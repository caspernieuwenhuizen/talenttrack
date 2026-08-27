<?php
/**
 * Migration 0236 — `tt_players.sex` (#2894).
 *
 * Prerequisite for BMI-for-age (#2895). Growth references are sex-specific:
 * boys and girls have different BMI-for-age curves at every age, so a
 * percentile cannot be computed without knowing which curve applies. The
 * player record carried `date_of_birth`, `height_cm` and `weight_kg` and
 * nothing about sex.
 *
 * A fixed, purpose-labelled enum rather than a `tt_lookups` vocabulary:
 * `male` / `female` / `''`. A growth reference has exactly two curves, and
 * making the list editable per academy would imply the reference follows
 * whatever an operator types into it. It does not.
 *
 * The empty string is a first-class value, not a missing one. It is the
 * default for every existing record, nothing is backfilled or inferred, and
 * a blank costs the player only the age-adjusted column — raw BMI still
 * computes. Inferring sex from a name would be both unreliable and exactly
 * the wrong thing to do to a minor's record.
 *
 * `VARCHAR(20)` rather than a MySQL `ENUM`: the plugin stores every other
 * vocabulary as a string, and an `ENUM` would need a schema change to gain
 * a value the growth references may one day distinguish.
 *
 * Additive and idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0236_player_sex';
    }

    public function up(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tt_players';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing(
            $table,
            'sex',
            "VARCHAR(20) NOT NULL DEFAULT ''",
            'date_of_birth'
        );
    }
};
