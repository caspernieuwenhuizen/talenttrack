<?php
/**
 * Migration 0195 — per-test "show on player profile" flag (#2204, epic #2116).
 *
 * Adds tt_measurement_definitions.show_on_profile (TINYINT(1), default 1) so an
 * operator can hide a specific test's results from the player profile while
 * keeping them in reports and exports. Default 1 preserves current behaviour:
 * every existing test stays visible on upgrade.
 *
 * Additive + idempotent — column-add on an existing table goes through
 * MigrationHelpers::addColumnIfMissing (never dbDelta, per the base-class note).
 * Forward-only. Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0195_measurement_show_on_profile';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_measurement_definitions';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing(
            $table,
            'show_on_profile',
            'TINYINT(1) NOT NULL DEFAULT 1',
            'is_active'
        );
    }
};
