<?php
namespace TT\Infrastructure\Identity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\CredentialEncryption;

/**
 * PhoneMeta — read/write chokepoint for the user-level phone number
 * (#0042). Phone is PII; stored encrypted via the same envelope as
 * the Spond URL (#0031) and SMTP password (#0013).
 *
 *   tt_phone           — encrypted E.164 string (envelope `tt:1:...`)
 *   tt_phone_hash      — sha256 of normalized E.164 (indexed lookup)
 *   tt_phone_verified_at — datetime, set when a push subscription
 *                          confirms the user owns the device the
 *                          number lives on (see PushSubscriptionsRepository)
 *
 * The plain value is only decrypted on read. REST responses and audit
 * payloads must never serialize the decrypted form — gate at the
 * controller layer; this helper does not enforce serialization rules.
 */
final class PhoneMeta {

    public const META_PHONE       = 'tt_phone';
    public const META_PHONE_HASH  = 'tt_phone_hash';
    public const META_VERIFIED_AT = 'tt_phone_verified_at';

    /**
     * Get the decrypted phone for a user, or empty string if none /
     * decrypt fails. An empty string means "no phone on file".
     */
    public static function get( int $user_id ): string {
        if ( $user_id <= 0 ) return '';
        $stored = (string) get_user_meta( $user_id, self::META_PHONE, true );
        if ( $stored === '' ) return '';
        return CredentialEncryption::decrypt( $stored );
    }

    /**
     * Persist a phone for a user. Empty input clears the meta and
     * the hash. Non-empty input must already be E.164-shaped — this
     * helper does not re-validate; the form / REST layer is the gate.
     */
    public static function set( int $user_id, string $phone ): void {
        if ( $user_id <= 0 ) return;
        $phone = self::normalize( $phone );
        if ( $phone === '' ) {
            self::clear( $user_id );
            return;
        }
        update_user_meta( $user_id, self::META_PHONE,      CredentialEncryption::encrypt( $phone ) );
        update_user_meta( $user_id, self::META_PHONE_HASH, hash( 'sha256', $phone ) );
    }

    /**
     * Remove the phone, hash, and verification timestamp. Used by
     * profile-edit "clear phone" and by GDPR exports / deletes.
     */
    public static function clear( int $user_id ): void {
        if ( $user_id <= 0 ) return;
        delete_user_meta( $user_id, self::META_PHONE );
        delete_user_meta( $user_id, self::META_PHONE_HASH );
        delete_user_meta( $user_id, self::META_VERIFIED_AT );
    }

    /**
     * Whether a user has a phone on file. Uses the hash so we don't
     * pay the decrypt cost for a presence check.
     */
    public static function exists( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        return (string) get_user_meta( $user_id, self::META_PHONE_HASH, true ) !== '';
    }

    /**
     * Mark the phone as verified-via-push. Idempotent. The timestamp
     * is the current site time (UTC stored, displayed in site tz).
     */
    public static function markVerified( int $user_id ): void {
        if ( $user_id <= 0 ) return;
        if ( ! self::exists( $user_id ) ) return;
        if ( (string) get_user_meta( $user_id, self::META_VERIFIED_AT, true ) !== '' ) return;
        update_user_meta( $user_id, self::META_VERIFIED_AT, current_time( 'mysql' ) );
    }

    /**
     * Whether the user's phone has been verified via push.
     */
    public static function isVerified( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        return (string) get_user_meta( $user_id, self::META_VERIFIED_AT, true ) !== '';
    }

    /**
     * Normalize raw form input to E.164. Strips spaces, dashes, and
     * parens; preserves a leading `+`. Returns empty string if the
     * result fails the loose E.164 shape check.
     */
    public static function normalize( string $raw ): string {
        $stripped = preg_replace( '/[\s\-()\.]/', '', trim( $raw ) ) ?? '';
        if ( $stripped === '' ) return '';
        if ( ! self::isValid( $stripped ) ) return '';
        return $stripped;
    }

    /**
     * Loose E.164 shape check — leading `+` optional, 7-15 digits,
     * first digit non-zero. Does not check country-code validity;
     * carriers will reject mis-shaped numbers at send time.
     */
    public static function isValid( string $candidate ): bool {
        return (bool) preg_match( '/^\+?[1-9]\d{6,14}$/', $candidate );
    }

    /**
     * Render a masked form of the number — leading country code +
     * last 4 digits, middle replaced with bullets. Used by the
     * profile-edit "phone on file" indicator without leaking the
     * full value into the page.
     */
    public static function masked( string $phone ): string {
        if ( $phone === '' ) return '';
        $len = strlen( $phone );
        if ( $len <= 6 ) return $phone;
        $head = substr( $phone, 0, 3 );
        $tail = substr( $phone, -4 );
        $mid  = str_repeat( '•', max( 0, $len - 7 ) );
        return $head . $mid . $tail;
    }
}
