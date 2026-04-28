<?php
namespace TT\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PhotoInliner — replaces `<img src="…">` URLs with `data:` URIs.
 *
 * #0014 Sprint 5. Used on scout-emailed reports so the rendered HTML
 * is fully self-contained — recipients never need to fetch from the
 * site's uploads directory, and the link page survives broken hosting.
 */
final class PhotoInliner {

    /**
     * Walk the HTML once and inline every `src=…` that points at the
     * site's own uploads dir. External images (CDN, Gravatar, etc.)
     * are left alone — embedding random outbound assets would defeat
     * the self-contained promise.
     */
    public static function inline( string $html ): string {
        $upload = wp_get_upload_dir();
        $base_url = (string) ( $upload['baseurl'] ?? '' );
        $base_dir = (string) ( $upload['basedir'] ?? '' );
        if ( $base_url === '' || $base_dir === '' ) return $html;

        return (string) preg_replace_callback(
            '#<img\s+([^>]*?)src=(["\'])([^"\']+)\2([^>]*)>#i',
            static function ( $m ) use ( $base_url, $base_dir ): string {
                $url   = (string) $m[3];
                $other = (string) $m[1] . (string) $m[4];
                if ( strpos( $url, 'data:' ) === 0 ) {
                    // Already inlined.
                    return (string) $m[0];
                }
                if ( strpos( $url, $base_url ) !== 0 ) {
                    return (string) $m[0];
                }
                $relative = ltrim( substr( $url, strlen( $base_url ) ), '/' );
                $path     = $base_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
                if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                    return (string) $m[0];
                }
                $bytes = (string) file_get_contents( $path );
                if ( $bytes === '' ) {
                    return (string) $m[0];
                }
                $mime = self::mimeFor( $path );
                $b64  = base64_encode( $bytes );
                $data = 'data:' . $mime . ';base64,' . $b64;
                return '<img ' . trim( $other ) . ' src="' . esc_attr( $data ) . '">';
            },
            $html
        );
    }

    private static function mimeFor( string $path ): string {
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        switch ( $ext ) {
            case 'jpg':
            case 'jpeg': return 'image/jpeg';
            case 'png':  return 'image/png';
            case 'gif':  return 'image/gif';
            case 'webp': return 'image/webp';
            case 'svg':  return 'image/svg+xml';
            default:     return 'application/octet-stream';
        }
    }
}
