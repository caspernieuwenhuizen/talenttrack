<?php
/**
 * Migration 0307 — Report Studio storage (#4569, epic #4564).
 *
 * Four new tables, and the matrix rows for the two new entities.
 *
 * `tt_reports` is one report or dashboard: the starting choices a builder made
 * (kind, scope, binding, audience, period, layout, sharing), its name, its
 * owner and the version recipients read. It holds no definition of its own:
 * every builder's working copy is a `tt_report_drafts` row, the owner's
 * included, so two people editing one shared report never overwrite each
 * other. The subject is typed, `player_id` or `team_id`, never one
 * polymorphic id: `PlayerDeletionCascade` finds tables by their `player_id`
 * column and `CascadeRegistry` finds team references by `team_id`, and a
 * column called `subject_id` would escape both. A group stores a cohort rule
 * (`cohort_json`: team ids plus an age group or a position), never a list of
 * player ids, for the same reason. `archived_by` / `trashed_*` are the
 * recycle-bin lifecycle `ArchiveRepository` stamps.
 *
 * `tt_report_drafts` is one row per builder per report. `revision` is the
 * optimistic lock a save compares against, so a second tab of the same builder
 * cannot silently overwrite the first.
 *
 * `tt_report_versions` is what publishing freezes. `UNIQUE (report_id,
 * version_no)` is what stops two concurrent publishes both writing version 3.
 *
 * `tt_report_publications` says who a report is published to, by persona key
 * (`PersonaResolver`), never a WordPress role name. Family sharing and
 * scheduled mail are later phases and reuse the snapshot and scheduled-report
 * tables, so nothing here stores them.
 *
 * Every table carries `club_id`; reports and versions carry a `uuid` as their
 * public identifier (CLAUDE.md §4).
 *
 * The matrix top-up writes the seed's `report_studio` and `report_publishing`
 * rows with INSERT IGNORE, so a row an admin has edited is never touched and a
 * second run writes nothing. Forward-only. Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /** The matrix entities this migration may write. */
    private const ENTITIES = [ 'report_studio', 'report_publishing' ];

    public function getName(): string {
        return '0307_report_studio_storage';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            kind VARCHAR(16) NOT NULL DEFAULT 'report',
            scope VARCHAR(16) NOT NULL DEFAULT 'me',
            binding VARCHAR(16) NOT NULL DEFAULT 'fixed',
            audience VARCHAR(16) NOT NULL DEFAULT 'internal',
            period_mode VARCHAR(16) NOT NULL DEFAULT 'on_open',
            period_value VARCHAR(32) NOT NULL DEFAULT '',
            layout VARCHAR(8) NOT NULL DEFAULT 'rows',
            sharing VARCHAR(16) NOT NULL DEFAULT 'me',
            name VARCHAR(255) NOT NULL DEFAULT '',
            player_id BIGINT UNSIGNED DEFAULT NULL,
            team_id BIGINT UNSIGNED DEFAULT NULL,
            cohort_json TEXT,
            owner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            published_version_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME DEFAULT NULL,
            archived_by BIGINT UNSIGNED DEFAULT NULL,
            trashed_at DATETIME DEFAULT NULL,
            trashed_by BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_owner (club_id, owner_user_id, archived_at),
            KEY idx_kind (club_id, kind, archived_at),
            KEY idx_player (club_id, player_id),
            KEY idx_team (club_id, team_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_report_drafts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            report_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            definition_json LONGTEXT,
            revision INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_report_user (report_id, user_id),
            KEY idx_club (club_id, user_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_report_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            report_id BIGINT UNSIGNED NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            definition_json LONGTEXT,
            note VARCHAR(500) NOT NULL DEFAULT '',
            published_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_report_version (report_id, version_no),
            KEY idx_club (club_id, report_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_report_publications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            report_id BIGINT UNSIGNED NOT NULL,
            persona_key VARCHAR(64) NOT NULL,
            published_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_report_persona (report_id, persona_key),
            KEY idx_club_persona (club_id, persona_key)
        ) {$charset};" );

        foreach ( [ 'tt_reports', 'tt_report_drafts', 'tt_report_versions', 'tt_report_publications' ] as $table ) {
            $name = $p . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) {
                throw new \RuntimeException( 'Migration statement failed: creating ' . $table . ' — ' . $wpdb->last_error );
            }
        }

        $this->report( $this->topUpMatrix(), 'report_studio and report_publishing matrix rows' );
    }

    /** The seed's rows for the two new entities, INSERT IGNORE. @return int rows written */
    private function topUpMatrix(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return 0;

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) return 0;
        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) return 0;

        $written = 0;
        foreach ( $rows as $row ) {
            if ( ! in_array( (string) ( $row['entity'] ?? '' ), self::ENTITIES, true ) ) continue;
            $written += $this->exec( $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (persona, entity, activity, scope_kind, module_class, is_default)
                 VALUES (%s, %s, %s, %s, %s, 1)",
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        if ( class_exists( '\\TT\\Modules\\Authorization\\Matrix\\MatrixRepository' ) ) {
            \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        }
        return $written;
    }
};
