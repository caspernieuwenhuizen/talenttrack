<?php
/**
 * Migration 0010 — Archive support (v2.17.0).
 *
 * Adds `archived_at DATETIME NULL` + `archived_by BIGINT UNSIGNED NULL`
 * to every entity table that participates in the bulk-archive feature.
 * NULL = active; any timestamp = archived at that moment, by that user.
 *
 * Entities covered:
 *   - tt_players
 *   - tt_teams
 *   - tt_evaluations
 *   - tt_sessions
 *   - tt_goals
 *   - tt_people
 *
 * Idempotent — skips any column that already exists (for re-runs on
 * partially-migrated hosts).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0010_archive_support';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $tables = [
            'tt_players',
            'tt_teams',
            'tt_evaluations',
            'tt_sessions',
            'tt_goals',
            'tt_people',
        ];

        foreach ( $tables as $table ) {
            $full = $p . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) continue;

            if ( ! $this->columnExists( $full, 'archived_at' ) ) {
                $wpdb->query( "ALTER TABLE {$full} ADD COLUMN archived_at DATETIME NULL DEFAULT NULL" );
                $wpdb->query( "ALTER TABLE {$full} ADD INDEX idx_archived_at (archived_at)" );
            }
            if ( ! $this->columnExists( $full, 'archived_by' ) ) {
                $wpdb->query( "ALTER TABLE {$full} ADD COLUMN archived_by BIGINT UNSIGNED NULL DEFAULT NULL" );
            }
        }
    }

    private function columnExists( string $table, string $column ): bool {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME, $table, $column
        ) );
        return (int) $exists > 0;
    }
};
