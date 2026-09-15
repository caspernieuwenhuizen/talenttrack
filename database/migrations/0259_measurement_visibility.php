<?php
/**
 * Migration 0259 — per-test audience on a measurement definition (#3392).
 *
 * Adds tt_measurement_definitions.visibility so an operator can keep a test
 * away from the player and their parents while it stays visible to staff,
 * recorded, reported and trended.
 *
 * `show_on_profile` (migration 0195) could not express that: it is
 * viewer-agnostic, so switching it off hides the test from the coach too.
 * It keeps its meaning — off = on nobody's profile — and this column
 * decides the audience among those left.
 *
 * Vocabulary is the journey's (EventTypeDefinition::VISIBILITY_*), so "who
 * may see this" means one thing across the product rather than two.
 *
 * Default 'public' preserves current behaviour: every existing test stays
 * visible to everyone who could already see it. Nothing disappears from a
 * player's screen on upgrade, which is the first acceptance criterion.
 *
 * Additive + idempotent — column-add on an existing table goes through
 * MigrationHelpers::addColumnIfMissing (never dbDelta, per the base-class
 * note). Forward-only. Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0259_measurement_visibility';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_measurement_definitions';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing(
            $table,
            'visibility',
            "VARCHAR(32) NOT NULL DEFAULT 'public'",
            'show_on_profile'
        );
    }
};
