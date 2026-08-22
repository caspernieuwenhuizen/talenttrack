<?php
/**
 * Migration 0223 — the nightly trigger row for the player training
 * exposure rebuild (#2500, epic #2493).
 *
 * `PlayerExposureAggregationTaskTemplate` registers the template; this
 * row is what makes the workflow engine actually fire it. Without it the
 * template exists and never runs, and the player file would show whatever
 * the last on-completion incremental left behind.
 *
 * 03:00, an hour after the VCT workload aggregation (0127) so the two
 * heavy nightly passes do not contend for the same connection.
 *
 * Idempotent: returns early when a row for this template already exists,
 * so a re-run cannot double-schedule the job.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0223_training_exposure_workflow_trigger';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_workflow_triggers';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $club_id      = 1;
        $template_key = 'training_exposure_aggregation';
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
            'cron_expression' => '0 3 * * *',
            'event_hook'      => null,
            'enabled'         => 1,
            'config_json'     => null,
        ] );
    }
};
