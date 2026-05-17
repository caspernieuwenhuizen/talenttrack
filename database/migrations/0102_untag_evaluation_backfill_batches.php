<?php
/**
 * Migration 0102 — reverse the indiscriminate evaluation demo-tag
 * backfill applied by migrations 0096 + 0099.
 *
 * Pilot symptom (after v3.110.146): "evaluations missing — not solved
 * yet." Root-cause analysis on 2026-05-17:
 *
 *   1. Widget + REST list both apply `apply_demo_scope('e','evaluation')`
 *      post-v3.110.136, so the surfaces are aligned. The mismatch
 *      that v3.110.136 was supposed to fix IS fixed — both now
 *      produce the same rowset.
 *
 *   2. The filter itself is wrong on installs where 0096 or 0099 ran
 *      while demo mode was ON. Both migrations indiscriminately tag
 *      every untagged eval with INSERT IGNORE shape:
 *
 *          INSERT INTO tt_demo_tags
 *          SELECT e.club_id, '<batch>', 'evaluation', e.id
 *            FROM tt_evaluations e
 *           WHERE NOT EXISTS (matching tag row);
 *
 *      That conflates two distinct categories: (A) wizard-created in
 *      demo-ON pre-v3.110.130 — the original bug, correctly tagged;
 *      (B) real evals created via REST / admin / wizard post-fix in
 *      demo-OFF mode — should NOT be tagged. The migrations tag (B)
 *      too. The moment the operator toggles demo OFF, demo-scope
 *      filters become `NOT IN (tagged)` → all real evals invisible.
 *
 *   3. This migration removes ONLY the rows written by the two
 *      offending backfills, identified by their distinctive batch_ids
 *      ('wizard-untagged-recovery-v3.110.130' and 'eval-retag-v3.110.136').
 *      Seeded demo evals (from `EvaluationGenerator`, `ExcelImporter`)
 *      use different batch_ids and stay tagged. New evals tagged
 *      going forward via DemoMode::tagIfActive use the
 *      'user-created' batch_id and stay too.
 *
 *   4. Residual risk: pre-v3.110.130 wizard-created evals (the
 *      population the backfill was trying to recover) become
 *      invisible again in demo-ON mode. Small window, small
 *      population; operator can re-rate or manually tag any
 *      surfacing. Better than the all-evals-invisible failure mode.
 *
 * Idempotent: re-running is a no-op (DELETE on already-deleted rows
 * does nothing).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0102_untag_evaluation_backfill_batches';
    }

    public function up(): void {
        global $wpdb;
        $tags_table = $wpdb->prefix . 'tt_demo_tags';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tags_table ) ) !== $tags_table ) return;

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$tags_table}
              WHERE entity_type = %s
                AND batch_id IN (%s, %s)",
            'evaluation',
            'wizard-untagged-recovery-v3.110.130',
            'eval-retag-v3.110.136'
        ) );
    }
};
