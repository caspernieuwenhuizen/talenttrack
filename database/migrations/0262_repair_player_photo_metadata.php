<?php
/**
 * Migration 0262 — repair the metadata on photos migrated by 0261 (#3421).
 *
 * `PlayerPhotoImporter` read the file's size, dimensions and checksum
 * *after* handing it to the storage adapter, and `store()` moves the file
 * rather than copying it. So every photo migration 0261 moved got a
 * `tt_media` row with `file_size` 0, NULL dimensions and NULL checksum.
 *
 * The bytes are intact — only the row describing them is wrong — so this
 * recomputes from the stored blob rather than asking anyone to re-upload.
 *
 * SCOPE IS DELIBERATELY NARROW
 *
 * Only rows linked to a player, with a storage key, and with a NULL
 * checksum. A NULL checksum is the fingerprint of the bug: `MediaIngest`,
 * the ordinary upload path, has always computed one before storing. So
 * this cannot touch a correctly-ingested row, and it is a no-op on an
 * install that never had a player photo to migrate — which at the time of
 * writing is every install, the fix having landed one release after the
 * cause.
 *
 * Forward-only, idempotent (a repaired row gains a checksum and stops
 * matching). Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\Storage\MediaStorage;

return new class extends Migration {

    public function getName(): string {
        return '0262_repair_player_photo_metadata';
    }

    public function up(): void {
        global $wpdb;
        $media_table = $wpdb->prefix . 'tt_media';
        $links_table = $wpdb->prefix . 'tt_media_links';

        foreach ( [ $media_table, $links_table ] as $t ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
                return;
            }
        }

        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id, m.storage_adapter, m.storage_key
               FROM {$wpdb->prefix}tt_media m
               JOIN {$wpdb->prefix}tt_media_links l ON l.media_id = m.id
              WHERE l.entity_type = %s
                AND m.checksum IS NULL
                AND m.storage_key != ''",
            MediaEntityType::PLAYER
        ) );
        if ( $rows === [] ) return;

        $repaired = 0;
        $skipped  = 0;

        foreach ( $rows as $row ) {
            $adapter = MediaStorage::for( (string) $row->storage_adapter );
            $key     = (string) $row->storage_key;

            if ( ! $adapter->exists( $key ) ) {
                $skipped++;
                continue;
            }

            $stream = $adapter->readStream( $key );
            if ( ! is_resource( $stream ) ) {
                $skipped++;
                continue;
            }
            $bytes = stream_get_contents( $stream );
            fclose( $stream );

            if ( ! is_string( $bytes ) || $bytes === '' ) {
                $skipped++;
                continue;
            }

            $fields = [
                'file_size' => strlen( $bytes ),
                'checksum'  => hash( 'sha256', $bytes ),
            ];

            // getimagesizefromstring() rather than a temp file: the bytes
            // are already in hand and a private blob should not be written
            // back out to somewhere less private to be measured.
            $dim = @getimagesizefromstring( $bytes );
            if ( is_array( $dim ) ) {
                $fields['width']  = (int) $dim[0];
                $fields['height'] = (int) $dim[1];
            }

            $wpdb->update( $media_table, $fields, [ 'id' => (int) $row->id ] );
            $repaired++;
        }

        Logger::info( 'player_photo.metadata.repaired', [ 'repaired' => $repaired, 'skipped' => $skipped ] );
    }
};
