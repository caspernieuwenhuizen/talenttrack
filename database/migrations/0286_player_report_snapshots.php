<?php
/**
 * Migration 0286 — frozen player reports (#3890, epic #3871).
 *
 * A snapshot is a record of what was put in front of a player: which evidence,
 * over which window, on which date, and what the conversation noted against
 * it. The live report is a view of current data — print it on Tuesday, talk on
 * Thursday, and a register taken in between moves the numbers under the
 * conversation — so the snapshot stores the rendered report, not a reference.
 *
 * Same shape as `tt_team_report_snapshots` (0267) and for the same reasons:
 * `composition_json` says what the document is, `data_json` is the payload as
 * composed, `notes_json` is one note per section, editable after the data is
 * frozen. No share-token column, deliberately: a player report is the densest
 * record of one child's development the product produces, and a URL that works
 * for whoever holds it is the wrong shape at any expiry.
 *
 * `player_id` makes a right-to-erasure delete reach these rows without a
 * cascade edit: `PlayerDeletionCascade` sweeps every `tt_*` table carrying one.
 *
 * `club_id` + `uuid` per CLAUDE.md §4; the uuid is the public identifier.
 * Idempotent: CREATE TABLE IF NOT EXISTS. Forward-only. Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0286_player_report_snapshots';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_player_report_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            player_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            period_from DATE NOT NULL,
            period_to DATE NOT NULL,
            composition_json LONGTEXT,
            data_json LONGTEXT,
            notes_json LONGTEXT,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_player (club_id, player_id, archived_at, created_at)
        ) {$charset};" );
    }
};
