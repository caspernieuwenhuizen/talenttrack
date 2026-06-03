<?php
namespace TT\Infrastructure\Goals;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * GoalsRepository — read-only repository for goal records.
 *
 * #1077 — module-by-module rollout of #806's architectural sweep.
 * Worked example: `Infrastructure\Evaluations\EvaluationsRepository`
 * (v4.17.2 / #1081). The goals slice differs from Evaluations in one
 * mechanical detail: goal status + priority are stored as code
 * strings on `tt_goals` (no FK into `tt_lookups`), so the per-row
 * hydration calls `LabelTranslator::goalStatus()` /
 * `LabelTranslator::goalPriority()` instead of `LookupTranslator::name()`.
 * The contract is the same — view code echoes `$row->status_localised`
 * and `$row->priority_localised`; bypass becomes structurally
 * impossible.
 *
 * Per-row shape (additive to whatever `SELECT *` returned):
 *
 *   `status`                  raw code (back-compat — KPI groupings)
 *   `status_localised`        user-facing label in active locale
 *   `priority`                raw code (back-compat)
 *   `priority_localised`      user-facing label in active locale
 *
 * Mirror pattern when shipping #1078 (Activities), #1079 (Players),
 * #1080 (Pdp).
 */
class GoalsRepository {

    /**
     * Active goals for a player, newest-first. Used by the player's
     * "My goals" surface.
     *
     * @return array<int, object>
     */
    public function listForPlayer( int $player_id ): array {
        if ( $player_id <= 0 ) return [];

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.*
               FROM {$p}tt_goals g
              WHERE g.player_id = %d
                AND g.archived_at IS NULL
                AND ( g.club_id = %d OR g.club_id IS NULL )
              ORDER BY g.created_at DESC",
            $player_id,
            $club_id
        ) );

        if ( ! is_array( $rows ) ) return [];

        foreach ( $rows as $row ) {
            self::hydrate( $row );
        }
        return $rows;
    }

    /**
     * Single goal scoped to a player. Returns null if the goal
     * doesn't exist, doesn't belong to the player, or is archived.
     *
     * Used by the player's "My goals → detail" surface so a player
     * can't drill into a goal that belongs to someone else by
     * tweaking the URL.
     */
    public function findForPlayer( int $goal_id, int $player_id ): ?object {
        if ( $goal_id <= 0 || $player_id <= 0 ) return null;

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT g.*
               FROM {$p}tt_goals g
              WHERE g.id = %d
                AND g.player_id = %d
                AND g.archived_at IS NULL
                AND ( g.club_id = %d OR g.club_id IS NULL )
              LIMIT 1",
            $goal_id,
            $player_id,
            $club_id
        ) );

        if ( ! $row ) return null;
        self::hydrate( $row );
        return $row;
    }

    /**
     * Decorate a `tt_goals` row in place with `status_localised` and
     * `priority_localised`. Raw fields stay for back-compat — KPI
     * aggregations + filter dropdowns key off the canonical codes.
     */
    private static function hydrate( object $row ): void {
        $row->status_localised   = LabelTranslator::goalStatus( (string) ( $row->status ?? '' ) );
        $row->priority_localised = LabelTranslator::goalPriority( (string) ( $row->priority ?? '' ) );
    }
}
