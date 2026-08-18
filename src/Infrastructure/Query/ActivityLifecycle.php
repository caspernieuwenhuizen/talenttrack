<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ActivityLifecycle (#2521) — the one SQL predicate that decides whether an
 * activity has actually happened.
 *
 * `tt_activities` carries two lifecycle columns:
 *
 *   - `activity_status_key` (planned / completed / cancelled, migration 0040)
 *     — the axis the UI shows, the coach sets, and the wizards write.
 *   - `plan_state` (draft / scheduled / in_progress / completed / cancelled,
 *     migration 0073) — the planner's workflow axis.
 *
 * They are different axes and they disagree in practice: `plan_state` was
 * added with `DEFAULT 'completed'` to preserve the log-only meaning of
 * pre-planner rows, and only the team planner ever sets it explicitly. Every
 * other create path (Spond import, flat form, activity wizard) leaves the
 * default in place, so an activity that reads "Planned" on screen carried
 * `plan_state = 'completed'` in the database — and every report that gated on
 * `plan_state` counted sessions that had not happened yet.
 *
 * So reporting reads `activity_status_key`: the status the user set is the
 * status the numbers honour. `plan_state` stays untouched for the planner,
 * the evaluation wizard's activity picker and the entry grids, which use it
 * as a genuine workflow signal.
 *
 * These helpers return literal SQL — no user input reaches them, so they need
 * no preparation. The alias is the caller's table alias for `tt_activities`.
 */
final class ActivityLifecycle {

    /**
     * "This activity has happened." Use for any query that reports on what
     * took place: attendance, minutes, KPI rollups, player-file counts.
     */
    public static function completedClause( string $alias = 'a' ): string {
        $col = self::column( $alias );
        return "LOWER({$col}) = 'completed'";
    }

    /**
     * "This activity was not called off." Use for queries that legitimately
     * span planned and completed rows (entry grids, planner surfaces) and
     * only need to drop cancellations.
     */
    public static function notCancelledClause( string $alias = 'a' ): string {
        $col = self::column( $alias );
        return "LOWER(COALESCE({$col}, '')) <> 'cancelled'";
    }

    /**
     * Qualify the status column with the caller's alias. An empty alias means
     * the query has a single unaliased table.
     */
    private static function column( string $alias ): string {
        $alias = preg_replace( '/[^A-Za-z0-9_]/', '', $alias );
        return $alias !== '' ? "{$alias}.activity_status_key" : 'activity_status_key';
    }
}
