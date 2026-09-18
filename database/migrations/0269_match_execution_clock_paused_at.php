<?php
/**
 * Migration 0269 — `clock_paused_at` on `tt_match_execution` (#3553).
 *
 * The live match clock existed only in the browser tab. The server stored
 * when each half started, but nothing about pauses reached it (the client
 * posted `pause` and never `resume`, the only writer of the per-half pause
 * totals), so a reload — a phone that locked, a tab the browser discarded —
 * brought the page back at 00:00, paused, and every sub, goal and tracked
 * action logged after that carried the wrong minute.
 *
 * With the moment of the current pause stored, the server can answer "how
 * far into the half are we, and is the clock running" on its own:
 * `pause` stamps it, `resume` folds the gap into the half's pause total and
 * clears it. The client derives its clock from that answer on every load.
 *
 * UTC, like the `*_started_at` / `*_ended_at` columns beside it. NULL means
 * the clock is not paused. Additive + idempotent through
 * MigrationHelpers::addColumnIfMissing; forward-only; run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0269_match_execution_clock_paused_at';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_match_execution';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing( $table, 'clock_paused_at', 'DATETIME DEFAULT NULL', 'second_half_pause_seconds' );
    }
};
