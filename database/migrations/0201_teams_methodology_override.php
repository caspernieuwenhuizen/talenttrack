<?php
/**
 * Migration 0201 — per-team methodology override (#2318, epic #2316).
 *
 * The active methodology is resolved per team with an install-wide
 * default: the default lives in `tt_config` (`active_methodology_id`),
 * and a team may override it. This adds the nullable override column;
 * NULL means "use the install default".
 *
 * Idempotent via addColumnIfMissing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0201_teams_methodology_override';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_teams';

        if ( ! MigrationHelpers::addColumnIfMissing( $table, 'methodology_id', 'BIGINT UNSIGNED DEFAULT NULL', 'club_id' ) ) {
            throw new \RuntimeException( '0201: failed to add methodology_id to tt_teams' );
        }

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, 'idx_methodology'
        ) );
        if ( $exists === 0 ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_methodology` (methodology_id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }
};
