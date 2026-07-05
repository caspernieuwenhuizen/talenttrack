<?php
/**
 * Migration 0196 — timed opponent goals (#2275, epic #2273).
 *
 * Adds tt_match_execution_goal_events.team so the opponent's goals can be
 * recorded as individual timed events (half + minute) alongside our own,
 * instead of living only in the plain away_score counter. Existing rows
 * backfill to 'home' via the column default — every per-player goal event
 * logged to date is one of ours. Away goals carry player_id = 0 (the
 * opponent has no tracked individual scorer).
 *
 * Additive + idempotent — column-add on an existing table goes through
 * MigrationHelpers::addColumnIfMissing (never dbDelta). Forward-only.
 * Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0196_match_execution_opponent_goals';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_match_execution_goal_events';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing(
            $table,
            'team',
            "VARCHAR(8) NOT NULL DEFAULT 'home'",
            'execution_id'
        );
    }
};
