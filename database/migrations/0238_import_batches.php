<?php
/**
 * Migration 0238 — `tt_import_batches` + `tt_import_tags` (#2956, epic #2954).
 *
 * The Excel importer records every row it creates so the import can be
 * accounted for and, later, undone. Until now the only place to record
 * that was `tt_demo_tags`, which is DemoData's — and that is a trap rather
 * than a convenience.
 *
 * WHY SEPARATE TABLES RATHER THAN A `kind` COLUMN ON tt_demo_tags
 *
 * `DemoDataCleaner::wipeData( null, null )` resolves ids straight out of
 * `tt_demo_tags` with no batch filter and deletes them. Tag a club's real
 * squad there and a routine "wipe demo data" deletes the club's actual
 * players — silently, and with no way back short of a database restore.
 *
 * A `kind` column would work only for as long as every present and future
 * cleaner query remembers to filter on it, and the all-batches wipe above
 * is precisely the kind of query that forgets. With the real rows in their
 * own tables the demo cleaner cannot reach them at all: there is no filter
 * to omit, so there is no way to get it wrong later. `DemoDataCleaner`
 * needs no change as a result — if it ever does, that is the signal the
 * separation has leaked.
 *
 * Both tables carry `club_id` for the tenancy scaffold, and the batch
 * carries a `uuid` (CLAUDE.md §4). Counts are stored on the batch as JSON
 * so the import history can list what arrived without recounting tags.
 *
 * Additive and idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0238_import_batches';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_import_batches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            batch_key VARCHAR(64) NOT NULL,
            source_filename VARCHAR(255) NOT NULL DEFAULT '',
            counts_json TEXT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_club_batch_key (club_id, batch_key),
            KEY idx_club_created (club_id, created_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_import_tags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            batch_key VARCHAR(64) NOT NULL,
            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            extra_json TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_batch_type (club_id, batch_key, entity_type),
            KEY idx_entity (club_id, entity_type, entity_id)
        ) {$charset};" );
    }
};
