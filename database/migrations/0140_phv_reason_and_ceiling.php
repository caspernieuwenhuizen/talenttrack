<?php
/**
 * Migration 0140 — Add `reason_key` + `intensity_ceiling` to
 * `tt_player_phv_flags` (#1089 / VCT-14).
 *
 * The original schema (migration 0122) ships `is_active` + `notes`.
 * VCT-14 ships the per-player PHV panel on the Profile tab + the
 * pill on the hero, with two new fields the mockup specifies:
 *
 *   reason_key       — enum-ish short string (`injury_knee`,
 *                      `asthma`, `cardiac`, `other_medical`,
 *                      `temp_fatigue`, …) so reasons translate via
 *                      `tt_lookups` and stay short for the coach view.
 *
 *   intensity_ceiling — TINYINT 1..10 — max intensity band the player
 *                      should train at. `WorkloadCapRule` will gain
 *                      a soft-cap path in a later slice; for now the
 *                      coach view + wizard step 4 surface the ceiling
 *                      as documentation alongside the existing
 *                      growth-spurt reduction.
 *
 * Forward-only; idempotent — checks both columns before adding so
 * re-running on a schema that already has them is a no-op.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0140_phv_reason_and_ceiling';
    }

    public function up(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'tt_player_phv_flags';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            return;
        }

        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}", 0 );
        if ( ! is_array( $cols ) ) $cols = [];

        if ( ! in_array( 'reason_key', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$t} ADD COLUMN reason_key VARCHAR(64) NULL AFTER is_active" );
        }
        if ( ! in_array( 'intensity_ceiling', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$t} ADD COLUMN intensity_ceiling TINYINT UNSIGNED NULL AFTER reason_key" );
        }
    }
};
