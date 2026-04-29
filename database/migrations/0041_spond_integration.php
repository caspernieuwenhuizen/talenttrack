<?php
/**
 * Migration 0041 — Spond integration scaffold (#0031).
 *
 * Adds:
 *  - `tt_teams.spond_ical_url TEXT DEFAULT NULL` (encrypted at rest via
 *    CredentialEncryption — column stores the envelope, not plaintext).
 *  - `tt_teams.spond_last_sync_at DATETIME DEFAULT NULL`.
 *  - `tt_teams.spond_last_sync_status VARCHAR(32) DEFAULT NULL` —
 *    `ok | failed | disabled | never`.
 *  - `tt_teams.spond_last_sync_message TEXT DEFAULT NULL` — last
 *    success/error string surfaced in the team-form notice.
 *  - `tt_activities.external_id VARCHAR(64) DEFAULT NULL` — Spond UID
 *    (or any future external system's UID). Source flag rides on the
 *    existing `activity_source_key` column shipped in v3.47.0
 *    (`activity_source = 'spond'` for Spond rows); no separate
 *    `external_source` column.
 *  - Index `(activity_source_key, external_id)` for upsert lookup.
 *
 * Idempotent. No data backfill needed.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0041_spond_integration';
    }

    public function up(): void {
        $this->addTeamSpondColumns();
        $this->addActivityExternalIdColumn();
    }

    private function addTeamSpondColumns(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_teams";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $cols = [
            'spond_ical_url'          => 'TEXT DEFAULT NULL',
            'spond_last_sync_at'      => 'DATETIME DEFAULT NULL',
            'spond_last_sync_status'  => "VARCHAR(32) DEFAULT NULL",
            'spond_last_sync_message' => 'TEXT DEFAULT NULL',
        ];

        foreach ( $cols as $name => $defn ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table, $name
            ) );
            if ( $exists === null ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$defn}" );
            }
        }
    }

    private function addActivityExternalIdColumn(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_activities";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $col_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'external_id'",
            $table
        ) );
        if ( $col_exists === null ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN external_id VARCHAR(64) DEFAULT NULL" );
        }

        $idx_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_external_lookup'",
            $table
        ) );
        if ( $idx_exists === null ) {
            @$wpdb->query( "ALTER TABLE {$table} ADD KEY idx_external_lookup (activity_source_key, external_id)" );
        }
    }
};
