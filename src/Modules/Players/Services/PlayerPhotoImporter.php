<?php
namespace TT\Modules\Players\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Modules\Media\Storage\MediaStorage;

/**
 * Move one player photograph out of `wp-content/uploads/` and into the
 * private media store (#3399).
 *
 * Two callers, deliberately sharing one implementation: migration 0261,
 * which sweeps an existing academy, and the admin player form, which runs
 * the same import the moment an operator picks a new photo. A second copy
 * is how the migration would go on being right while new uploads quietly
 * went back to being public.
 *
 * The WordPress media picker is kept — it is what operators know, and it
 * already handles the awkward parts of getting a file off a phone. What
 * changes is what happens next: the bytes are copied into the private
 * store, the row points at that, and the public original is deleted.
 * Picking a photo through the library no longer leaves one there.
 */
final class PlayerPhotoImporter {

    /**
     * Import a public uploads URL for a player.
     *
     * Returns the new `tt_media` id, or 0 when the URL is not ours to move
     * (an off-install address), the file is unreadable, or it is not an
     * image. **Zero is not failure to be papered over** — the caller keeps
     * whatever it had, because a photo that cannot be moved must not also
     * be lost.
     *
     * @param int    $player_id      Player the photo belongs to.
     * @param string $url            Public URL, expected inside uploads/.
     * @param bool   $delete_original Remove the public copy once stored.
     */
    public static function fromUploadsUrl( int $player_id, string $url, bool $delete_original = true ): int {
        if ( $player_id <= 0 || $url === '' ) return 0;

        $uploads  = wp_get_upload_dir();
        $base_url = (string) ( $uploads['baseurl'] ?? '' );
        $base_dir = (string) ( $uploads['basedir'] ?? '' );
        if ( $base_url === '' || $base_dir === '' ) return 0;

        // Only files this install owns. A club CDN or a gravatar is not
        // ours to move, and deleting it would be worse than leaving it.
        if ( strpos( $url, $base_url ) !== 0 ) return 0;

        $relative = ltrim( substr( $url, strlen( $base_url ) ), '/' );
        $path     = $base_dir . '/' . $relative;

        // `..` in a stored URL should be impossible; checked because the
        // next line deletes what it resolves to.
        if ( $relative === '' || strpos( $relative, '..' ) !== false
            || ! is_file( $path ) || ! is_readable( $path ) ) {
            Logger::warning( 'player_photo.import.unreadable', [ 'player' => $player_id, 'path' => $relative ] );
            return 0;
        }

        $type = wp_check_filetype( $path );
        $mime = (string) ( $type['type'] ?? '' );
        $ext  = (string) ( $type['ext'] ?? '' );
        if ( $mime === '' || strpos( $mime, 'image/' ) !== 0 ) {
            Logger::warning( 'player_photo.import.not_an_image', [ 'player' => $player_id, 'mime' => $mime ] );
            return 0;
        }

        $storage = MediaStorage::default();
        $key     = $storage->store( $path, $ext );
        if ( $key === '' ) {
            Logger::error( 'player_photo.import.store_failed', [ 'player' => $player_id ] );
            return 0;
        }

        $size = @filesize( $path );
        $dim  = @getimagesize( $path );

        $media_id = ( new MediaRepository() )->insert( [
            'kind'            => MediaKind::IMAGE,
            'title'           => __( 'Player photo', 'talenttrack' ),
            'storage_adapter' => $storage->name(),
            'storage_key'     => $key,
            'mime_type'       => $mime,
            'file_size'       => $size !== false ? (int) $size : 0,
            'width'           => is_array( $dim ) ? (int) $dim[0] : null,
            'height'          => is_array( $dim ) ? (int) $dim[1] : null,
            'checksum'        => hash_file( 'sha256', $path ) ?: null,
        ] );

        if ( $media_id <= 0 ) {
            // Orphan the blob rather than leave a row pointing nowhere.
            $storage->delete( $key );
            Logger::error( 'player_photo.import.insert_failed', [ 'player' => $player_id ] );
            return 0;
        }

        ( new MediaLinksRepository() )->link( $media_id, MediaEntityType::PLAYER, $player_id, true );

        if ( $delete_original ) {
            // Last, and only once the store has the bytes: the public copy
            // is the defect, and it is also the only remaining copy until
            // this point.
            self::deletePublicCopy( $path );
        }

        return $media_id;
    }

    /**
     * Delete the uploads file and, when it is a registered attachment, the
     * attachment and its generated sizes with it.
     *
     * Deleting only the original would leave `-150x150.jpg` and friends
     * sitting at equally guessable paths — which is the same defect with a
     * suffix.
     */
    private static function deletePublicCopy( string $path ): void {
        $attachment_id = (int) attachment_url_to_postid(
            str_replace( (string) ( wp_get_upload_dir()['basedir'] ?? '' ), (string) ( wp_get_upload_dir()['baseurl'] ?? '' ), $path )
        );

        if ( $attachment_id > 0 ) {
            wp_delete_attachment( $attachment_id, true );
            return;
        }

        @unlink( $path );
    }
}
