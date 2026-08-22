<?php
/**
 * Migration 0225 — animated scenes for exercises (#2501, epic #2493).
 *
 * A scene is a field of an exercise, the same category as its diagram
 * image or its coaching points, that happens to be edited on a canvas.
 * It lives in its own table rather than as a column because an exercise
 * can carry several — a build-up drill often wants one scene for the
 * pattern and another for the pressing variant — and because
 * `scene_json` is large enough that loading it on every exercise list
 * query would be wasteful.
 *
 * ## `scene_json`
 *
 * Coordinates are **0–100 in pitch space**, so one scene scales from a
 * 360px phone to the A4 print without re-authoring. Keyframe `t` is in
 * milliseconds, absolute, because that is what an editor timeline shows
 * and what a coach reasons about ("the ball moves at two seconds").
 *
 * The shipped Speelwijze renderer uses a normalised 0–1 `t` and three
 * separate actor arrays; this contract deliberately does not follow it.
 * A single `actors` array keyed by `kind` extends to cones and goals
 * without a fourth array, and id-based `links` survive an actor being
 * repositioned — a coordinate-based arrow does not. See the verification
 * note on #2501.
 *
 * ## Why `is_primary` rather than ordering alone
 *
 * The exercise detail, the sideline view and the print sheet each show
 * one scene. Without an explicit flag each surface would have to invent
 * "the first one", and they would eventually disagree.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0225_exercise_scenes';
    }

    public function up(): void {
        global $wpdb;

        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_exercise_scenes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            exercise_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) DEFAULT NULL,
            pitch_preset VARCHAR(24) NOT NULL DEFAULT 'full',
            duration_ms SMALLINT UNSIGNED NOT NULL DEFAULT 6000,
            scene_json LONGTEXT NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_exercise (club_id, exercise_id, sort_order),
            KEY idx_primary (club_id, exercise_id, is_primary)
        ) {$charset};" );
    }
};
