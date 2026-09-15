<?php
/**
 * Migration 0260 — a player's photo becomes a media reference (#3399).
 *
 * Adds `tt_players.photo_media_id`, pointing at the private media store
 * (#2589) instead of a public `wp-content/uploads/` URL.
 *
 * `photo_url` is deliberately NOT dropped here. Migration 0261 moves the
 * files and clears it row by row, and until that has run every surface
 * still has to render something — `PlayerPhoto` falls back to the column.
 * Dropping it in the same breath as adding this one would blank every
 * player's face for the length of an upgrade.
 *
 * Additive + idempotent, forward-only. Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0260_player_photo_media_id';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_players';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing(
            $table,
            'photo_media_id',
            'BIGINT UNSIGNED DEFAULT NULL',
            'photo_url'
        );
    }
};
