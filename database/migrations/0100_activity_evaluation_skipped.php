<?php
/**
 * Migration 0100 — adds `tt_activities.evaluation_skipped` boolean
 * for the v3.110.138 mark-attendance "Skip rating" split.
 *
 * Pilot symptom (#0092 follow-up): an activity where the coach
 * marked attendance and skipped rating still appeared in the
 * eval-wizard's activity picker. Root cause: the picker filters on
 * `plan_state='completed' AND NOT EXISTS (eval row)` — a skipped-
 * rating activity matches both conditions. v3.110.138 introduces a
 * two-button skip flow with a "no rating needed" branch that sets
 * this flag; the picker filters it out.
 *
 *   0 (default) — activity is in normal lifecycle. If completed and
 *                 unrated, it still surfaces in pickers.
 *   1           — coach explicitly decided no rating is needed.
 *                 Pickers exclude this row.
 *
 * Reversible: the activity detail view (chunk shipping in the same
 * release) has a "Re-open for rating" button gated on
 * `tt_edit_activities` that flips this back to 0.
 *
 * Idempotent. SHOW COLUMNS guard.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0100_activity_evaluation_skipped';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_activities';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            'evaluation_skipped'
        ) );
        if ( $exists === 'evaluation_skipped' ) return;

        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN evaluation_skipped TINYINT(1) NOT NULL DEFAULT 0 AFTER activity_status_key" );
    }
};
