<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CredentialEncryption — symmetric envelope for at-rest secrets.
 *
 * Used wherever a bearer credential lands in the database — Spond iCal
 * URLs (#0031), SMTP passwords (#0013), license keys (#0011 may adopt
 * later, optional). The envelope is `tt:1:<base64(iv|ciphertext|tag)>`
 * — a one-byte version prefix lets us migrate the cipher later without
 * touching call sites.
 *
 * Key derivation is `wp_salt('auth')`. Anyone with shell access to the
 * install can decrypt; anyone holding only a DB dump cannot. That's the
 * intended threat model for backups + staging clones.
 */
final class CredentialEncryption {

    private const VERSION   = 1;
    private const CIPHER    = 'aes-256-gcm';
    private const IV_BYTES  = 12;
    private const TAG_BYTES = 16;
    private const PREFIX    = 'tt:';

    /**
     * Encrypt a plaintext credential. Returns the envelope string, or
     * an empty string when the input is empty (so call sites don't have
     * to special-case empty values before storing).
     */
    public static function encrypt( string $plaintext ): string {
        if ( $plaintext === '' ) return '';

        $iv     = random_bytes( self::IV_BYTES );
        $tag    = '';
        $cipher = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::deriveKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES
        );
        if ( $cipher === false ) return '';

        return self::PREFIX . self::VERSION . ':' . base64_encode( $iv . $cipher . $tag );
    }

    /**
     * Decrypt an envelope back to plaintext. Returns an empty string on
     * any decode / verify failure; callers treat empty as "credential
     * not available". A plaintext value that isn't enveloped (legacy
     * data or operator-edited row) is returned as-is.
     */
    public static function decrypt( string $envelope ): string {
        if ( $envelope === '' ) return '';

        // Forward-compat: an unenveloped value (e.g. a row edited by
        // hand) is returned as-is. Calling code that needs strict
        // decryption can check `isEnveloped()` first.
        if ( ! self::isEnveloped( $envelope ) ) return $envelope;

        $body = substr( $envelope, strlen( self::PREFIX . self::VERSION . ':' ) );
        $raw  = base64_decode( $body, true );
        if ( $raw === false || strlen( $raw ) <= self::IV_BYTES + self::TAG_BYTES ) {
            return '';
        }

        $iv     = substr( $raw, 0, self::IV_BYTES );
        $tag    = substr( $raw, -self::TAG_BYTES );
        $cipher = substr( $raw, self::IV_BYTES, -self::TAG_BYTES );

        $plain = openssl_decrypt(
            $cipher,
            self::CIPHER,
            self::deriveKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        return $plain === false ? '' : $plain;
    }

    /**
     * Whether a stored value is in our envelope format. Useful when
     * migrating legacy plaintext rows or for diagnostic UIs.
     */
    public static function isEnveloped( string $value ): bool {
        return strncmp( $value, self::PREFIX . self::VERSION . ':', strlen( self::PREFIX . self::VERSION . ':' ) ) === 0;
    }

    /**
     * Stable 32-byte key derived from the install's `wp_salt('auth')`.
     * If the salt rotates, existing envelopes become undecryptable —
     * that's intentional and matches the WP convention for any
     * salt-derived secret.
     */
    private static function deriveKey(): string {
        return hash( 'sha256', (string) wp_salt( 'auth' ), true );
    }
}
