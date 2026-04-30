<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * InstallId — owns the per-install identifier used in the
 * phone-home protocol (#0065 / TTA #0001).
 *
 * Generated once on first read, stored in `wp_options:tt_install_id`,
 * stable across all subsequent pings. UUID v4 (RFC 4122) so two
 * different installs cannot collide and so the value carries no
 * meaning that could leak business data.
 *
 * The receiver re-derives the HMAC secret as
 *   hash('sha256', $install_id . '|' . $site_url)
 * so both values appear in every payload; neither is secret on
 * its own.
 */
final class InstallId {

    public const OPTION = 'tt_install_id';

    public static function get(): string {
        $current = get_option( self::OPTION, '' );
        if ( is_string( $current ) && self::isUuidV4( $current ) ) {
            return $current;
        }

        $fresh = self::generate();
        update_option( self::OPTION, $fresh, false );
        return $fresh;
    }

    /**
     * UUID v4. Uses random_bytes() so it works on every supported PHP
     * version (7.4+) without a runtime composer dep.
     */
    private static function generate(): string {
        $b = random_bytes( 16 );
        $b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
        $b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split( bin2hex( $b ), 4 )
        );
    }

    private static function isUuidV4( string $s ): bool {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $s
        );
    }
}
