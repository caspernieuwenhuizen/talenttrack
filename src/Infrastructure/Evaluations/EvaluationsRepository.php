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
}
