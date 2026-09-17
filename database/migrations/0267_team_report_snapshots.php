<?php
/**
 * Migration 0267 — frozen monthly reports for a staff meeting (#3517, epic
 * #3513).
 *
 * The monthly report is a view of current data. Open it on the 3rd, discuss it
 * on the 5th, and a register taken in between has moved the numbers under the
 * discussion — so what the meeting decided cannot be reproduced afterwards.
 *
 * A snapshot stores the **rendered report** rather than a reference to the data
 * behind it. `data_json` is the block payload exactly as it was composed;
 * reopening the snapshot next season shows what the meeting actually saw, with
 * no query against today's numbers.
 *
 * WHY THE COMPOSITION IS STORED ALONGSIDE THE DATA
 *
 * `composition_json` records which team, window, layout and sections produced
 * it — enough to say what the document *is* without interpreting the payload,
 * and enough to re-render it through the same blocks. A snapshot therefore
 * survives a section being added to or removed from the block vocabulary later:
 * it renders what it stored, and a block it has never heard of simply is not in
 * the payload.
 *
 * WHY NOTES LIVE HERE AND NOT ON THE LIVE REPORT
 *
 * `notes_json` is one typed note per section — what was said about attendance,
 * about the players needing a conversation. It belongs to the snapshot because
 * the live report is a view of current data and commentary on a moving number
 * has nothing to attach to. The notes stay editable after the snapshot is
 * taken while the data does not: staff draft before the meeting and record what
 * was decided during it. Each note carries who last wrote it and when.
 *
 * WHY THERE IS NO SHARE TOKEN COLUMN
 *
 * Deliberately absent, and not an oversight to be corrected later. A monthly
 * report names every player in a squad and carries their attendance, their test
 * readings and who needs attention — the densest collection of information
 * about minors this product produces. A URL that works for whoever holds it is
 * the wrong shape for that at any expiry, so a snapshot is reachable only by a
 * signed-in reader holding the capability for that team's reports. Match prep
 * and match analysis have share links; this deliberately does not.
 *
 * `club_id` + `uuid` per CLAUDE.md §4. The uuid is the public identifier — the
 * URL carries it rather than the autoincrement id, so snapshot ids are not
 * guessable by counting. Idempotent: CREATE TABLE IF NOT EXISTS. Forward-only.
 * Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0267_team_report_snapshots';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_team_report_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            team_id BIGINT UNSIGNED NOT NULL,
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
            KEY idx_team (club_id, team_id, archived_at, period_to),
            KEY idx_recent (club_id, archived_at, created_at)
        ) {$charset};" );
    }
};
