<?php
/**
 * Migration 0133 — match-execution state split (#1033).
 *
 * Backfills existing `tt_match_execution.state = 'finished'` rows to
 * `'finalized'` so the column shape lines up with the new
 * post-#1033 state machine:
 *
 *     not_started -> first_half -> half_time -> second_half
 *                 -> pending_review (editable post-match)
 *                 -> finalized (terminal, read-only)
 *
 * Safe interpretation: the rows currently in `'finished'` were closed
 * at a time when there was no edit affordance after End-match. The
 * coach can no longer go back and edit them, so they get the terminal
 * `'finalized'` value. If the operator wants a finalized match to
 * become editable again, they can flip it back via a future "Re-open
 * for review" admin action (deferred — not in #1033 v1).
 *
 * Idempotent. Re-running the migration on an install with no
 * `'finished'` rows left is a no-op.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0133_match_execution_state_pending_review';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_match_execution";

        // Table existence guard — `tt_match_execution` shipped in 0120;
        // any install on a migration <0120 hasn't reached this yet.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = %s WHERE state = %s",
            'finalized',
            'finished'
        ) );
    }
};
