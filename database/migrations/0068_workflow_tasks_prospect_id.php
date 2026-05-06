<?php
/**
 * Migration 0068 — add `prospect_id` entity-link column to
 * `tt_workflow_tasks` for the #0081 onboarding-pipeline chain.
 *
 * The Workflow engine carries entity links as nullable FK columns
 * (`player_id`, `team_id`, `activity_id`, `evaluation_id`, `goal_id`,
 * `trial_case_id`, `parent_task_id`). Child 2 of #0081 introduces five
 * new templates that thread a `prospect_id` through the chain — same
 * shape as the existing FKs, no JSON in `extras` for the link itself.
 *
 * Adding the column unblocks two child-1 follow-ups:
 *   - Retention cron's "active-chain protection" gains a real LEFT
 *     JOIN onto workflow tasks via this column (was created_at-only
 *     in v3.95.0; query gets tightened in this PR to use the new
 *     column).
 *   - Pipeline widget (child 3) joins prospects + their workflow
 *     tasks via this column for stage-bucketing.
 *
 * Idempotent. SHOW COLUMNS guard so re-running on a backfilled install
 * is a no-op.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0068_workflow_tasks_prospect_id';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_workflow_tasks";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s", 'prospect_id'
        ) );
        if ( $col === 'prospect_id' ) {
            return;
        }

        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN prospect_id BIGINT UNSIGNED DEFAULT NULL AFTER trial_case_id" );
        $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_prospect (prospect_id)" );
    }
};
