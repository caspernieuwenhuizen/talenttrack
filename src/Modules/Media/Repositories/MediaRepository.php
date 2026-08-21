<?php
namespace TT\Modules\Media\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Storage\MediaStorage;

/**
 * MediaRepository (#2590, epic #2589) — reads and writes `tt_media`.
 *
 * Every query is club-scoped (CLAUDE.md §4). Today that is always `1`;
 * writing it now is what keeps a second tenant from being a rewrite.
 *
 * Ordering is `captured_at DESC` with a `created_at` fallback, everywhere,
 * without exception. A player's media is a chronological story about the
 * player, not a log of when a coach got round to uploading — sorting by
 * upload time would put an August session below a November one purely
 * because of when the camera roll was emptied.
 */
final class MediaRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_media';
    }

    private function linksTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_media_links';
    }

    /** Sort applied to every listing. See the class docblock. */
    private const ORDER = ' ORDER BY COALESCE(m.captured_at, m.created_at) DESC, m.id DESC';

    public function find( int $id ): ?object {
        global $wpdb;
        if ( $id <= 0 ) return null;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d AND club_id = %d",
            $id,
            CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Look up by uuid — the identity the REST surface exposes. Sequential
     * ids are not addressable from outside on purpose.
     */
    public function findByUuid( string $uuid ): ?object {
        global $wpdb;
        if ( $uuid === '' ) return null;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE uuid = %s AND club_id = %d",
            $uuid,
            CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Media attached to one record.
     *
     * @return object[]
     */
    public function listForEntity( string $entity_type, int $entity_id, bool $include_archived = false ): array {
        global $wpdb;
        if ( ! MediaEntityType::isValid( $entity_type ) || $entity_id <= 0 ) return [];

        $archived = $include_archived ? '' : ' AND m.archived_at IS NULL';

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, l.id AS link_id, l.is_primary, l.sort_order
               FROM {$this->table()} m
               INNER JOIN {$this->linksTable()} l ON l.media_id = m.id
              WHERE l.entity_type = %s
                AND l.entity_id = %d
                AND " . QueryHelpers::clubScopeWhere( 'm' ) . "
                AND " . QueryHelpers::clubScopeWhere( 'l' ) . $archived . self::ORDER,
            $entity_type,
            $entity_id
        ) );
    }

    /** The item marked primary for a record, or the most recent one. */
    public function primaryForEntity( string $entity_type, int $entity_id ): ?object {
        global $wpdb;
        if ( ! MediaEntityType::isValid( $entity_type ) || $entity_id <= 0 ) return null;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.*, l.id AS link_id, l.is_primary
               FROM {$this->table()} m
               INNER JOIN {$this->linksTable()} l ON l.media_id = m.id
              WHERE l.entity_type = %s
                AND l.entity_id = %d
                AND " . QueryHelpers::clubScopeWhere( 'm' ) . "
                AND " . QueryHelpers::clubScopeWhere( 'l' ) . "
                AND m.archived_at IS NULL
              ORDER BY l.is_primary DESC, COALESCE(m.captured_at, m.created_at) DESC, m.id DESC
              LIMIT 1",
            $entity_type,
            $entity_id
        ) );

        return $row ?: null;
    }

    /**
     * Rows already holding these bytes, so a duplicate upload can be
     * recognised instead of silently doubling the disk footprint.
     *
     * @return object[]
     */
    public function findByChecksum( string $checksum ): array {
        global $wpdb;
        if ( $checksum === '' ) return [];
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE checksum = %s AND club_id = %d AND archived_at IS NULL",
            $checksum,
            CurrentClub::id()
        ) );
    }

    /**
     * Insert a row. `$payload` is what MediaIngestService produced, or a
     * hand-built payload for a `video_link`.
     *
     * @param array<string, mixed> $payload
     * @return int New id, or 0 on failure.
     */
    public function insert( array $payload ): int {
        global $wpdb;

        $kind = isset( $payload['kind'] ) ? (string) $payload['kind'] : MediaKind::IMAGE;
        if ( ! MediaKind::isValid( $kind ) ) return 0;

        $row = [
            'uuid'            => wp_generate_uuid4(),
            'kind'            => $kind,
            'title'           => isset( $payload['title'] ) ? (string) $payload['title'] : '',
            'description'     => isset( $payload['description'] ) ? $payload['description'] : null,
            'storage_adapter' => isset( $payload['storage_adapter'] ) ? (string) $payload['storage_adapter'] : '',
            'storage_key'     => isset( $payload['storage_key'] ) ? (string) $payload['storage_key'] : '',
            'mime_type'       => isset( $payload['mime_type'] ) ? (string) $payload['mime_type'] : '',
            'file_size'       => isset( $payload['file_size'] ) ? (int) $payload['file_size'] : 0,
            'width'           => isset( $payload['width'] ) ? $payload['width'] : null,
            'height'          => isset( $payload['height'] ) ? $payload['height'] : null,
            'duration_seconds' => isset( $payload['duration_seconds'] ) ? $payload['duration_seconds'] : null,
            'checksum'        => isset( $payload['checksum'] ) ? $payload['checksum'] : null,
            'thumbnail_key'   => isset( $payload['thumbnail_key'] ) ? $payload['thumbnail_key'] : null,
            'provider'        => isset( $payload['provider'] ) ? $payload['provider'] : null,
            'external_url'    => isset( $payload['external_url'] ) ? $payload['external_url'] : null,
            'captured_at'     => isset( $payload['captured_at'] ) ? $payload['captured_at'] : null,
            'uploaded_by'     => isset( $payload['uploaded_by'] ) ? (int) $payload['uploaded_by'] : get_current_user_id(),
        ];

        $ok = $wpdb->insert( $this->table(), array_merge( $row, QueryHelpers::clubScopeInsertColumn() ) );
        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Update the operator-editable fields. Storage columns are not
     * updatable — replacing the bytes means a new row, so a link that
     * pointed at one photo can never silently start pointing at another.
     *
     * @param array<string, mixed> $fields
     */
    public function update( int $id, array $fields ): bool {
        global $wpdb;
        if ( $id <= 0 ) return false;

        $allowed = [ 'title', 'description', 'captured_at' ];
        $data    = [];
        foreach ( $allowed as $key ) {
            if ( array_key_exists( $key, $fields ) ) $data[ $key ] = $fields[ $key ];
        }
        if ( $data === [] ) return false;

        return false !== $wpdb->update(
            $this->table(),
            $data,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    /** Soft-archive. Bytes are kept — purge is the lifecycle's job. */
    public function archive( int $id ): bool {
        global $wpdb;
        if ( $id <= 0 ) return false;
        return false !== $wpdb->update(
            $this->table(),
            [ 'archived_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    public function restore( int $id ): bool {
        global $wpdb;
        if ( $id <= 0 ) return false;
        return false !== $wpdb->update(
            $this->table(),
            [ 'archived_at' => null ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Remove the row, its links, and its bytes.
     *
     * Bytes go last and through the adapter the row was written with, not
     * the current default — an install that has since switched adapters
     * must still be able to delete what the old one wrote. Deleting the
     * row before the blob would strand the file with nothing pointing at
     * it, which is exactly the orphan this method exists to prevent.
     */
    public function deleteWithBlobs( int $id ): bool {
        global $wpdb;

        $media = $this->find( $id );
        if ( ! $media ) return false;

        if ( MediaKind::isStored( (string) $media->kind ) ) {
            $storage = MediaStorage::for( (string) $media->storage_adapter );
            if ( ! empty( $media->storage_key ) )   $storage->delete( (string) $media->storage_key );
            if ( ! empty( $media->thumbnail_key ) ) $storage->delete( (string) $media->thumbnail_key );
        }

        $wpdb->delete( $this->linksTable(), [ 'media_id' => $id ] );
        return false !== $wpdb->delete( $this->table(), [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
    }

    /**
     * Total bytes this club is holding. Self-hosted video fills a disk
     * quietly; something has to be able to report the number.
     */
    public function totalBytes(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(file_size), 0) FROM {$this->table()} WHERE club_id = %d",
            CurrentClub::id()
        ) );
    }
}
