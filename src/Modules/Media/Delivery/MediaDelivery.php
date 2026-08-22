<?php
namespace TT\Modules\Media\Delivery;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Storage\MediaStorage;

/**
 * MediaDelivery (#2592, epic #2589) — serves the bytes.
 *
 * **This is the access boundary.** The deny-all `.htaccess` on the media
 * directory only works on Apache; on nginx it does nothing at all. So on
 * a large share of installs this class is the only thing standing between
 * a photograph of a child and anyone who asks for it. It is written to be
 * read with that in mind.
 *
 * What that means in practice:
 *
 *   - The caller has already established *who may*. This class establishes
 *     *what is sent*, and refuses anything it cannot describe exactly.
 *   - `Content-Type` comes from the stored, whitelisted mime — never from
 *     sniffing the file at serve time, and never from the request.
 *   - Anything outside the inline-safe list is sent as an attachment, so a
 *     browser is never invited to render it in our origin.
 *   - Bytes are streamed in chunks. A 200MB match clip must not be read
 *     into PHP memory to be sent, and `readfile()` on a range is not an
 *     option anyway.
 *
 * Range support is not a nicety: mobile Safari refuses to seek — and in
 * practice refuses to play — a video served without it.
 */
final class MediaDelivery {

    public const VARIANT_FILE  = 'file';
    public const VARIANT_THUMB = 'thumb';

    /** Read/write chunk. Large enough to be cheap, small enough to bound memory. */
    private const CHUNK = 262144; // 256KB

