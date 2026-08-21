<?php
/**
 * Migration 0218 — media library foundation (#2590, epic #2589).
 *
 * Two tables:
 *
 *   tt_media        one uploaded file or one external video link. The
 *                   root entity, so it carries `uuid` — that is the
 *                   identity the REST surface addresses and the one that
 *                   survives a SaaS migration. The autoincrement `id` is
 *                   an implementation detail of this database.
 *   tt_media_links  what the media is attached to: a player, a team or
 *                   an activity. Polymorphic on (entity_type, entity_id)
 *                   rather than three nullable FK columns, because one
 *                   session photo legitimately belongs to the activity
 *                   AND to each player in frame, and a link row per
 *                   attachment is what lets a single upload surface on
 *                   four records.
 *
 * `storage_key` is deliberately opaque — not a URL, not a path. The
 * storage adapter is the only thing that knows how to turn it into
 * bytes, which is what keeps swapping LocalPrivateStorage for object
 * storage a one-class change rather than a grep across every query.
 * Nothing outside the adapter may parse it.
 *
 * `captured_at` is separate from `created_at` on purpose. A coach empties
 * their camera roll weeks after the session; sorting a player's media by
 * upload time would put November at the top of an August story. The
 * player's journey is chronological by when the thing happened, so the
 * EXIF capture date is a first-class column and the sort key.
 *
 * `archived_at` is present from the start so media slots into the
 * archive → trash → purge lifecycle (epic #2018) rather than needing a
 * retrofit once rows exist.
 *
 * Per CLAUDE.md §4 both tables carry club_id and the root entity carries
 * uuid, both unused while the plugin is single-tenant and both what
 * separates an easy SaaS migration from rewriting every query.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0218_media_foundation';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // One media item. `kind` decides which half of the row is
        // meaningful: image/video use the storage columns, video_link uses
        // provider + external_url and never touches the filesystem.
        //
        // checksum is sha256 of the stored bytes. A coach attaching the
        // same team photo to twelve players should be detectable as one
        // file, not twelve; the column is what makes that possible without
        // re-hashing the whole store.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_media (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            kind VARCHAR(16) NOT NULL DEFAULT 'image',
            title VARCHAR(255) NOT NULL DEFAULT '',
            description TEXT NULL,
            storage_adapter VARCHAR(32) NOT NULL DEFAULT 'local_private',
            storage_key VARCHAR(255) NOT NULL DEFAULT '',
            mime_type VARCHAR(100) NOT NULL DEFAULT '',
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            width SMALLINT UNSIGNED DEFAULT NULL,
            height SMALLINT UNSIGNED DEFAULT NULL,
            duration_seconds INT UNSIGNED DEFAULT NULL,
            checksum CHAR(64) DEFAULT NULL,
            thumbnail_key VARCHAR(255) DEFAULT NULL,
            provider VARCHAR(32) DEFAULT NULL,
            external_url VARCHAR(1000) DEFAULT NULL,
            captured_at DATETIME DEFAULT NULL,
            uploaded_by BIGINT UNSIGNED DEFAULT NULL,
            archived_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_active (club_id, archived_at),
            KEY idx_club_kind (club_id, kind, archived_at),
            KEY idx_club_checksum (club_id, checksum),
            KEY idx_club_captured (club_id, captured_at),
            KEY idx_uploader (uploaded_by)
        ) $charset;" );

        // The attachment. UNIQUE on (media_id, entity_type, entity_id) so
        // attaching the same item to the same record twice is a no-op
        // rather than a duplicate tile in the gallery.
        //
        // is_primary lives here, not on tt_media: "primary" is a property
        // of the attachment, not of the file. The same team photo can be
        // the primary shot for the team and an ordinary one for each
        // player in it.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_media_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            media_id BIGINT UNSIGNED NOT NULL,
            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_link (media_id, entity_type, entity_id),
            KEY idx_entity (club_id, entity_type, entity_id, sort_order),
            KEY idx_media (media_id),
            KEY idx_entity_primary (club_id, entity_type, entity_id, is_primary)
        ) $charset;" );
    }
};
