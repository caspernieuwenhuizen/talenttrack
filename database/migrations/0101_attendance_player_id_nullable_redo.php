<?php
/**
 * Migration 0101 — re-apply `tt_attendance.player_id NULL` on
 * installs where migration 0020's MODIFY COLUMN didn't stick.
 *
 * Pilot report (v3.110.143 diagnostic): adding a guest to an
 * activity failed with "Column 'player_id' cannot be null" — the
 * REST handler's INSERT correctly passes NULL for guest rows
 * (linked guests reference via `guest_player_id`, anonymous guests
 * have no player), but the column was still `NOT NULL` on the
 * pilot's database.
 *
 * Migration 0020 (v3.26-ish) introduced the relax-to-NULL via:
 *
 *   $is_nullable = SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS …
 *   if ( $is_nullable === 'NO' ) {
 *     ALTER TABLE … MODIFY COLUMN player_id BIGINT UNSIGNED NULL
 *   }
 *
 * Most likely failure mode on the pilot install: 0020's IS_NULLABLE
 * check didn't return 'NO' exactly (case / whitespace / driver
 * variance), the gated ALTER never ran, and 0020 was still marked
 * applied. A subsequent backup/restore cycle could also have reset
 * the column to NOT NULL. Either way, this migration runs as a
 * fresh entry in `tt_migrations` and forces the ALTER through.
 *
 * Strategy: unconditionally `MODIFY COLUMN … NULL`. MySQL accepts
 * the ALTER even when the column is already nullable (no-op), so
 * the migration is idempotent and safe on installs where 0020
 * succeeded.
 *
 * Belt-and-braces guards: SHOW TABLES + SHOW COLUMNS so a stripped
 * test install where tt_attendance was never created doesn't fatal.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0101_attendance_player_id_nullable_redo';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_attendance';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            'player_id'
        ) );
        if ( $col !== 'player_id' ) {
            return; // Column doesn't exist on this install — unexpected, but bail rather than fatal.
        }

        // Unconditional ALTER. No-op when already nullable.
        $wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN player_id BIGINT UNSIGNED NULL" );
    }
};