    /**
     * Types a browser may render in our origin. Anything else downloads.
     * Video is inline because that is the whole point of a video tag;
     * both entries are formats with no scripting surface.
     */
    private const INLINE_SAFE = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'video/quicktime',
    ];

    /**
     * Decide the response. Returns a plan, or a WP_Error naming what is
     * wrong — never a partial plan.
     *
     * @param object      $media   Row from tt_media.
     * @param string      $variant self::VARIANT_*.
     * @param string|null $range   Raw `Range` header, if any.
     * @return MediaDeliveryPlan|\WP_Error
     */
    public static function plan( object $media, string $variant = self::VARIANT_FILE, ?string $range = null ) {
        $kind = (string) ( $media->kind ?? '' );

        if ( ! MediaKind::isStored( $kind ) ) {
            return new \WP_Error(
                'not_stored',
                __( 'That item is a link to video hosted elsewhere; there is no file to download.', 'talenttrack' ),
                [ 'status' => 409 ]
            );
        }

        $is_thumb = $variant === self::VARIANT_THUMB;
        $key      = $is_thumb ? (string) ( $media->thumbnail_key ?? '' ) : (string) ( $media->storage_key ?? '' );

        if ( $key === '' ) {
            return new \WP_Error(
                'no_file',
                $is_thumb
                    ? __( 'That item has no thumbnail.', 'talenttrack' )
                    : __( 'That item has no stored file.', 'talenttrack' ),
                [ 'status' => 404 ]
            );
        }

        $adapter_name = (string) ( $media->storage_adapter ?? '' );
        $storage      = MediaStorage::for( $adapter_name );

        if ( ! $storage->exists( $key ) ) {
            return new \WP_Error(
                'file_missing',
                __( 'The stored file could not be found.', 'talenttrack' ),
                [ 'status' => 404 ]
            );
        }

        $total = $storage->size( $key );
        if ( $total <= 0 ) {
            return new \WP_Error( 'file_empty', __( 'The stored file is empty.', 'talenttrack' ), [ 'status' => 404 ] );
        }

        // A thumbnail is always an image, whatever the item's own type is.
        $mime = $is_thumb ? 'image/jpeg' : (string) ( $media->mime_type ?? '' );
        if ( $is_thumb && ! empty( $media->mime_type ) && strpos( (string) $media->mime_type, 'image/' ) === 0 ) {
            $mime = (string) $media->mime_type;
        }

        $disposition = in_array( $mime, self::INLINE_SAFE, true )
            ? 'inline'
            : 'attachment; filename="' . self::downloadName( $media, $key ) . '"';

        // A thumbnail is small and always sent whole; ranging it buys
        // nothing and is one more thing to get wrong.
        $offsets = $is_thumb ? null : self::parseRange( $range, $total );

        if ( $offsets === 'unsatisfiable' ) {
            return new \WP_Error(
                'range_not_satisfiable',
                __( 'The requested byte range is outside the file.', 'talenttrack' ),
                [ 'status' => 416, 'total' => $total ]
            );
        }

        if ( $offsets === null ) {
            return new MediaDeliveryPlan( 200, $mime, $disposition, $key, $storage->name(), 0, $total - 1, $total );
        }

        [ $start, $end ] = $offsets;
        return new MediaDeliveryPlan( 206, $mime, $disposition, $key, $storage->name(), $start, $end, $total );
    }

    /**
     * Send a planned response and end the request.
     *
     * Ends in `exit`, following `ExportRestController` — the REST server
     * would otherwise JSON-encode whatever it got back and append it to
     * the bytes we just wrote.
     */
    public static function stream( MediaDeliveryPlan $plan ): void {
        $storage = MediaStorage::for( $plan->adapter );
        $handle  = $storage->readStream( $plan->key );

        if ( $handle === null ) {
            status_header( 404 );
            exit;
        }

        nocache_headers();
        status_header( $plan->status );
        foreach ( $plan->headers() as $name => $value ) {
            header( $name . ': ' . $value );
        }

        // Any buffered output would corrupt the body — and, with the JSON
        // envelope already started by the REST server, silently produce a
        // file no player can read.
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        if ( $plan->start > 0 ) {
            fseek( $handle, $plan->start );
        }

        $remaining = $plan->length();
        while ( $remaining > 0 && ! feof( $handle ) ) {
            $chunk = fread( $handle, (int) min( self::CHUNK, $remaining ) );
            if ( $chunk === false || $chunk === '' ) break;
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $remaining -= strlen( $chunk );
            flush();
        }

        fclose( $handle );
        exit;
    }

    /**
     * Parse a `Range` header.
     *
     * Returns null for "send the whole thing" (absent, malformed, or
     * multi-range — all of which a whole response legitimately answers),
     * the string `unsatisfiable` for a range that starts past the end, or
     * `[start, end]`.
     *
     * Multi-range is deliberately answered with the whole file rather than
     * a `multipart/byteranges` body: no player this feature serves asks
     * for one, and hand-rolling that encoding to serve a private
     * photograph is a poor trade.
     *
     * @return array{0:int,1:int}|string|null
     */
    public static function parseRange( ?string $range, int $total ) {
        if ( $range === null || $range === '' || $total <= 0 ) return null;
        if ( ! preg_match( '/^bytes=(.*)$/i', trim( $range ), $m ) ) return null;

        $spec = trim( $m[1] );
        if ( $spec === '' || strpos( $spec, ',' ) !== false ) return null;

        if ( ! preg_match( '/^(\d*)-(\d*)$/', $spec, $parts ) ) return null;

        $from = $parts[1];
        $to   = $parts[2];

        if ( $from === '' && $to === '' ) return null;

        if ( $from === '' ) {
            // Suffix range: the last N bytes. N of 0 asks for nothing,
            // which is not a range any response can satisfy.
            $suffix = (int) $to;
            if ( $suffix <= 0 ) return 'unsatisfiable';
            $start = max( 0, $total - $suffix );
            $end   = $total - 1;
            return [ $start, $end ];
        }

        $start = (int) $from;
        if ( $start >= $total ) return 'unsatisfiable';

        $end = $to === '' ? $total - 1 : (int) $to;
        if ( $end < $start ) return 'unsatisfiable';
        if ( $end > $total - 1 ) $end = $total - 1;

        return [ $start, $end ];
    }

    /**
     * Filename for an attachment response. Built from the item's title, or
     * the storage key's extension when there is nothing usable — never
     * from anything a request supplied.
     */
    private static function downloadName( object $media, string $key ): string {
        $title = sanitize_file_name( (string) ( $media->title ?? '' ) );
        $title = trim( str_replace( '"', '', $title ) );
        if ( $title === '' ) $title = 'media';

        $ext = strtolower( (string) pathinfo( $key, PATHINFO_EXTENSION ) );
        if ( $ext !== '' && substr( $title, -( strlen( $ext ) + 1 ) ) !== '.' . $ext ) {
            $title .= '.' . $ext;
        }

        return $title;
    }
}
