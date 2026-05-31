<?php
namespace TT\Infrastructure\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * EvaluationsRepository — read-only repository for evaluation records.
 *
 * #920 — extracts the previously inline SQL from `FrontendMyEvaluationsView`
 * so the coach "My evaluations" list:
 *
 *   1. Has a single SQL source-of-truth that the view + REST endpoint
 *      both consume.
 *   2. Passes the CLAUDE.md §4 smell test ("deleted every file under
 *      src/Shared/Frontend/, could the REST API still return correct
 *      data?").
 *   3. Becomes consumable by a future non-WordPress front end via the
 *      new `GET /evaluations/recent` endpoint without re-deriving the
 *      SQL.
 *
 * Per-row shape mirrors what the view template expects: `id`, `eval_date`,
 * `opponent`, `game_result`, `type_name` (joined from `tt_lookups`),
 * `first_name` + `last_name` (joined from `tt_players`).
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

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.eval_date, e.opponent, e.game_result,
                    lt.name AS type_name,
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

        return is_array( $rows ) ? $rows : [];
    }
}
