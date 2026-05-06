<?php
/**
 * Migration 0072 — Team blueprint tiers (#0068 Phase 2).
 *
 * Phase 2 of the Team Blueprint epic adds the squad-plan flavour
 * (multi-tier position fits — primary / secondary / tertiary). Phase 1
 * landed `tt_team_blueprint_assignments` with UNIQUE(blueprint_id,
 * slot_label); a single player per slot. Squad-plan needs three:
 * primary starter, secondary backup, tertiary depth.
 *
 * Schema change:
 *   - Add `tier` VARCHAR(10) NOT NULL DEFAULT 'primary'
 *   - Drop UNIQUE(blueprint_id, slot_label)
 *   - Add UNIQUE(blueprint_id, slot_label, tier)
 *
 * Existing rows backfill to tier='primary' so match-day blueprints stay
 * single-tier (the editor still treats match_day as one row per slot;
 * tier just becomes a no-op constant on that flavour).
 *
 * Idempotent. Guards on column existence + key existence.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0072_team_blueprint_tiers';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = $p . 'tt_team_blueprint_assignments';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $has_tier = $wpdb->get_row( $wpdb->prepare(
            "SHOW COLUMNS FROM `$table` LIKE %s",
            'tier'
        ) );
        if ( $has_tier === null ) {
            $wpdb->query(
                "ALTER TABLE `$table` ADD COLUMN `tier` VARCHAR(10) NOT NULL DEFAULT 'primary' AFTER slot_label"
            );
        }

        $old_unique = $wpdb->get_row( $wpdb->prepare(
            "SHOW INDEX FROM `$table` WHERE Key_name = %s",
            'uk_slot'
        ) );
        if ( $old_unique !== null ) {
            $wpdb->query( "ALTER TABLE `$table` DROP INDEX `uk_slot`" );
        }

        $new_unique = $wpdb->get_row( $wpdb->prepare(
            "SHOW INDEX FROM `$table` WHERE Key_name = %s",
            'uk_slot_tier'
        ) );
        if ( $new_unique === null ) {
            $wpdb->query(
                "ALTER TABLE `$table` ADD UNIQUE KEY `uk_slot_tier` (`blueprint_id`, `slot_label`, `tier`)"
            );
        }
    }
};
