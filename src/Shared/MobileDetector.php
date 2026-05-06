<?php
namespace TT\Shared;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MobileDetector — server-side phone-class user-agent detection (#0084 Child 1).
 *
 * The `desktop_only` mobile classification (`MobileSurfaceRegistry`) is gated
 * by this detector. Conservative on purpose: only confirmed phone-class user
 * agents return true. **Tablets are NOT mobile in this classification** —
 * they get the desktop UI per the spec's locked decision (iPad-Safari users
 * told us they want the laptop-equivalent UX). Users with phablets or in-
 * between devices who genuinely want the cramped desktop view on a phone
 * have the `?force_mobile=1` URL escape hatch.
 *
 * Detection sources, in order of preference:
 *   1. `Sec-CH-UA-Mobile` client hint header (`?1` → mobile, `?0` → not).
 *      Modern Chromium-based browsers send this cleanly.
 *   2. User-Agent string regex match for known phone tokens (`Mobi`,
 *      `iPhone`, `Android` excluding `Tablet`, etc.).
 *
 * The detector is purely server-side. Client-side responsive CSS is
 * independent — a tablet or small laptop window still gets the responsive
 * treatment, just not the desktop-only redirect.
 *
 * Pure / stateless — every call rereads the headers. Safe to call multiple
 * times per request.
 */
final class MobileDetector {

    /**
     * Whether the current request is from a phone-class user agent.
     *
     * Tablets, desktops, screen readers, and bots return `false`.
     * Cooperates with `?force_mobile=1` callers indirectly — that flag is
     * applied in the dispatcher, not here, so this method is a clean
     * "what is this device, really?" answer.
     */
    public static function isPhone(): bool {
        // Client hint takes precedence — when present and explicit, trust it.
        if ( isset( $_SERVER['HTTP_SEC_CH_UA_MOBILE'] ) ) {
            $hint = trim( (string) $_SERVER['HTTP_SEC_CH_UA_MOBILE'] );
            if ( $hint === '?1' ) return true;
            if ( $hint === '?0' ) return false;
        }

        $ua = self::userAgent();
        if ( $ua === '' ) return false;

        // Tablet exclusions first — `Tablet` and `iPad` are not phones.
        if ( stripos( $ua, 'iPad' ) !== false ) return false;
        if ( stripos( $ua, 'Tablet' ) !== false ) return false;
        if ( stripos( $ua, 'Kindle' ) !== false ) return false;
        if ( stripos( $ua, 'PlayBook' ) !== false ) return false;
        // Android tablets that don't include "Mobi" in their UA string fall
        // out via the positive-match below: an Android UA string must contain
        // both `Android` AND `Mobile` to count as a phone (Android device-team
        // convention since Honeycomb).
        if ( stripos( $ua, 'Android' ) !== false ) {
            return stripos( $ua, 'Mobile' ) !== false;
        }

        // iPhone, iPod, BlackBerry, Windows Phone, generic mobile UAs.
        $phone_tokens = [ 'iPhone', 'iPod', 'BlackBerry', 'BB10', 'IEMobile', 'Windows Phone', 'Mobi' ];
        foreach ( $phone_tokens as $token ) {
            if ( stripos( $ua, $token ) !== false ) return true;
        }

        return false;
    }

    /**
     * The raw User-Agent string for the current request, sanitised.
     */
    public static function userAgent(): string {
        if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) return '';
        return (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] );
    }

    /**
     * Whether the current request explicitly opts out of the desktop-only
     * gate via `?force_mobile=1`. The gate respects this — power users on
     * phablets who genuinely want the cramped desktop view still get it.
     *
     * Logged in `tt_audit_log` by the dispatcher when used (for "is the
     * classification wrong on this surface?" review).
     */
    public static function userForcedMobile(): bool {
        if ( ! isset( $_GET['force_mobile'] ) ) return false;
        $val = (string) $_GET['force_mobile'];
        return $val === '1' || strtolower( $val ) === 'true';
    }
}
