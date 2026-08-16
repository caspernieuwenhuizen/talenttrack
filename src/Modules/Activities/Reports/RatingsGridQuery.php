<?php
namespace TT\Modules\Activities\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

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
     * @return array{
     *   activity: object|null,
     *   categories: list<array{id:int,label:string}>,
     *   players: list<object>,
     *   values: array<int, array<int, float>>,
     *   scale: array{min:float,max:float,step:float}
     * }
     */
    public static function forActivity( int $activity_id ): array {
        $empty = [
            'activity'   => null,
            'categories' => [],
            'players'    => [],
            'values'     => [],
            'scale'      => self::scale(),
        ];
        if ( $activity_id <= 0 ) return $empty;

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $activity = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id, title, session_date, activity_type_key, eval_type_id
               FROM {$p}tt_activities
              WHERE id = %d AND club_id = %d",
            $activity_id, $club_id
        ) );
        if ( ! $activity ) return $empty;

        return [
            'activity'   => $activity,
            'categories' => self::categoriesFor( $activity ),
            'players'    => self::players( (int) $activity->team_id ),
            'values'     => self::existingRatings( $activity_id ),
            'scale'      => self::scale(),
        ];
    }

    /**
     * Columns = the categories this activity's eval type declares
     * (`tt_eval_type_categories`). A type that declares none falls back to
     * every active category — better a full grid than an empty one; the
     * view says which case applies.
     *
     * @return list<array{id:int,label:string}>
     */
    private static function categoriesFor( object $activity ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $type_id = (int) ( $activity->eval_type_id ?? 0 );
        if ( $type_id <= 0 ) {
            $type_id = \TT\Modules\Wizards\Evaluation\EvaluationInserter::evalTypeIdForActivity( (int) $activity->id );
        }

        $rows = [];
        if ( $type_id > 0 ) {
            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT c.id, c.label
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
                "SELECT id, label FROM {$p}tt_eval_categories
                  WHERE is_active = 1
                  ORDER BY display_order ASC, label ASC"
            );
        }

        $out = [];
        foreach ( $rows as $r ) {
            $id = (int) ( $r->id ?? 0 );
            if ( $id <= 0 ) continue;
            $out[] = [ 'id' => $id, 'label' => (string) ( $r->label ?? '' ) ];
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
