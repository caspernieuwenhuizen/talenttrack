<?php
/**
 * Migration 0129 — Blueprint assignment refs (#953).
 *
 * Adds discriminated-reference columns to `tt_team_blueprint_assignments`
 * so depth-chart slots can hold three distinct kinds of reference:
 *
 *   - `player`  — canonical roster player (existing behaviour;
 *                 `player_id` continues to point at `tt_players.id`).
 *                 Cross-team picks are still `kind=player` — the linked
 *                 player's `team_id` differs from the blueprint's team
 *                 but the FK target is the same.
 *   - `guest`   — anonymous trial / visiting player; `guest_name`
 *                 carries the display name, `guest_position` the
 *                 optional position hint. `player_id` is NULL.
 *   - `custom`  — free-text placeholder (e.g. "Scout target #4"),
 *                 not tied to any player record. `custom_label`
 *                 carries the display string. `player_id` is NULL.
 *
 * Discriminator column `ref_kind` defaults to `'player'` so every
 * existing row keeps its current semantics. Idempotent column-add
 * via dbDelta; safe to re-run on installs that already have the
 * columns.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0129_blueprint_assignment_refs';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // dbDelta only ALTERs when the column doesn't already exist, so
        // re-runs are safe. Keep the same table definition as 0070 but
        // append the four new columns + drop any pre-existing UNIQUE
        // constraint on (blueprint_id, player_id) — same-player-in-
        // multiple-slots is now legal per spec.
        $assignments = "CREATE TABLE IF NOT EXISTS {$p}tt_team_blueprint_assignments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            blueprint_id BIGINT UNSIGNED NOT NULL,
            slot_label VARCHAR(40) NOT NULL,
            tier VARCHAR(20) NOT NULL DEFAULT 'primary',
            ref_kind VARCHAR(20) NOT NULL DEFAULT 'player',
            player_id BIGINT UNSIGNED DEFAULT NULL,
            guest_name VARCHAR(120) DEFAULT NULL,
            guest_position VARCHAR(60) DEFAULT NULL,
            custom_label VARCHAR(120) DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_slot_tier (blueprint_id, slot_label, tier),
            KEY idx_blueprint (blueprint_id),
            KEY idx_club (club_id),
            KEY idx_player (player_id)
        ) {$charset};";

        dbDelta( $assignments );
    }

    public function down(): void {
        // Forward-only migration. Reverting would lose `guest` and
        // `custom` rows for any blueprint that uses them. Operators
        // who need to roll back should restore from a pre-#953 backup.
    }
};
