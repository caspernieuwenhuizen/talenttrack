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
     * #1851 — the player's top active goals for a "Your focus" preview:
     * non-archived, not completed/cancelled, nearest due date first,
     * undated last. Used by the state-aware PDP surface and the
     * development home (#1850) so both show the same short focus list
     * without re-deriving the query in a view.
     *
     * @return array<int, object>
     */
    public function topActiveForPlayer( int $player_id, int $limit = 3 ): array {
        if ( $player_id <= 0 ) return [];
        $limit = max( 1, min( 20, $limit ) );

        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.*
               FROM {$p}tt_goals g
              WHERE g.player_id = %d
                AND g.archived_at IS NULL
                AND ( g.club_id = %d OR g.club_id IS NULL )
                AND ( g.status IS NULL OR g.status NOT IN ( 'completed', 'cancelled' ) )
              ORDER BY ( g.due_date IS NULL ), g.due_date ASC, g.id DESC
              LIMIT %d",
            $player_id, CurrentClub::id(), $limit
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
     * #1358 — every non-archived goal for the player-profile Goals
     * tab (completed/cancelled rows included — the tab shows the full
     * picture; only the KPI counts filter by status), ordered by
     * urgency: dated goals first by nearest due date, undated last by
     * recency.
     *
     * @return array<int, object>
     */
    public function listActiveByDueDateForPlayer( int $player_id, int $limit = 50 ): array {
        if ( $player_id <= 0 ) return [];
        $limit = max( 1, min( 100, $limit ) );

        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.*
               FROM {$p}tt_goals g
              WHERE g.player_id = %d AND g.archived_at IS NULL
                AND ( g.club_id = %d OR g.club_id IS NULL )
              ORDER BY g.due_date IS NULL, g.due_date ASC, g.created_at DESC
              LIMIT %d",
            $player_id, CurrentClub::id(), $limit
        ) );
        if ( ! is_array( $rows ) ) return [];
        foreach ( $rows as $row ) {
            self::hydrate( $row );
        }
        return $rows;
    }

    /**
     * #1358 — count of active (non-archived, not completed/cancelled)
     * goals, for the player-profile "Goals" KPI.
     */
    public function countActiveForPlayer( int $player_id ): int {
        if ( $player_id <= 0 ) return 0;

        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals
              WHERE player_id = %d AND archived_at IS NULL
                AND ( club_id = %d OR club_id IS NULL )
                AND ( status IS NULL OR status NOT IN ( 'completed', 'cancelled' ) )",
            $player_id, CurrentClub::id()
        ) );
    }

    /**
     * #1358 — count of active goals due within the next `$days` days,
     * for the KPI's "N due soon" hint.
     */
    public function countDueSoonForPlayer( int $player_id, int $days = 7 ): int {
        if ( $player_id <= 0 ) return 0;
        $days = max( 1, $days );

        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals
              WHERE player_id = %d AND archived_at IS NULL
                AND ( club_id = %d OR club_id IS NULL )
                AND due_date IS NOT NULL
                AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL %d DAY)
                AND ( status IS NULL OR status NOT IN ( 'completed', 'cancelled' ) )",
            $player_id, CurrentClub::id(), $days
        ) );
    }

    /**
     * #1385 — count of completed (non-archived) goals for a player, for
     * the `MyGoalsCompletedSeason` player KPI.
     */
    public function countCompletedForPlayer( int $player_id ): int {
        if ( $player_id <= 0 ) return 0;

        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals
              WHERE player_id = %d AND archived_at IS NULL
                AND ( club_id = %d OR club_id IS NULL )
                AND status = 'completed'",
            $player_id, CurrentClub::id()
        ) );
    }

    /**
     * #1385 — the player's next milestone: nearest-due active goal
     * (non-archived, not completed/cancelled, with a due date). Returns
     * null when the player has no dated active goal. Powers
     * `MyNextMilestone`.
     */
    public function nextDueActiveGoalForPlayer( int $player_id ): ?object {
        if ( $player_id <= 0 ) return null;

        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT g.*
               FROM {$p}tt_goals g
              WHERE g.player_id = %d AND g.archived_at IS NULL
                AND ( g.club_id = %d OR g.club_id IS NULL )
                AND g.due_date IS NOT NULL
                AND ( g.status IS NULL OR g.status NOT IN ( 'completed', 'cancelled' ) )
              ORDER BY g.due_date ASC
              LIMIT 1",
            $player_id, CurrentClub::id()
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
