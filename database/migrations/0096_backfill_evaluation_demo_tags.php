<?php
/**
 * Migration 0096 — Backfill missing demo tags for evaluations created
 * via the wizard.
 *
 * The new evaluation wizard's two write paths
 * (`Modules/Wizards/Evaluation/ReviewStep::submit()` and the shared
 * `EvaluationInserter::insert()`) never called
 * `DemoMode::tagIfActive('evaluation', $id)` after inserting the
 * `tt_evaluations` row. Other writer surfaces (REST `create_eval`,
 * wp-admin form, demo generators) all tagged correctly — the wizard
 * was the outlier. Pilot reported: "evaluations are not visible" while
 * the database had hundreds of rows, but every one was invisible to
 * `apply_demo_scope('e','evaluation')` in demo-ON mode because no
 * matching `tt_demo_tags` row existed.
 *
 * The going-forward fix lives in the two wizard write paths
 * (v3.110.130). This migration patches existing data: insert a demo
 * tag for every `tt_evaluations` row that doesn't have one — BUT only
 * when the install is currently in demo-ON mode. The gate prevents
 * silently tagging real evaluations as demo on installs where some
 * mix of demo / real data exists.
 *
 * Idempotent: a row that already has a tag is skipped by the
 * NOT EXISTS clause. Re-running is a no-op.
 *
 * Defensive: short-circuits when DemoMode isn't ON, when either
 * table doesn't exist (very old installs), or when DemoMode class
 * isn't loaded yet.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0096_backfill_evaluation_demo_tags';
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

        $batch_id = 'wizard-untagged-recovery-v3.110.130';

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
