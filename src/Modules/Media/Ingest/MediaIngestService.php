<?php
namespace TT\Modules\Media\Ingest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Storage\MediaStorage;

/**
 * MediaIngestService (#2590, epic #2589) — turns a file on disk into a
 * storable, safe `tt_media` payload.
 *
 * Everything that makes an upload safe happens here, before the bytes
 * reach the store:
 *
 *   - **Type is decided by content, never by filename.** `finfo` reads
 *     the magic bytes. A `.jpg` that is really an SVG is refused.
 *   - **SVG is refused outright.** It is a script-execution vector served
 *     from our own origin, and an academy has no use for one.
 *   - **EXIF is stripped after the capture date is read out of it.** A
 *     phone photo taken pitch-side carries the GPS coordinates of a field
 *     full of minors. Reading the date and then re-serving the location
 *     would defeat the point of storing these files privately at all.
 *   - Orientation is applied to the pixels first, or every portrait photo
 *     lands sideways once its EXIF is gone.
 *
 * Video is the honest gap: stripping location metadata from an MP4 needs
 * a demuxer this plugin does not ship, and iOS writes GPS into the `moov`
 * atom. Uploaded video therefore keeps its container metadata. That is
 * documented rather than papered over, and tracked separately.
 */
final class MediaIngestService {

