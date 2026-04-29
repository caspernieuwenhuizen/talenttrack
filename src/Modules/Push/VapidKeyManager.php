<?php
namespace TT\Modules\Push;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\CredentialEncryption;

/**
 * VapidKeyManager — generate + read the install's VAPID keypair.
 *
 * VAPID (RFC 8292) is the application-server identity layer for the
 * Web Push protocol. The browser stores the public key when it
 * subscribes; every outbound push is signed with the private key so
 * the push service can verify the sender. Without VAPID, push
 * services either silently drop messages or rate-limit hard.
 *
 * Keys are P-256 ECDSA. Public is the uncompressed 65-byte point
 * (`0x04 || X || Y`); private is the 32-byte scalar `d`. The public
 * is shipped raw to the browser via `wp_localize_script`; the private
 * never leaves PHP and is encrypted at rest via CredentialEncryption.
 *
 * Idempotent: `ensureKeys()` is a no-op once a pair exists. To
 * rotate, delete the two options and re-run — every active
 * subscription will then fail with HTTP 401 and prune naturally.
 */
final class VapidKeyManager {

    private const OPT_PUBLIC  = 'tt_vapid_public';
    private const OPT_PRIVATE = 'tt_vapid_private';
    private const OPT_SUBJECT = 'tt_vapid_subject';

    /**
     * Ensure a VAPID keypair exists for this install. Idempotent.
     * Returns true on success — false means the host doesn't have
     * the EC primitives compiled into OpenSSL.
     */
    public static function ensureKeys(): bool {
        if ( self::publicKey() !== '' ) return true;
        return self::generate();
    }

    /**
     * Generate a fresh keypair, overwriting any existing values.
     * Returns false on hosts that lack EC support — in which case
     * push remains disabled and the caller falls back to email.
     */
    public static function generate(): bool {
        if ( ! function_exists( 'openssl_pkey_new' ) ) return false;

        $res = openssl_pkey_new( [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ] );
        if ( ! $res ) return false;

        $details = openssl_pkey_get_details( $res );
        if ( ! is_array( $details ) || ! isset( $details['ec']['x'], $details['ec']['y'], $details['ec']['d'] ) ) {
            return false;
        }

        $x = self::pad( (string) $details['ec']['x'], 32 );
        $y = self::pad( (string) $details['ec']['y'], 32 );
        $d = self::pad( (string) $details['ec']['d'], 32 );
        if ( $x === '' || $y === '' || $d === '' ) return false;

        $public_raw  = "\x04" . $x . $y;
        $private_raw = $d;

        update_option( self::OPT_PUBLIC,  self::base64UrlEncode( $public_raw ),  false );
        update_option( self::OPT_PRIVATE, CredentialEncryption::encrypt( $private_raw ), false );

        if ( get_option( self::OPT_SUBJECT, '' ) === '' ) {
            $admin_email = (string) get_option( 'admin_email', '' );
            $subject     = $admin_email !== '' ? 'mailto:' . $admin_email : home_url( '/' );
            update_option( self::OPT_SUBJECT, $subject, false );
        }

        return true;
    }

    /**
     * Base64url-encoded public key — embed in the page for
     * `pushManager.subscribe({ applicationServerKey })`.
     */
    public static function publicKey(): string {
        return (string) get_option( self::OPT_PUBLIC, '' );
    }

    /**
     * Raw 32-byte private scalar. Empty string when no key exists or
     * the install rotated `wp_salt('auth')` after key generation.
     */
    public static function privateKeyRaw(): string {
        $stored = (string) get_option( self::OPT_PRIVATE, '' );
        if ( $stored === '' ) return '';
        $plain = CredentialEncryption::decrypt( $stored );
        return $plain;
    }

    /**
     * VAPID `sub` claim — `mailto:` URI or site URL. Stored on first
     * key generation; admins can override via the option directly.
     */
    public static function subject(): string {
        $sub = (string) get_option( self::OPT_SUBJECT, '' );
        if ( $sub !== '' ) return $sub;
        $admin = (string) get_option( 'admin_email', '' );
        return $admin !== '' ? 'mailto:' . $admin : home_url( '/' );
    }

    public static function hasKeys(): bool {
        return self::publicKey() !== '' && self::privateKeyRaw() !== '';
    }

    public static function base64UrlEncode( string $raw ): string {
        return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
    }

    public static function base64UrlDecode( string $encoded ): string {
        $padded = strtr( $encoded, '-_', '+/' );
        $remainder = strlen( $padded ) % 4;
        if ( $remainder ) $padded .= str_repeat( '=', 4 - $remainder );
        $decoded = base64_decode( $padded, true );
        return $decoded === false ? '' : $decoded;
    }

    /**
     * Left-pad a binary string to a fixed length (and trim leading
     * zeros if the input is too long — OpenSSL occasionally returns
     * an extra zero byte on big-endian conversions).
     */
    private static function pad( string $bin, int $length ): string {
        if ( strlen( $bin ) > $length ) {
            $bin = substr( $bin, -$length );
        }
        return str_pad( $bin, $length, "\x00", STR_PAD_LEFT );
    }
}
