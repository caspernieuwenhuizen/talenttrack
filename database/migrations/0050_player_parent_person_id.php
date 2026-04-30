<?php
/**
 * Migration 0050 — `tt_players.parent_person_id` (#0063).
 *
 * Adds a nullable, indexed FK from `tt_players` to `tt_people.id`,
 * surfacing the linked-parent record explicitly on the player row
 * for the parent-picker on the player edit form. Coexists with the
 * legacy `guardian_name` / `guardian_email` / `guardian_phone` text
 * fields and the `tt_player_parents` pivot (#0032) — neither is
 * dropped in this PR. The picker writes the new column; the legacy
 * fields render below the picker as a fallback for installs that
 * haven't migrated their guardian data into `tt_people` yet.
 *
 * Idempotent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0050_player_parent_person_id';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_players';

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'parent_person_id'",
            $table
        ) );

        if ( $exists === 0 ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN parent_person_id BIGINT UNSIGNED DEFAULT NULL AFTER guardian_phone" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_parent_person (parent_person_id)" );
        }
    }

    public function down(): void {
        // No-op. Schema migrations are forward-only in this project.
    }
};
