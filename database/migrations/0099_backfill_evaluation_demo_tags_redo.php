<?php
/**
 * Migration 0099 — Re-run the v3.110.130 evaluation demo-tag backfill.
 *
 * Why a second migration: migration 0096 gated on
 * `DemoMode::current() === ON`. On an install where the operator
 * toggled demo mode ON *after* the v3.110.130 upgrade landed, the
 * first run of 0096 saw demo OFF and skipped. WP migration runners
 * track applied migrations by name and never re-execute them, so
 * 0096 will never run again on that install — even though the data
 * still needs tagging.
 *
 * Symptom this fixes: pilot in demo-ON mode reported seeing
 * evaluations in the coach dashboard's "recent evaluations" widget
 * (which bypasses `apply_demo_scope`) but an empty list when
 * clicking "show all" (which applies the scope and filters out the
 * untagged rows). The going-forward wizard tagging from v3.110.130
 * tags new rows correctly; this migration sweeps the pre-existing
 * untagged rows.
 *
 * Same idempotent INSERT … WHERE NOT EXISTS shape as 0096. Re-running
 * on an install where 0096 already tagged every row is a no-op:
 * every `tt_evaluations` row already has a matching `tt_demo_tags`
 * entry; the NOT EXISTS clause filters every row out of the SELECT.
 *
 * The same demo-ON gate applies — the migration never tags rows on
 * installs where the operator is running with demo mode off (real
 * production data should stay un-tagged so the demo filter doesn't
 * accidentally hide it next time the toggle flips).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0099_backfill_evaluation_demo_tags_redo';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( ! class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) ) {
            return;
        }
        if ( \TT\Modules\DemoData\DemoMode::current() !== \TT\Modules\DemoData\DemoMode::ON ) {
            return;
        }

        $evals_table = $p . 'tt_evaluations';
        $tags_table  = $p . 'tt_demo_tags';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $evals_table ) ) !== $evals_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tags_table  ) ) !== $tags_table  ) return;

        $batch_id = 'eval-retag-v3.110.136';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — table names + literal batch_id are safe.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$tags_table} (club_id, batch_id, entity_type, entity_id, extra_json)
             SELECT e.club_id, %s, 'evaluation', e.id, NULL
               FROM {$evals_table} e
              WHERE NOT EXISTS (
                  SELECT 1 FROM {$tags_table} t
                   WHERE t.entity_type = 'evaluation' AND t.entity_id = e.id
              )",
            $batch_id
        ) );
    }
};
