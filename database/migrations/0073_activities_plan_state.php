<?php
/**
 * Migration 0073 — #0006 team-planning module: plan-state on tt_activities.
 *
 * Adds three columns to `tt_activities` (originally `tt_sessions`,
 * renamed in migration 0027):
 *   - plan_state VARCHAR(16) DEFAULT 'completed' — one of
 *     'draft', 'scheduled', 'in_progress', 'completed', 'cancelled'.
 *     Default 'completed' on existing rows preserves the historical
 *     log-only meaning. New rows from the planner UI start at
 *     'scheduled'; new rows from the existing logging flow keep the
 *     historical 'completed' default.
 *   - planned_at DATETIME — when the activity was scheduled in the
 *     planner. NULL on rows created via the legacy logging flow.
 *   - planned_by BIGINT UNSIGNED — the user who scheduled it. NULL
 *     for legacy log-only rows.
 *
 * Pre-existing `tt_principles` (migration 0015 — methodology framework)
 * already provides the principle-lookup the planning spec calls for —
 * the existing principles ARE the coaching-philosophy items the
 * planner will tag activities with. `tt_session_principles` already
 * exists as the link table. No new tables needed.
 *
 * Idempotent. SHOW COLUMNS guards.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0073_activities_plan_state';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_activities";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s", 'plan_state'
        ) );
        if ( $col !== 'plan_state' ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN plan_state VARCHAR(16) NOT NULL DEFAULT 'completed' AFTER notes" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_plan_state (plan_state)" );
        }

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s", 'planned_at'
        ) );
        if ( $col !== 'planned_at' ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN planned_at DATETIME DEFAULT NULL AFTER plan_state" );
        }

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s", 'planned_by'
        ) );
        if ( $col !== 'planned_by' ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN planned_by BIGINT UNSIGNED DEFAULT NULL AFTER planned_at" );
        }
    }
};
