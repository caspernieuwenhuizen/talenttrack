<?php
/**
 * Migration 0043 — Thread messages primitive (#0028).
 *
 * Two new tables backing the polymorphic conversation primitive used
 * by goals (v1) and — in follow-up PRs — trial cases, scout reports,
 * and PDP conversations:
 *
 *   tt_thread_messages — one row per message; keyed on
 *     (thread_type, thread_id) so multiple consumer modules share
 *     the table without a join.
 *   tt_thread_reads    — last-read timestamp per (user, thread).
 *     Composite primary key.
 *
 * Both tables include `club_id` for the SaaS-readiness scaffold
 * (#0052 PR-A) and `tt_thread_messages` carries a `uuid` per
 * `CLAUDE.md` § 4 root-entity rule.
 *
 * Idempotent. No data backfill.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0043_thread_messages';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $sql = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_thread_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) DEFAULT NULL,
            thread_type VARCHAR(32) NOT NULL,
            thread_id BIGINT UNSIGNED NOT NULL,
            author_user_id BIGINT UNSIGNED NOT NULL,
            body LONGTEXT NOT NULL,
            visibility VARCHAR(24) NOT NULL DEFAULT 'public',
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            edited_at DATETIME DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_thread (thread_type, thread_id, created_at),
            KEY idx_author (author_user_id),
            KEY idx_club (club_id),
            UNIQUE KEY uk_uuid (uuid)
        ) $c;";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_thread_reads (
            user_id BIGINT UNSIGNED NOT NULL,
            thread_type VARCHAR(32) NOT NULL,
            thread_id BIGINT UNSIGNED NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, thread_type, thread_id)
        ) $c;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( $sql as $stmt ) {
            dbDelta( $stmt );
        }
    }

    public function down(): void {
        // No-op. Schema migrations are forward-only in this project.
    }
};
