<?php
/**
 * Migration 0044 — Player status methodology config (#0057 Sprint 3).
 *
 * One row per (club_id, age_group_id) — the per-age-group config that
 * `MethodologyResolver::forPlayer()` looks up. `age_group_id = 0` is
 * the club-wide default fallback. Config is JSON for v1 flexibility;
 * if patterns stabilise, normalise the schema later.
 *
 * No seed — `MethodologyResolver` already returns a hard-coded shipped
 * default when the table is empty, so the methodology works the moment
 * the migration runs.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0044_player_status_methodology';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_player_status_methodology";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) return;

        $charset = $wpdb->get_charset_collate();
        $wpdb->query( "
            CREATE TABLE {$table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                age_group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                config_json LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY uk_club_age_group (club_id, age_group_id)
            ) {$charset};
        " );
    }
};
