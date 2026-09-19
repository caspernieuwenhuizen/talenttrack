<?php
/**
 * Migration 0274 — delivery record on `tt_player_reports` (#3683).
 *
 * A trial letter was generated, stored and printed, and nothing after
 * that was written down. The head of development reopening a case a week
 * later saw "Active" and could not tell whether the family had ever been
 * handed the letter — the one fact that decides whether to chase it.
 *
 * TalentTrack still sends nothing: delivery is a human step. These three
 * columns record who handed the letter over, when, and how.
 *
 * NULL throughout means "not recorded as delivered", which is what every
 * existing letter reads as; there is nothing to backfill from, and
 * guessing "probably delivered" would be worse than an honest blank.
 *
 * Additive + idempotent through MigrationHelpers::addColumnIfMissing;
 * forward-only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0274_player_reports_delivery';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_reports';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing( $table, 'delivered_at', 'DATETIME DEFAULT NULL', 'first_accessed_at' );
        MigrationHelpers::addColumnIfMissing( $table, 'delivered_by', 'BIGINT UNSIGNED DEFAULT NULL', 'delivered_at' );
        MigrationHelpers::addColumnIfMissing( $table, 'delivery_method', 'VARCHAR(16) DEFAULT NULL', 'delivered_by' );
    }
};
