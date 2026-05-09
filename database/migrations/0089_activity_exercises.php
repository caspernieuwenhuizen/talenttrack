<?php
/**
 * Migration 0089 — `tt_activity_exercises` linkage (#0016 Sprint 2a).
 *
 * Sprint 2 of the photo-to-session capture epic links `tt_activities`
 * to specific `tt_exercises` rows in an ordered sequence. Each row
 * references an immutable exercise version (pinned via the
 * `exercise_id` FK to a specific row id, not a logical key) so
 * editing the underlying exercise produces a new row at version+1
 * without mutating historical activities.
 *
 *   tt_activity_exercises
 *     id BIGINT PK
 *     club_id INT (CLAUDE.md §4 SaaS-readiness)
 *     activity_id BIGINT (FK to tt_activities.id, logical)
 *     exercise_id BIGINT (FK to tt_exercises.id — specific version
 *                         row, NOT a logical exercise key)
 *     order_index SMALLINT (sequence within the activity)
 *     actual_duration_minutes SMALLINT (coach may override the
 *                                       exercise's planned duration)
 *     notes TEXT (per-row notes scribbled on the photo / typed by
 *                 the coach)
 *     is_draft TINYINT (Sprint 6 surfaces unconfirmed AI extractions
 *                       as drafts; Sprint 2-5 only ever write 0)
 *     created_at, updated_at
 *
 * Per CLAUDE.md §4: club_id NOT NULL DEFAULT 1; the row inherits
 * the club_id of the parent activity. Every read scopes by club_id.
 *
 * Sprint 2a (this ship) lands the schema + repository only — UI
 * integration on the activity edit page lands in Sprint 2b.
 *
 * Idempotent. Safe to re-run.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0089_activity_exercises';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_activity_exercises (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            activity_id BIGINT UNSIGNED NOT NULL,
            exercise_id BIGINT UNSIGNED NOT NULL,
            order_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            actual_duration_minutes SMALLINT UNSIGNED DEFAULT NULL,
            notes TEXT NULL,
            is_draft TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_activity_order (club_id, activity_id, order_index),
            KEY idx_activity (activity_id),
            KEY idx_exercise (exercise_id),
            KEY idx_draft (is_draft)
        ) $charset;" );
    }
};
