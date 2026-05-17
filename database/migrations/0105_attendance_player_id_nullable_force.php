<?php
/**
 * Migration 0105 — third attempt to make `tt_attendance.player_id` nullable.
 *
 * Pilot symptom (post-v3.110.156): "Gast toevoegen" → "The guest could
 * not be added (database error: Column 'player_id' cannot be null)".
 *
 * Lineage:
 *
 *   - 0020 (v3.26-ish) introduced the relax-to-NULL behind a
 *     `SELECT IS_NULLABLE` gate. Some installs saw the gate evaluate
 *     to the wrong branch and the ALTER never ran; 0020 was still
 *     marked applied.
 *
 *   - 0101 (v3.110.145) re-tried unconditionally:
 *
 *         ALTER TABLE … MODIFY COLUMN player_id BIGINT UNSIGNED NULL
 *
 *     `$wpdb->query()` returns false on error but doesn't propagate
 *     it. If the ALTER silently failed (privilege denied on shared
 *     hosting, MariaDB optimisation skipping the change, etc.), the
 *     migration was still marked applied and the column stayed NOT
 *     NULL — exactly what the pilot is seeing now.
 *
 * This migration:
 *
 *   1. Reads `IS_NULLABLE` from `INFORMATION_SCHEMA.COLUMNS` BEFORE
 *      the ALTER (skip if already YES — true no-op for installs
 *      where 0101 actually worked).
 *
 *   2. Runs the ALTER with full backtick-quoting + explicit
 *      `DEFAULT NULL` to nudge MariaDB into committing the change
 *      even when the optimiser thinks the column "already matches".
 *
 *   3. Captures `$wpdb->last_error` from the ALTER attempt.
 *
 *   4. Reads `IS_NULLABLE` AFTER the ALTER. If still NO, logs the
 *      pre-state, post-state, and the captured error to the standard
 *      Logger (or `error_log()` as fallback) so the operator can see
 *      WHY it failed. Without verification + logging, the failure
 *      mode is "migration marked applied, schema unchanged, every
 *      Add Guest fails with the same SQL error indefinitely."
 *
 * If this migration also fails to make the column nullable, the
 * `add_guest` REST handler (v3.110.158) defensively writes
 * `player_id = 0` for guest rows so the surface keeps working
 * regardless. The migration log entry is the diagnostic trail for
 * the operator to file a hosting support ticket about ALTER
 * privileges if that's the root cause.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0105_attendance_player_id_nullable_force';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_attendance';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $pre = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'player_id'",
            $table
        ) );

        if ( strtoupper( trim( $pre ) ) === 'YES' ) {
            return; // Already nullable. True no-op.
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — table name is hardcoded.
        $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `player_id` BIGINT UNSIGNED NULL DEFAULT NULL" );
        $alter_error = (string) $wpdb->last_error;

        $post = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'player_id'",
            $table
        ) );

        if ( strtoupper( trim( $post ) ) === 'YES' ) {
            return; // ALTER succeeded.
        }

        // Schema didn't change. Leave a breadcrumb so the operator
        // can see why — Logger if available, error_log otherwise.
        $payload = [
            'table'            => $table,
            'pre_is_nullable'  => $pre,
            'post_is_nullable' => $post,
            'db_error'         => $alter_error,
        ];
        if ( class_exists( '\\TT\\Infrastructure\\Logging\\Logger' ) ) {
            \TT\Infrastructure\Logging\Logger::error( 'migration.0105.alter_failed', $payload );
        } else {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[TalentTrack] migration 0105 ALTER failed: ' . wp_json_encode( $payload ) );
        }
    }
};
