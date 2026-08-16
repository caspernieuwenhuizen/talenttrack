<?php
namespace TT\Modules\Activities\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * RatingsGridQuery (#2414, epic #2381) — the read model behind the
 * ratings-entry grid: one activity, the team's active players down the
 * rows, that activity's evaluation categories across the columns.
 *
 * Why this axis: a rating is N category scores per player per activity, so
 * a players × activities grid could only ever show one collapsed number
 * per cell. Pivoting on categories for ONE activity keeps every cell a
 * single, directly-typed `tt_eval_ratings.rating` — full fidelity, nothing
 * derived, no popover. The weighted overall stays derived at read time
 * exactly as it is elsewhere; this grid never writes one.
 *
 * Lives in the domain layer, not the view: the REST endpoint and the
 * rendered grid must agree on which columns exist and what is already
 * recorded (CLAUDE.md §4).
 */
final class RatingsGridQuery {

    /**
     * `categories` is the flat, left-to-right column order — the bulk
     * endpoint validates against it and the view walks it for cells.
     * `groups` is the same set arranged as the two-level tree the header
     * renders (#2432): one entry per main category, carrying its own
     * rateable column when the eval type declares the main itself, plus
     * whichever of its sub-categories are declared.
     *
     * @return array{
     *   activity: object|null,
     *   categories: list<array{id:int,label:string,parent_id:int|null}>,
     *   groups: list<array{id:int,label:string,own:array{id:int,label:string}|null,subs:list<array{id:int,label:string}>,expanded:bool}>,
     *   players: list<object>,
     *   values: array<int, array<int, float>>,
     *   scale: array{min:float,max:float,step:float}
     * }
     */
    public static function forActivity( int $activity_id ): array {
        $empty = [
            'activity'   => null,
            'categories' => [],
            'groups'     => [],
            'players'    => [],
            'values'     => [],
            'scale'      => self::scale(),
        ];
        if ( $activity_id <= 0 ) return $empty;

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        // No eval_type_id on tt_activities — the evaluation type is derived
        // from activity_type_key (see EvaluationInserter::evalTypeIdForActivity).
        $activity = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id, title, session_date, activity_type_key
               FROM {$p}tt_activities
              WHERE id = %d AND club_id = %d",
            $activity_id, $club_id
        ) );
        if ( ! $activity ) return $empty;

        $values = self::existingRatings( $activity_id );
        $groups = self::groupsFor( self::categoriesFor( $activity ), $values );

        return [
            'activity'   => $activity,
            // Flattened in group order, so column order and header order are
            // the same walk — the view and the JS index into one sequence.
            'categories' => self::flatten( $groups ),
            'groups'     => $groups,
            'players'    => self::players( (int) $activity->team_id ),
            'values'     => $values,
            'scale'      => self::scale(),
        ];
    }

    /**
     * Arrange the declared categories into their two-level tree.
     *
     * `tt_eval_categories` is main (`parent_id IS NULL`) plus sub; the flat
     * `display_order` sort does not keep a sub next to its own parent, so
     * grouping here is also what stops the columns interleaving.
     *
     * Three shapes fall out of what the eval type declares, and all three
     * are real:
     *   - main + subs → the main keeps its own column AND heads the group,
     *     which is the same Basic/Detailed split the evaluation form offers.
     *   - main alone  → one column, no sub tier, no expand affordance.
     *   - subs alone  → a group header for context, with no rateable column
     *     of its own. The parent label is fetched even though the parent is
     *     not a column, otherwise the subs would have nothing to sit under.
     *
     * @param list<array{id:int,label:string,parent_id:int|null,order:int}> $flat
     * @param array<int, array<int, float>>                                 $values
     * @return list<array{id:int,label:string,own:array{id:int,label:string}|null,subs:list<array{id:int,label:string}>,expanded:bool}>
     */
    private static function groupsFor( array $flat, array $values ): array {
        if ( ! $flat ) return [];

        $declared = [];
        foreach ( $flat as $row ) $declared[ $row['id'] ] = $row;

        // Every main that has to appear, whether it is itself rateable or
        // only exists to head a group of declared subs.
        $missing_parents = [];
        foreach ( $flat as $row ) {
            $pid = $row['parent_id'];
            if ( $pid !== null && ! isset( $declared[ $pid ] ) ) $missing_parents[ $pid ] = true;
        }
        $parent_meta = self::parentMeta( array_keys( $missing_parents ) );

        $groups = [];
        foreach ( $flat as $row ) {
            $pid = $row['parent_id'];

            if ( $pid === null ) {
                $groups[ $row['id'] ] ??= self::emptyGroup( $row['id'], $row['label'], $row['order'] );
                $groups[ $row['id'] ]['label'] = $row['label'];
                $groups[ $row['id'] ]['order'] = $row['order'];
                $groups[ $row['id'] ]['own']   = [ 'id' => $row['id'], 'label' => $row['label'] ];
                continue;
            }

            $meta = $parent_meta[ $pid ] ?? null;
            $groups[ $pid ] ??= self::emptyGroup(
                $pid,
                $meta['label'] ?? '',
                $meta['order'] ?? $row['order']
            );
            $groups[ $pid ]['subs'][] = [ 'id' => $row['id'], 'label' => $row['label'] ];
        }

        // Groups follow the main's own display_order; subs keep the order
        // they were selected in, which is already display_order.
        uasort( $groups, static fn( $a, $b ) => $a['order'] <=> $b['order'] );

        $out = [];
        foreach ( $groups as $g ) {
            // Auto-expand when a sub already holds a score for anyone in
            // this activity: a coach reopening a detailed rating must see
            // what they entered, not a collapsed grid hiding it (#2432 D2).
            $g['expanded'] = $g['subs'] ? self::anySubRated( $g['subs'], $values ) : false;
            unset( $g['order'] );
            $out[] = $g;
        }
        return $out;
    }

    /** @return array{id:int,label:string,own:array{id:int,label:string}|null,subs:list<array{id:int,label:string}>,expanded:bool,order:int} */
    private static function emptyGroup( int $id, string $label, int $order ): array {
        return [ 'id' => $id, 'label' => $label, 'own' => null, 'subs' => [], 'expanded' => false, 'order' => $order ];
    }

    /**
     * Label + display_order for mains that head a group without being a
     * rateable column themselves.
     *
     * @param list<int> $ids
     * @return array<int, array{label:string,order:int}>
     */
    private static function parentMeta( array $ids ): array {
        if ( ! $ids ) return [];
        global $wpdb;
        $p            = $wpdb->prefix;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows         = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, label, display_order FROM {$p}tt_eval_categories WHERE id IN ({$placeholders})",
            ...$ids
        ) );

        $out = [];
        foreach ( $rows as $r ) {
            $id = (int) ( $r->id ?? 0 );
            if ( $id <= 0 ) continue;
            $out[ $id ] = [
                'label' => EvalCategoriesRepository::displayLabel( (string) ( $r->label ?? '' ), $id ),
                'order' => (int) ( $r->display_order ?? 0 ),
            ];
        }
        return $out;
    }

    /**
     * @param list<array{id:int,label:string}> $subs
     * @param array<int, array<int, float>>    $values
     */
    private static function anySubRated( array $subs, array $values ): bool {
        foreach ( $values as $by_category ) {
            foreach ( $subs as $sub ) {
                if ( isset( $by_category[ $sub['id'] ] ) ) return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{id:int,label:string,own:array{id:int,label:string}|null,subs:list<array{id:int,label:string}>,expanded:bool}> $groups
     * @return list<array{id:int,label:string,parent_id:int|null}>
     */
    private static function flatten( array $groups ): array {
        $out = [];
        foreach ( $groups as $g ) {
            if ( $g['own'] !== null ) {
                $out[] = [ 'id' => $g['own']['id'], 'label' => $g['own']['label'], 'parent_id' => null ];
            }
            foreach ( $g['subs'] as $sub ) {
                $out[] = [ 'id' => $sub['id'], 'label' => $sub['label'], 'parent_id' => $g['id'] ];
            }
        }
        return $out;
    }

    /**
     * Columns = the categories this activity's eval type declares
     * (`tt_eval_type_categories`). A type that declares none falls back to
     * every active category — better a full grid than an empty one; the
     * view says which case applies.
     *
     * Returns the declared set with its tier intact but still unordered as
     * a tree; `groupsFor()` does the arranging.
     *
     * @return list<array{id:int,label:string,parent_id:int|null,order:int}>
     */
    private static function categoriesFor( object $activity ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        // The activity carries an activity_type_key; the matching eval_type
        // lookup id is resolved by the same helper the wizard's writer uses,
        // so grid columns and wizard cards agree on the type.
        $type_id = \TT\Modules\Wizards\Evaluation\EvaluationInserter::evalTypeIdForActivity( (int) $activity->id );

        $rows = [];
        if ( $type_id > 0 ) {
            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT c.id, c.label, c.parent_id, c.display_order
                   FROM {$p}tt_eval_type_categories tc
                   INNER JOIN {$p}tt_eval_categories c ON c.id = tc.eval_category_id
                  WHERE tc.eval_type_id = %d
                    AND tc.club_id = %d
                    AND c.is_active = 1
                  ORDER BY c.display_order ASC, c.label ASC",
                $type_id, $club_id
            ) );
        }

        if ( ! $rows ) {
            $rows = (array) $wpdb->get_results(
                "SELECT id, label, parent_id, display_order FROM {$p}tt_eval_categories
                  WHERE is_active = 1
                  ORDER BY display_order ASC, label ASC"
            );
        }

        // Labels are resolved for display here rather than in the view, so
        // the rendered grid and the bulk endpoint — which reads this same
        // projection — can never disagree about what a column is called.
        // The stored label stays canonical: nothing translated is ever
        // written back, and the bulk writer keys on `id` alone.
        $out = [];
        foreach ( $rows as $r ) {
            $id = (int) ( $r->id ?? 0 );
            if ( $id <= 0 ) continue;
            $parent = isset( $r->parent_id ) && $r->parent_id !== null ? (int) $r->parent_id : null;
            $out[] = [
                'id'        => $id,
                'label'     => EvalCategoriesRepository::displayLabel( (string) ( $r->label ?? '' ), $id ),
                'parent_id' => $parent,
                'order'     => (int) ( $r->display_order ?? 0 ),
            ];
        }
        return $out;
    }

    /** Rows = the team's ACTIVE players, the roster definition epic #2381 settled on. */
    private static function players( int $team_id ): array {
        if ( $team_id <= 0 ) return [];
        global $wpdb;
        $p         = $wpdb->prefix;
        $lifecycle = \TT\Infrastructure\Archive\ArchiveRepository::filterClause( 'active', 'pl' );
        $rows      = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id, pl.first_name, pl.last_name, pl.jersey_number
               FROM {$p}tt_players pl
              WHERE pl.team_id = %d
                AND pl.club_id = %d
                AND pl.status = 'active'
                AND {$lifecycle}
              ORDER BY pl.last_name ASC, pl.first_name ASC",
            $team_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Already-recorded scores for this activity: player_id => category_id
     * => rating. Absent means not rated — never zero.
     *
     * @return array<int, array<int, float>>
     */
    private static function existingRatings( int $activity_id ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.player_id, r.category_id, r.rating
               FROM {$p}tt_evaluations e
               INNER JOIN {$p}tt_eval_ratings r ON r.evaluation_id = e.id
              WHERE e.activity_id = %d AND e.club_id = %d",
            $activity_id, CurrentClub::id()
        ) );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $pid = (int) ( $r->player_id ?? 0 );
            $cid = (int) ( $r->category_id ?? 0 );
            if ( $pid <= 0 || $cid <= 0 ) continue;
            $out[ $pid ][ $cid ] = (float) $r->rating;
        }
        return $out;
    }

    /** @return array{min:float,max:float,step:float} */
    public static function scale(): array {
        $get = static fn( string $k, string $d ): float =>
            (float) \TT\Infrastructure\Query\QueryHelpers::get_config( $k, $d );

        $min  = $get( 'rating_min', '5' );
        $max  = $get( 'rating_max', '10' );
        $step = $get( 'rating_step', '0.5' );

        if ( $max <= $min ) { $min = 5.0; $max = 10.0; }
        if ( $step <= 0 )   { $step = 0.5; }

        return [ 'min' => $min, 'max' => $max, 'step' => $step ];
    }
}
