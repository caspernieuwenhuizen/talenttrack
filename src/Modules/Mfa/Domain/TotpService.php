<?php
namespace TT\Modules\Mfa\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TotpService — RFC 6238 Time-Based One-Time Password (#0086 Workstream B Child 1).
 *
 * Pure logic, no I/O. Generates the 6-digit TOTP for a given shared
 * secret + timestamp; verifies a user-submitted code against the
 * current step plus a ±1-step tolerance window (so a code generated
 * 30 seconds ago and a code that just rolled over both succeed).
 *
 * Inputs:
 *   - secret: a base32-encoded byte string (RFC 4648 alphabet, no
 *     padding). 20 bytes (160 bits) of entropy is the RFC default
 *     and what every authenticator app expects. The repository stores
 *     the same base32 string under `CredentialEncryption`.
 *   - timestamp: the Unix epoch seconds. Defaulted by callers to
 *     `time()` so unit tests can pin to a specific instant.
 *
 * Algorithm (faithful to RFC 6238):
 *   1. counter = floor(timestamp / 30)
 *   2. hmac    = HMAC-SHA1(decoded_secret, big-endian uint64(counter))
 *   3. offset  = hmac[19] & 0x0F
 *   4. binary  = (hmac[offset .. offset+3] & 0x7FFFFFFF)
 *   5. code    = binary mod 10^6, zero-padded to 6 digits
 *
 * The implementation is constant-time at the comparison step so a
 * timing oracle can't be used to brute-force the verifier.
 */
final class TotpService {

    /** Step duration in seconds (RFC 6238 default). */
    private const STEP_SECONDS = 30;

    /** Number of digits in the generated code. */
    private const CODE_DIGITS = 6;

    /** Tolerance in steps either side of the current step. ±1 means 90s window. */
    private const TOLERANCE_STEPS = 1;

    /** Algorithm — RFC 6238 specifies SHA1 as the default; every authenticator app supports it. */
    private const HMAC_ALG = 'sha1';

    /**
     * Generate a fresh shared secret. Returns a base32-encoded string
     * suitable for storage and for the otpauth:// URI consumed by
     * authenticator-app QR codes.
     *
     * 20 bytes / 160 bits — the RFC 6238 default. Authenticator apps
     * (Google Authenticator, Authy, 1Password, etc.) all expect this.
     */
    public static function generateSecret(): string {
        return self::base32Encode( random_bytes( 20 ) );
    }

    /**
     * Generate the current TOTP code for a given secret. Returns a
     * 6-digit numeric string, zero-padded.
     *
     * @param string $secret Base32-encoded shared secret.
     * @param int|null $timestamp Unix epoch seconds (default: now).
     * @return string Six-digit code (e.g. "082145").
     */
    public static function generate( string $secret, ?int $timestamp = null ): string {
        $timestamp = $timestamp ?? time();
        $counter   = (int) floor( $timestamp / self::STEP_SECONDS );
        return self::codeForCounter( $secret, $counter );
    }

    /**
     * Verify a user-submitted code against the current step + a
     * tolerance window. Returns true on first match, false if no step
     * within the window produces the code.
     *
     * Side-effects: none — caller is responsible for tracking
     * `last_verified_at`, incrementing `failed_attempts`, applying
     * `locked_until`. Sprint 3 wires those.
     *
     * @param string $secret Base32-encoded shared secret.
     * @param string $submitted_code The 6-digit code the user typed.
     * @param int|null $timestamp Unix epoch seconds (default: now).
     */
    public static function verify( string $secret, string $submitted_code, ?int $timestamp = null ): bool {
        // Strip whitespace from the user's input so "082 145" works as
        // well as "082145". Authenticator apps render with a space; users
        // copy-paste with the space included.
        $submitted_code = preg_replace( '/\s+/', '', $submitted_code );
        if ( $submitted_code === null || strlen( $submitted_code ) !== self::CODE_DIGITS ) {
            return false;
        }
        if ( ! ctype_digit( $submitted_code ) ) {
            return false;
        }

        $timestamp = $timestamp ?? time();
        $current   = (int) floor( $timestamp / self::STEP_SECONDS );

        for ( $offset = -self::TOLERANCE_STEPS; $offset <= self::TOLERANCE_STEPS; $offset++ ) {
            $candidate = self::codeForCounter( $secret, $current + $offset );
            if ( hash_equals( $candidate, $submitted_code ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the otpauth:// URI consumed by authenticator-app QR codes.
     *
     * Format (RFC + de-facto convention):
     *   otpauth://totp/<issuer>:<account>?secret=<base32>&issuer=<issuer>&algorithm=SHA1&digits=6&period=30
     *
     * The `issuer` prefix on the label and the `issuer=` query parameter
     * are both required for some authenticator apps to display the issuer
     * name correctly (Google Authenticator on iOS reads the prefix; 1Password
     * reads the query param).
     *
     * @param string $secret Base32-encoded shared secret.
     * @param string $account_label Usually the user's email — what the user sees in their authenticator app.
     * @param string $issuer The brand the user sees alongside the account (e.g. "TalentTrack — &lt;academy name&gt;").
     */
    public static function otpauthUri( string $secret, string $account_label, string $issuer ): string {
        $account = rawurlencode( $issuer ) . ':' . rawurlencode( $account_label );
        $params  = http_build_query( [
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => (string) self::CODE_DIGITS,
            'period'    => (string) self::STEP_SECONDS,
        ] );
        return 'otpauth://totp/' . $account . '?' . $params;
    }

    /**
     * The HOTP code for a specific counter value. Pulled out so
     * `verify()` can iterate the tolerance window.
     */
    private static function codeForCounter( string $secret, int $counter ): string {
        $key = self::base32Decode( $secret );
        if ( $key === '' ) return '';

        // Big-endian 64-bit counter. PHP doesn't have a uint64 type, so
        // pack the high and low 32 bits separately.
        $high   = ( $counter >> 32 ) & 0xFFFFFFFF;
        $low    = $counter & 0xFFFFFFFF;
        $packed = pack( 'NN', $high, $low );

        $hmac   = hash_hmac( self::HMAC_ALG, $packed, $key, true );
        $offset = ord( $hmac[ strlen( $hmac ) - 1 ] ) & 0x0F;

        $binary = ( ( ord( $hmac[ $offset ] ) & 0x7F ) << 24 )
            | ( ord( $hmac[ $offset + 1 ] ) << 16 )
            | ( ord( $hmac[ $offset + 2 ] ) << 8 )
            | ord( $hmac[ $offset + 3 ] );

        $code = (string) ( $binary % ( 10 ** self::CODE_DIGITS ) );
        return str_pad( $code, self::CODE_DIGITS, '0', STR_PAD_LEFT );
    }

    /**
     * Base32 encode (RFC 4648, no padding) — the encoding authenticator
     * apps consume.
     */
    private static function base32Encode( string $bytes ): string {
        if ( $bytes === '' ) return '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits     = '';
        for ( $i = 0; $i < strlen( $bytes ); $i++ ) {
            $bits .= str_pad( decbin( ord( $bytes[ $i ] ) ), 8, '0', STR_PAD_LEFT );
        }
        // Pad the bit-string up to a multiple of 5 (each base32 char is 5 bits).
        $bits = str_pad( $bits, (int) ( ceil( strlen( $bits ) / 5 ) * 5 ), '0', STR_PAD_RIGHT );

        $out = '';
        for ( $i = 0; $i < strlen( $bits ); $i += 5 ) {
            $out .= $alphabet[ bindec( substr( $bits, $i, 5 ) ) ];
        }
        return $out;
    }

    /**
     * Base32 decode. Returns '' when the input contains non-alphabet
     * characters; callers treat empty as "secret is corrupted".
     */
    private static function base32Decode( string $secret ): string {
        $secret = strtoupper( str_replace( ' ', '', $secret ) );
        if ( $secret === '' ) return '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        $bits = '';
        for ( $i = 0; $i < strlen( $secret ); $i++ ) {
            $pos = strpos( $alphabet, $secret[ $i ] );
            if ( $pos === false ) return '';
            $bits .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
        }

        $out = '';
        for ( $i = 0; $i + 8 <= strlen( $bits ); $i += 8 ) {
            $out .= chr( bindec( substr( $bits, $i, 8 ) ) );
        }
        return $out;
    }
}
