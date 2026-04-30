<?php
/**
 * Migration 0049 — Custom CSS independence (#0064).
 *
 * Adds the `tt_custom_css_history` table that stores the last N saves
 * of every custom-CSS payload plus named presets the operator has
 * pinned. The "live" current payload itself lives in `tt_config`
 * keyed on `custom_css.<surface>.css` etc. — that fits the existing
 * branding pattern and avoids a one-row `tt_clubs` add when the
 * tenancy scaffold is still hardcoded to club_id=1 (`CurrentClub`).
 *
 * The history table tracks both auto-saves (one row per save, max 10
 * retained) and named presets (operator-pinned snapshots that don't
 * count against the rolling-10 cap). Each row carries the full CSS
 * body — they're click-to-revert points.
 *
 * Idempotent. Re-running leaves existing rows alone.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0049_custom_css';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$p}tt_custom_css_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            surface VARCHAR(20) NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'auto',
            preset_name VARCHAR(120) DEFAULT NULL,
            css_body LONGTEXT NOT NULL,
            visual_settings LONGTEXT DEFAULT NULL,
            byte_count INT UNSIGNED NOT NULL DEFAULT 0,
            saved_by_user_id BIGINT UNSIGNED NOT NULL,
            saved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_club_surface (club_id, surface),
            KEY idx_kind (kind),
            KEY idx_saved_at (saved_at)
        ) {$charset};";
        dbDelta( $sql );
    }
};
