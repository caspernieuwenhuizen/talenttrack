<?php
/**
 * Migration 0144 — Add optional `start_time` + `end_time` to
 * `tt_activities` (#1126).
 *
 * Pilot 2026-06-03 asked for optional time fields on activities.
 * Today coaches type the time into the title ("Tactische bespreking
 * 18:00–18:30"); this lifts it into a structured pair that the
 * planner, team detail, and activity detail can render uniformly.
 *
 * Both nullable — empty surfaces render nothing (no placeholder).
 * The match-specific `kickoff_time` (added by migration 0079) stays
 * — it's match-only and the team-sheet exporter still keys off it.
 * The new fields are universal across every activity type.
 *
 * Idempotent + additive only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0144_activity_start_end_time';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $t = "{$p}tt_activities";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            return;
        }

        self::addColumn( $t, 'start_time', "TIME DEFAULT NULL", 'session_date' );
        self::addColumn( $t, 'end_time',   "TIME DEFAULT NULL", 'start_time' );
    }

    private static function addColumn( string $table, string $column, string $def, string $after ): void {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column
        ) );
        if ( $exists === $column ) return;
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$def} AFTER {$after}" );
    }
};
