<?php
namespace TT\Infrastructure\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * PlayerEvaluationsReader (#3478) — a player's evaluations, shaped for the
 * people the player-facing surfaces serve: the player and their guardians.
 *
 * "My evaluations" used to run its own query in the view, with no window at
 * all, and then render every evaluation's full subcategory breakdown into the
 * page behind a toggle. For a player with 208 evaluations that was 2.5 MB of
 * HTML and 4,368 hidden rating rows in first paint — plus two further queries
 * per evaluation while building it. The reader opens at most one of those
 * breakdowns.
 *
 * Decided 2026-09-16: **current season by default, earlier seasons on demand,
 * detail fetched when a row is opened.** That is what this class provides, and
 * the view and `GET /players/{id}/evaluations` both read it, so a non-WordPress
 * front end gets the same cut (§4).
 *
 * ## Player-facing shape
 *
 * Rows carry what the player surface shows — date, type, coach, match, the
 * coach's `player_feedback` — and never the staff-only `notes` column. That is
 * a deliberate difference from `GET /evaluations/{id}`, which is staff-gated
 * and returns the full record.
 *
 * Authorization is the caller's job (the view is reached through the Me-view
 * gate; the REST route checks `canViewPlayer` + the #1867 section preference).
 */
final class PlayerEvaluationsReader {

    public const SCOPE_CURRENT = 'current';
    public const SCOPE_ALL     = 'all';

    /**
     * The date window for a scope. `current` narrows to the current season;
     * with no current season set there is nothing to narrow to, so it widens
     * to everything rather than showing an empty page.
     *
     * @return array{from: ?string, to: ?string, season_name: ?string}
     */
    public function window( string $scope ): array {
        if ( $scope !== self::SCOPE_CURRENT ) {
            return [ 'from' => null, 'to' => null, 'season_name' => null ];
        }
        $season = ( new SeasonsRepository() )->current();
        if ( ! $season || empty( $season->start_date ) || empty( $season->end_date ) ) {
            return [ 'from' => null, 'to' => null, 'season_name' => null ];
        }
        return [
            'from'        => (string) $season->start_date,
            'to'          => (string) $season->end_date,
            'season_name' => (string) ( $season->name ?? '' ),
        ];
    }

    /**
     * Evaluations inside the scope, newest first, player-facing fields only.
     *
     * Rows carry both `type_name` (the canonical `tt_lookups` value, kept for
     * consumers that group on the key) and `type_name_localised` (#806 — the
     * user-facing string in the active locale). Localising here rather than in
     * the view is what keeps the rendered page and `GET /players/{id}/evaluations`
     * saying the same word.
     *
     * @return list<object>
     */
    public function listForPlayer( int $player_id, string $scope = self::SCOPE_CURRENT ): array {
        if ( $player_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $window = $this->window( $scope );
        $where  = 'e.player_id = %d AND e.archived_at IS NULL AND ( e.club_id = %d OR e.club_id IS NULL )';
        $params = [ $player_id, CurrentClub::id() ];
        if ( $window['from'] !== null && $window['to'] !== null ) {
            $where   .= ' AND e.eval_date BETWEEN %s AND %s';
            $params[] = $window['from'];
            $params[] = $window['to'];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from literals only.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.eval_date, e.opponent, e.game_result, e.player_feedback,
                    lt.id AS lookup_id, lt.name AS type_name, lt.lookup_type AS lookup_type,
                    u.display_name AS coach_name
               FROM {$p}tt_evaluations e
               LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id = lt.id
               LEFT JOIN {$wpdb->users} u ON e.coach_id = u.ID
              WHERE {$where}
              ORDER BY e.eval_date DESC, e.id DESC",
            ...$params
        ) );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $fields = (array) $row;
            $fields['type_name_localised'] = $this->localisedType( $fields );
            // The join columns are plumbing for the line above, not part of
            // the player-facing shape.
            unset( $fields['lookup_id'], $fields['lookup_type'] );
            $out[] = (object) $fields;
        }
        return $out;
    }

    /**
     * The evaluation type in the active locale, or '' when the row has no
     * type lookup at all. Mirrors `EvaluationsRepository::recentForCoach()`
     * so the coach list and the player list translate identically.
     *
     * @param array<string, mixed> $fields One row of the query above.
     */
    private function localisedType( array $fields ): string {
        $lookup_id = (int) ( $fields['lookup_id'] ?? 0 );
        if ( $lookup_id <= 0 ) return '';

        return LookupTranslator::name( (object) [
            'id'          => $lookup_id,
            'name'        => (string) ( $fields['type_name'] ?? '' ),
            'lookup_type' => (string) ( $fields['lookup_type'] ?? '' ),
        ] );
    }

    /** How many evaluations fall outside the scope — what "earlier seasons" would add. */
    public function countOutside( int $player_id, string $scope = self::SCOPE_CURRENT ): int {
        if ( $player_id <= 0 || $scope !== self::SCOPE_CURRENT ) return 0;
        $window = $this->window( $scope );
        if ( $window['from'] === null ) return 0;

        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations e
              WHERE e.player_id = %d AND e.archived_at IS NULL
                AND ( e.club_id = %d OR e.club_id IS NULL )
                AND e.eval_date NOT BETWEEN %s AND %s",
            $player_id, CurrentClub::id(), $window['from'], $window['to']
        ) );
    }

    /**
     * Main-category pills for one evaluation — the row summary, cheap enough
     * to render for every row in the window.
     *
     * @return list<array{label: string, rating: float}>
     */
    public function mainPills( int $eval_id ): array {
        $out = [];
        foreach ( ( new EvalRatingsRepository() )->effectiveMainRatingsFor( $eval_id ) as $main_id => $row ) {
            if ( $row['value'] === null ) continue;
            $out[] = [
                'label'  => EvalCategoriesRepository::displayLabel( (string) $row['label'], (int) $main_id ),
                'rating' => (float) $row['value'],
            ];
        }
        return $out;
    }

    /**
     * The subcategory breakdown for one evaluation, grouped under its main
     * categories — what used to be rendered hidden for every row, now read
     * when a row is opened.
     *
     * Returns null when the evaluation does not exist, is archived, or does
     * not belong to `$player_id`: a caller authorised for one player must not
     * be able to read another player's breakdown by guessing an id.
     *
     * Each group and sub carries its category note (#3949), '' for none; a
     * sub that was not rated but has a note carries a null rating.
     *
     * @return list<array{label: string, note: string, subs: list<array{label: string, rating: float|null, note: string}>}>|null
     */
    public function detail( int $player_id, int $eval_id ): ?array {
        if ( $player_id <= 0 || $eval_id <= 0 ) return null;

        $full = QueryHelpers::get_evaluation( $eval_id );
        if ( ! $full
            || (int) ( $full->player_id ?? 0 ) !== $player_id
            || ! empty( $full->archived_at ) ) {
            return null;
        }

        $main_labels = [];
        foreach ( ( new EvalRatingsRepository() )->effectiveMainRatingsFor( $eval_id ) as $main_id => $row ) {
            $main_labels[ (int) $main_id ] = EvalCategoriesRepository::displayLabel( (string) $row['label'], (int) $main_id );
        }

        // #3949 — the category notes ride along. This detail is only served
        // to a reader the route already let see the evaluation.
        $notes = ( new EvalCategoryNotesRepository() )->forEvaluation( $eval_id );

        $groups = [];
        $seen   = [];
        foreach ( (array) ( $full->ratings ?? [] ) as $r ) {
            if ( ! is_object( $r ) ) continue;
            $parent = (int) ( $r->category_parent_id ?? 0 );
            if ( $parent <= 0 ) continue;
            if ( ! isset( $groups[ $parent ] ) ) {
                $groups[ $parent ] = [ 'label' => $main_labels[ $parent ] ?? '', 'note' => (string) ( $notes[ $parent ] ?? '' ), 'subs' => [] ];
            }
            $cid = (int) ( $r->category_id ?? 0 );
            $seen[ $cid ] = true;
            $groups[ $parent ]['subs'][] = [
                'label'  => EvalCategoriesRepository::displayLabel( (string) ( $r->category_name ?? '' ), $cid ),
                'rating' => (float) ( $r->rating ?? 0 ),
                'note'   => (string) ( $notes[ $cid ] ?? '' ),
            ];
        }

        // A note on a category with no sub rating of its own: a main
        // category's note, or a skill left unscored.
        $cat_repo = new EvalCategoriesRepository();
        foreach ( $notes as $cid => $note ) {
            if ( isset( $seen[ $cid ] ) ) continue;
            $cat = $cat_repo->get( (int) $cid );
            if ( $cat === null ) continue;
            $parent = (int) ( $cat->parent_id ?? 0 );
            if ( $parent <= 0 ) {
                if ( ! isset( $groups[ $cid ] ) ) {
                    $groups[ $cid ] = [
                        'label' => $main_labels[ $cid ] ?? EvalCategoriesRepository::displayLabel( (string) ( $cat->label ?? '' ), (int) $cid ),
                        'note'  => $note,
                        'subs'  => [],
                    ];
                }
                continue;
            }
            if ( ! isset( $groups[ $parent ] ) ) {
                $parent_row = $cat_repo->get( $parent );
                $groups[ $parent ] = [
                    'label' => $main_labels[ $parent ] ?? ( $parent_row !== null ? EvalCategoriesRepository::displayLabel( (string) ( $parent_row->label ?? '' ), $parent ) : '' ),
                    'note'  => (string) ( $notes[ $parent ] ?? '' ),
                    'subs'  => [],
                ];
            }
            $groups[ $parent ]['subs'][] = [
                'label'  => EvalCategoriesRepository::displayLabel( (string) ( $cat->label ?? '' ), (int) $cid ),
                'rating' => null,
                'note'   => $note,
            ];
        }
        return array_values( $groups );
    }

    /** Whether an evaluation has any breakdown to open — decides if the toggle renders. */
    public function hasDetail( int $eval_id ): bool {
        global $wpdb;
        // #3949 — a category note is something to open as well.
        if ( ( new EvalCategoryNotesRepository() )->forEvaluation( $eval_id ) ) return true;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}tt_eval_ratings r
               JOIN {$wpdb->prefix}tt_eval_categories c ON c.id = r.category_id AND c.club_id = r.club_id
              WHERE r.evaluation_id = %d AND c.parent_id IS NOT NULL
              LIMIT 1",
            $eval_id
        ) ) === 1;
    }
}
