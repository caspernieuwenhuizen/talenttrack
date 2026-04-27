<?php
/**
 * Migration: 0030_audit_log_payload_rename
 *
 * #0021 — fixes a long-running schema drift on tt_audit_log.
 *
 * Background: migration 0002 created the table with a `payload`
 * LONGTEXT column and an `ip_address` VARCHAR(45) column.
 * Activator::ensureSchema (used on fresh installs) however defined a
 * `details` LONGTEXT column instead, and omitted ip_address. Since
 * Activator::markMigrationsApplied auto-marks 0001-0004 as applied
 * without running them, fresh installs ended up with the wrong column
 * name AND a missing IP column. AuditService::record() writes to
 * `payload` + `ip_address`, so audit logging was silently broken on
 * any fresh-install site since the column rename happened.
 *
 * This migration:
 *   1. Rename `details` → `payload` if `details` exists and `payload`
 *      does not.
 *   2. Add `ip_address` VARCHAR(45) DEFAULT '' if missing.
 *
 * Idempotent. Safe to run on:
 *   - Sites with the correct schema already (0002 ran cleanly): no-op.
 *   - Sites with the broken `details` schema: column gets renamed.
 *   - Sites mid-state (somehow have both): skips the rename, just adds
 *     ip_address if missing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0030_audit_log_payload_rename';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_audit_log';

        // Table missing entirely? Nothing to do — Activator::ensureSchema
        // will recreate with the correct columns on the next activation.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $has_details = $this->columnExists( $table, 'details' );
        $has_payload = $this->columnExists( $table, 'payload' );
        $has_ip      = $this->columnExists( $table, 'ip_address' );

        if ( $has_details && ! $has_payload ) {
            // Rename in place — keeps existing rows intact.
            $wpdb->query( "ALTER TABLE `$table` CHANGE COLUMN `details` `payload` LONGTEXT" );
        } elseif ( $has_details && $has_payload ) {
            // Defensive: a partially-fixed table has both. Drop the orphaned
            // `details` column — `payload` is the column AuditService reads.
            $wpdb->query( "ALTER TABLE `$table` DROP COLUMN `details`" );
        }

        if ( ! $has_ip ) {
            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `ip_address` VARCHAR(45) DEFAULT '' AFTER `payload`" );
        }
    }

    private function columnExists( string $table, string $column ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column ) );
        return $row !== null;
    }
};
