<?php
/**
 * Migration 0127 — VCT workload-aggregation cron trigger (#912 VCT-7 /
 * partial epic #905).
 *
 * Inserts a row into `tt_workflow_triggers` so the Workflow module's
 * `CronDispatcher` fires `VctWorkloadAggregationTaskTemplate::dispatch()`
 * once per day at 02:00 UTC. Idempotent — checks for an existing row
 * keyed on (club_id, template_key, trigger_type) before inserting so
 * re-runs don't duplicate the schedule.
 *
 * Per spec § Decisions log #1: no `wp_schedule_event` direct
 * registration. The Workflow module is the SaaS-port chokepoint for
 * scheduler abstraction — one place that swaps out when VCT migrates
 * to the SaaS frontend, not N per-module cron registrations.
 *
 * Operators can disable the schedule via the workflow config UI
 * (toggles `enabled = 0`) or override the cron expression. Re-running
 * this migration leaves operator-edited rows intact.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0127_vct_workflow_trigger';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_workflow_triggers';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $club_id      = 1;
        $template_key = 'vct_workload_aggregation';
        $trigger_type = 'cron';

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table}
              WHERE club_id = %d AND template_key = %s AND trigger_type = %s
              LIMIT 1",
            $club_id, $template_key, $trigger_type
        ) );
        if ( $existing > 0 ) return;

        $wpdb->insert( $table, [
            'club_id'         => $club_id,
            'template_key'    => $template_key,
            'trigger_type'    => $trigger_type,
            'cron_expression' => '0 2 * * *',
            'event_hook'      => null,
            'enabled'         => 1,
            'config_json'     => null,
        ] );
    }
};
