<?php
/**
 * Migration 0020 — Guest-player attendance (#0026).
 *
 * Adds the columns + index that turn `tt_attendance` from a roster-only
 * record into one that can also store linked or anonymous guest entries:
 *
 *   - is_guest           — flag; existing rows default to 0 (non-guest)
 *   - guest_player_id    — FK to tt_players for linked guests; NULL for anonymous
 *   - guest_name         — display name for anonymous guests
 *   - guest_age          — optional age for anonymous guests
 *   - guest_position     — optional position label for anonymous guests
 *   - guest_notes        — coach-authored observations on anonymous guests
 *
 * Plus relaxes `player_id` from NOT NULL to NULL so guest rows can
 * leave it empty (linked guests reference via `guest_player_id`,
 * anonymous guests have no player at all).
 *
 * Plus an index `idx_session_guest (session_id, is_guest)` to keep
 * "fetch guests for this session" / "fetch non-guest attendance" cheap.
 *
 * Idempotent: every column / index check uses information_schema first.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0020_attendance_guests';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = $p . 'tt_attendance';

        $this->addColumnIfMissing( $table, 'is_guest',        "TINYINT(1) NOT NULL DEFAULT 0" );
        $this->addColumnIfMissing( $table, 'guest_player_id', "BIGINT UNSIGNED NULL" );
        $this->addColumnIfMissing( $table, 'guest_name',      "VARCHAR(120) NULL" );
        $this->addColumnIfMissing( $table, 'guest_age',       "TINYINT UNSIGNED NULL" );
        $this->addColumnIfMissing( $table, 'guest_position',  "VARCHAR(60) NULL" );
        $this->addColumnIfMissing( $table, 'guest_notes',     "TEXT NULL" );

        // Relax player_id to NULL so anonymous + linked guest rows
        // don't have to carry a sentinel value. Existing rows are
        // already populated; making the column nullable doesn't
        // affect them.
        $is_nullable = $wpdb->get_var( $wpdb->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'player_id'",
            $table
        ) );
        if ( $is_nullable === 'NO' ) {
            $wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN player_id BIGINT UNSIGNED NULL" );
        }

        $this->addIndexIfMissing( $table, 'idx_session_guest', '(session_id, is_guest)' );
    }

    private function addColumnIfMissing( string $table, string $column, string $definition ): void {
        global $wpdb;
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table, $column
        ) );
        if ( $exists === 0 ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
        }
    }

    private function addIndexIfMissing( string $table, string $index, string $columns ): void {
        global $wpdb;
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, $index
        ) );
        if ( $exists === 0 ) {
            $wpdb->query( "ALTER TABLE {$table} ADD KEY {$index} {$columns}" );
        }
    }
};
