<?php
namespace TT\Infrastructure\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * EvaluationsRepository — read-only repository for evaluation records.
 *
 * #920 — extracts the previously inline SQL from `FrontendMyEvaluationsView`
 * so the coach "My evaluations" list has a single SQL source-of-truth
 * + becomes consumable by a future non-WordPress front end via the
 * new `GET /evaluations/recent` endpoint without re-deriving the SQL.
 *
 * #806 — **worked example for the LookupTranslator-into-repository
 * pattern.** ~30 sites across the codebase echo raw `tt_lookups.name`
 * values directly without translating; the architectural fix is to
 * pre-localise lookup-backed fields at the repository boundary so
 * view code that does `echo $row->type_name_localised` gets the
 * right string by construction — bypass becomes impossible.
 *
 * Per-row shape: `id`, `eval_date`, `opponent`, `game_result`,
 * `type_name` (raw, untranslated — kept for back-compat with consumers
 * that need the canonical key), **`type_name_localised`** (the user-
 * facing string in the active locale via `LookupTranslator::name()`),
 * `first_name`, `last_name`.
 *
 * Pattern to mirror in the other 4 modules (Goals, Activities,
 * Players, Pdp) per #806's module-by-module rollout — each follow-up
 * ships as its own focused issue.
 */
class EvaluationsRepository {

    /**
     * Recent evaluations authored by a single coach.
     *
     * Filters: archived rows excluded; ordered newest-first. The trailing
     * window defaults to 30 days — wider than the KPI's strictly-this-week
     * cut so the surface shows context on quiet weeks.
     *
     * SaaS-readiness: filters by `CurrentClub::id()` so a future
     * multi-tenant install can't leak evaluations across academies even
     * if a coach_id collides.
     *
     * @return array<int, object>
     */
    public function recentForCoach( int $coach_user_id, int $days = 30 ): array {
        if ( $coach_user_id <= 0 ) return [];
        if ( $days <= 0 ) $days = 30;

        global $wpdb;
        $p       = $wpdb->prefix;
        $cutoff  = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $club_id = CurrentClub::id();

        // #806 — pull the full lookup row (not just lt.name) so
        // LookupTranslator can translate per-row below.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.eval_date, e.opponent, e.game_result,
                    lt.id   AS lookup_id,
                    lt.name AS type_name,
                    lt.lookup_type AS lookup_type,
                    pl.first_name, pl.last_name
               FROM {$p}tt_evaluations e
               LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id = lt.id
               LEFT JOIN {$p}tt_players pl ON e.player_id = pl.id
              WHERE e.coach_id = %d
                AND e.archived_at IS NULL
                AND e.eval_date >= %s
                AND ( e.club_id = %d OR e.club_id IS NULL )
              ORDER BY e.eval_date DESC",
            $coach_user_id,
            $cutoff,
            $club_id
        ) );

        if ( ! is_array( $rows ) ) return [];

        // #806 — hydrate every row with `type_name_localised` BEFORE
        // handing it back. The view layer just echoes the localised
        // field; raw `type_name` stays available for back-compat
        // (consumers that need the canonical key for grouping etc.).
        foreach ( $rows as $row ) {
            if ( ! isset( $row->lookup_id ) || $row->lookup_id === null ) {
                $row->type_name_localised = '';
                continue;
            }
            $lookup_stub = (object) [
                'id'          => (int) $row->lookup_id,
                'name'        => (string) ( $row->type_name ?? '' ),
                'lookup_type' => (string) ( $row->lookup_type ?? '' ),
            ];
            $row->type_name_localised = LookupTranslator::name( $lookup_stub );
        }

        return $rows;
    }

    /**
     * #1358 — recent evaluations for one player, with the per-
     * evaluation mean rating surfaced inline so the player-profile
     * Evaluations tab can render its rating chip without a second
     * round-trip per row.
     *
     * Tab list and PlayerFileCounts must agree on scope —
     * (player_id, club_id, archived_at IS NULL) — otherwise the tab
     * badge and the list can fall out of sync.
     *
     * @return array<int, object> rows: id, eval_date, eval_type_id, avg_rating.
     */
    public function listRecentForPlayer( int $player_id, int $limit = 50 ): array {
        if ( $player_id <= 0 ) return [];
        $limit = max( 1, min( 100, $limit ) );

        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.eval_date, e.eval_type_id,
                    (SELECT AVG(r.rating) FROM {$p}tt_eval_ratings r
                      WHERE r.evaluation_id = e.id AND r.club_id = e.club_id) AS avg_rating
               FROM {$p}tt_evaluations e
              WHERE e.player_id = %d AND e.club_id = %d AND e.archived_at IS NULL
              ORDER BY e.eval_date DESC LIMIT %d",
            $player_id, CurrentClub::id(), $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #1358 — whole-history rating summary for the player-profile
     * "Avg rating" KPI: mean of every rating row across the player's
     * non-archived evaluations plus the distinct evaluation count.
     * The direct-mean shape mirrors `MiniPlayerListWidget` (the
     * dashboard tile's per-evaluation average), aggregated across the
     * player's whole history.
     *
     * @return object|null `{avg_r: float|null, n: int}`; null on query failure.
     */
    public function ratingSummaryForPlayer( int $player_id ): ?object {
        if ( $player_id <= 0 ) return null;

        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT AVG(r.rating) AS avg_r, COUNT(DISTINCT e.id) AS n
               FROM {$p}tt_evaluations e
               JOIN {$p}tt_eval_ratings r ON r.evaluation_id = e.id
              WHERE e.player_id = %d
                AND e.archived_at IS NULL
                AND ( e.club_id = %d OR e.club_id IS NULL )",
            $player_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * #1358 — mean rating of the player's most recent evaluation, for
     * the KPI's trend arrow (compared against the rolling mean from
     * `ratingSummaryForPlayer`). Null when the player has no rated
     * evaluations.
     */
    public function lastEvaluationMeanForPlayer( int $player_id ): ?float {
        if ( $player_id <= 0 ) return null;

        global $wpdb;
        $p = $wpdb->prefix;

        $last = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(r.rating)
               FROM {$p}tt_evaluations e
               JOIN {$p}tt_eval_ratings r ON r.evaluation_id = e.id
              WHERE e.player_id = %d AND e.archived_at IS NULL
                AND ( e.club_id = %d OR e.club_id IS NULL )
              GROUP BY e.id
              ORDER BY e.eval_date DESC LIMIT 1",
            $player_id, CurrentClub::id()
        ) );
        return $last !== null ? (float) $last : null;
    }
}
