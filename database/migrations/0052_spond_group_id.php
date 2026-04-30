<?php
/**
 * Migration 0052 — Spond JSON-API switchover (#0062).
 *
 * Adds:
 *  - `tt_teams.spond_group_id VARCHAR(64) DEFAULT NULL` — the Spond
 *    group UUID that maps a TalentTrack team to a Spond group. The
 *    JSON API is group-scoped, replacing the per-team iCal URL the
 *    #0031 spec assumed (Spond never published iCal).
 *
 * Backfill:
 *  - Nulls out any existing `spond_ical_url` value. Column is kept
 *    in schema for one release for rollback safety; #0062's spec
 *    schedules its drop in a follow-up migration.
 *
 * Per-club credentials (email + password) live in `tt_config` keyed
 * `spond.credentials.*` and are managed by `Spond\CredentialsManager`,
 * not by this migration. No DB column for them.
 *
 * Idempotent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0052_spond_group_id';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_teams";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $col_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'spond_group_id'",
            $table
        ) );
        if ( $col_exists === null ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN spond_group_id VARCHAR(64) DEFAULT NULL" );
            @$wpdb->query( "ALTER TABLE {$table} ADD KEY idx_spond_group_id (spond_group_id)" );
        }

        $url_col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'spond_ical_url'",
            $table
        ) );
        if ( $url_col !== null ) {
            $wpdb->query( "UPDATE {$table} SET spond_ical_url = NULL WHERE spond_ical_url IS NOT NULL" );
        }
    }
};