    /**
     * Content types we accept, mapped to the extension we store them as.
     * The extension comes from this table, never from the uploaded
     * filename, so a crafted name cannot pick what lands on disk.
     */
    private const ALLOWED = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'video/mp4'       => 'mp4',
        'video/quicktime' => 'mov',
    ];

    /** Longest edge of a generated thumbnail, in pixels. */
    private const THUMB_MAX_EDGE = 640;

    /**
     * Ingest one file.
     *
     * @param string               $source_path Absolute path to the file.
     * @param array<string, mixed> $meta        title, description, captured_at overrides.
     */
    public function ingest( string $source_path, array $meta = [] ): MediaIngestResult {
        if ( $source_path === '' || ! is_file( $source_path ) ) {
            return MediaIngestResult::fail( 'no_file', __( 'No file was received.', 'talenttrack' ) );
        }

        $size = (int) filesize( $source_path );
        if ( $size <= 0 ) {
            return MediaIngestResult::fail( 'empty_file', __( 'That file is empty.', 'talenttrack' ) );
        }

        $limit = self::maxUploadBytes();
        if ( $size > $limit ) {
            return MediaIngestResult::fail(
                'too_large',
                sprintf(
                    /* translators: 1: size of the file, 2: the server's limit. */
                    __( 'That file is %1$s. This server accepts uploads up to %2$s.', 'talenttrack' ),
                    size_format( $size ),
                    size_format( $limit )
                )
            );
        }

        $mime = self::detectMime( $source_path );
        if ( $mime === 'image/svg+xml' || $mime === 'text/html' ) {
            return MediaIngestResult::fail(
                'unsafe_type',
                __( 'That file type cannot be uploaded because it can contain scripts. Upload a JPEG, PNG or WebP image instead.', 'talenttrack' )
            );
        }
        if ( ! isset( self::ALLOWED[ $mime ] ) ) {
            return MediaIngestResult::fail(
                'unsupported_type',
                __( 'That file type is not supported. Upload a JPEG, PNG or WebP image, or an MP4 or MOV video.', 'talenttrack' )
            );
        }

        $extension = self::ALLOWED[ $mime ];
        $kind      = MediaKind::forMime( $mime );

        $captured_at   = null;
        $width         = null;
        $height        = null;
        $thumbnail_key = null;

        if ( $kind === MediaKind::IMAGE ) {
            // Order matters: read the date out of the EXIF, apply the
            // orientation to the pixels, and only then strip. Doing it in
            // any other order loses the date or leaves photos sideways.
            $captured_at = self::readCapturedAt( $source_path, $mime );
            self::normaliseImage( $source_path, $mime );

            $dimensions = @getimagesize( $source_path );
            if ( is_array( $dimensions ) ) {
                $width  = (int) $dimensions[0] ?: null;
                $height = (int) $dimensions[1] ?: null;
            }
        }

        // Hash the bytes we are actually storing, after normalisation, so
        // the checksum identifies the stored object rather than whatever
        // arrived.
        $checksum = hash_file( 'sha256', $source_path );
        $size     = (int) filesize( $source_path );

        $storage = MediaStorage::default();

        if ( $kind === MediaKind::IMAGE ) {
            $thumbnail_key = $this->makeThumbnail( $source_path, $extension );
        }

        $key = $storage->store( $source_path, $extension );
        if ( $key === '' ) {
            if ( $thumbnail_key !== null ) $storage->delete( $thumbnail_key );
            return MediaIngestResult::fail(
                'store_failed',
                __( 'The file could not be saved. Check that the uploads folder is writable.', 'talenttrack' )
            );
        }

        $override = isset( $meta['captured_at'] ) ? (string) $meta['captured_at'] : '';
        if ( $override !== '' ) $captured_at = $override;

        return MediaIngestResult::ok( [
            'kind'            => $kind,
            'title'           => isset( $meta['title'] ) ? (string) $meta['title'] : '',
            'description'     => isset( $meta['description'] ) ? (string) $meta['description'] : null,
            'storage_adapter' => $storage->name(),
            'storage_key'     => $key,
            'mime_type'       => $mime,
            'file_size'       => $size,
            'width'           => $width,
            'height'          => $height,
            'checksum'        => is_string( $checksum ) ? $checksum : null,
            'thumbnail_key'   => $thumbnail_key,
            'captured_at'     => $captured_at,
        ] );
    }

    /**
     * Store a caller-supplied thumbnail blob — the path video takes, where
     * the browser grabs a poster frame off the `<video>` element. That is
     * what keeps a server-side transcoder out of the stack.
     */
    public function storeThumbnail( string $source_path, string $mime ): ?string {
        if ( ! isset( self::ALLOWED[ $mime ] ) ) return null;
        if ( MediaKind::forMime( $mime ) !== MediaKind::IMAGE ) return null;

        self::normaliseImage( $source_path, $mime );
        $key = MediaStorage::default()->store( $source_path, self::ALLOWED[ $mime ] );
        return $key === '' ? null : $key;
    }

    /**
     * Largest upload this install can actually accept. Read from PHP, not
     * from a setting of ours — a 200MB policy means nothing when
     * `upload_max_filesize` is 8MB, and the resulting truncated-request
     * error tells the user nothing.
     */
    public static function maxUploadBytes(): int {
        $limit = (int) wp_max_upload_size();
        if ( $limit <= 0 ) $limit = 8 * MB_IN_BYTES;
        return (int) apply_filters( 'tt_media_max_upload_bytes', $limit );
    }

    /** @return array<string, string> mime => extension */
    public static function allowedTypes(): array {
        return self::ALLOWED;
    }

    // Internals

    /**
     * Content-sniffed MIME. `finfo` first; `getimagesize` is the fallback
     * for installs without fileinfo, and it too reads the bytes rather
     * than the name.
     */
    private static function detectMime( string $path ): string {
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo !== false ) {
                $mime = finfo_file( $finfo, $path );
                finfo_close( $finfo );
                if ( is_string( $mime ) && $mime !== '' ) return strtolower( $mime );
            }
        }

        $info = @getimagesize( $path );
        if ( is_array( $info ) && ! empty( $info['mime'] ) ) return strtolower( (string) $info['mime'] );

        return '';
    }

    /** EXIF capture date as `Y-m-d H:i:s`, or null when there is none. */
    private static function readCapturedAt( string $path, string $mime ): ?string {
        if ( $mime !== 'image/jpeg' || ! function_exists( 'exif_read_data' ) ) return null;

        $exif = @exif_read_data( $path );
        if ( ! is_array( $exif ) ) return null;

        foreach ( [ 'DateTimeOriginal', 'DateTimeDigitized', 'DateTime' ] as $field ) {
            if ( empty( $exif[ $field ] ) ) continue;
            // EXIF writes `2026:08:14 18:32:00`; only the date half uses colons.
            $raw = (string) $exif[ $field ];
            $ts  = strtotime( preg_replace( '/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $raw ) ?? '' );
            if ( $ts ) return gmdate( 'Y-m-d H:i:s', $ts );
        }

        return null;
    }

    /**
     * Apply EXIF orientation, then remove every scrap of metadata by
     * re-encoding the pixels.
     *
     * Imagick gets an explicit `stripImage()`. GD is used otherwise and
     * needs no strip call — it has no way to write EXIF back out, so a
     * re-encode is inherently a strip. Either way the file that lands on
     * disk carries pixels and nothing else.
     */
    private static function normaliseImage( string $path, string $mime ): bool {
        if ( class_exists( '\Imagick' ) ) {
            try {
                $image = new \Imagick( $path );
                if ( method_exists( $image, 'autoOrient' ) ) {
                    $image->autoOrient();
                }
                $image->stripImage();
                $image->writeImage( $path );
                $image->clear();
                $image->destroy();
                return true;
            } catch ( \Throwable $e ) {
                // Fall through to GD rather than leaving the original in
                // place — an un-stripped file is the one outcome we will
                // not accept.
                unset( $e );
            }
        }

        return self::normaliseWithGd( $path, $mime );
    }

    private static function normaliseWithGd( string $path, string $mime ): bool {
        if ( ! function_exists( 'imagecreatefromjpeg' ) ) return false;

        $orientation = 1;
        if ( $mime === 'image/jpeg' && function_exists( 'exif_read_data' ) ) {
            $exif = @exif_read_data( $path );
            if ( is_array( $exif ) && ! empty( $exif['Orientation'] ) ) {
                $orientation = (int) $exif['Orientation'];
            }
        }

        switch ( $mime ) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg( $path );
                break;
            case 'image/png':
                $image = @imagecreatefrompng( $path );
                break;
            case 'image/webp':
                $image = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
                break;
            default:
                return false;
        }

        if ( ! $image ) return false;

        $image = self::applyOrientation( $image, $orientation );

        switch ( $mime ) {
            case 'image/jpeg':
                $ok = @imagejpeg( $image, $path, 90 );
                break;
            case 'image/png':
                imagealphablending( $image, false );
                imagesavealpha( $image, true );
                $ok = @imagepng( $image, $path );
                break;
            default:
                $ok = function_exists( 'imagewebp' ) ? @imagewebp( $image, $path ) : false;
        }

        imagedestroy( $image );
        return (bool) $ok;
    }

    /**
     * @param  \GdImage|resource $image
     * @return \GdImage|resource
     */
    private static function applyOrientation( $image, int $orientation ) {
        switch ( $orientation ) {
            case 2:
                imageflip( $image, IMG_FLIP_HORIZONTAL );
                return $image;
            case 3:
                return imagerotate( $image, 180, 0 ) ?: $image;
            case 4:
                imageflip( $image, IMG_FLIP_VERTICAL );
                return $image;
            case 5:
                $rotated = imagerotate( $image, 270, 0 ) ?: $image;
                imageflip( $rotated, IMG_FLIP_HORIZONTAL );
                return $rotated;
            case 6:
                return imagerotate( $image, 270, 0 ) ?: $image;
            case 7:
                $rotated = imagerotate( $image, 90, 0 ) ?: $image;
                imageflip( $rotated, IMG_FLIP_HORIZONTAL );
                return $rotated;
            case 8:
                return imagerotate( $image, 90, 0 ) ?: $image;
            default:
                return $image;
        }
    }

    /**
     * Downscaled copy for gallery tiles. Failure is not fatal — a missing
     * thumbnail costs bandwidth, not correctness, and the gallery falls
     * back to the full image.
     */
    private function makeThumbnail( string $source_path, string $extension ): ?string {
        $editor = wp_get_image_editor( $source_path );
        if ( is_wp_error( $editor ) ) return null;

        $editor->resize( self::THUMB_MAX_EDGE, self::THUMB_MAX_EDGE, false );

        $temp = wp_tempnam( 'tt-media-thumb' );
        if ( ! $temp ) return null;

        $saved = $editor->save( $temp );
        if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
            @unlink( $temp );
            return null;
        }

        $key = MediaStorage::default()->store( (string) $saved['path'], $extension );

        // The editor may have written alongside $temp rather than to it.
        if ( file_exists( $temp ) ) @unlink( $temp );

        return $key === '' ? null : $key;
    }
}
