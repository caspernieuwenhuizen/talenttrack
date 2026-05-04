<?php
/**
 * Migration 0061 — backfill rows where the v3.89.1-and-earlier delete
 * code wrote `tt_players.status='deleted'` instead of setting
 * `archived_at`. v3.89.2 fixed both delete paths to write the canonical
 * archive shape; this migration repairs prior installs.
 *
 * Affected rows:
 *   `tt_players` rows where `status = 'deleted'` AND `archived_at IS NULL`
 *
 * What we do:
 *   - `archived_at` ← `updated_at` (closer to the actual delete time
 *     than NOW() — the row was already touched when the bad delete
 *     fired, so updated_at is roughly when it happened)
 *   - `status` ← 'active' (the lifecycle marker; if the operator
 *     un-archives the player later, they shouldn't be stuck in a
 *     status the system never reads or handles)
 *
 * `archived_by` is left NULL — we don't know who originally clicked
 * Delete on these rows; the column allows NULL per the 0001 schema.
 *
 * Idempotent: the WHERE clause skips rows that already have
 * archived_at populated, so re-running is a no-op.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0061_backfill_player_status_deleted_to_archived_at';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_players';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $wpdb->query(
            "UPDATE {$table}
             SET archived_at = updated_at,
                 status      = 'active'
             WHERE status      = 'deleted'
               AND archived_at IS NULL"
        );
    }
};
