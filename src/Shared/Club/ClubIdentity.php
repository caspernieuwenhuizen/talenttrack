<?php
namespace TT\Shared\Club;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * ClubIdentity — single source of truth for the club's short-form
 * identity tokens (the 3-letter club code used on score-box labels,
 * scoreboards, exports).
 *
 * Introduced in v4.12.11 (#1024) because the match-execution score
 * box was deriving the "home" abbreviation from each team's name
 * ("Hedel JO14-1" → `HJ`), which is wrong: the home team in a
 * club's score box represents the club, not the per-age-group
 * squad, so every match should show `HED` for vv Hedel regardless
 * of which JO-team is playing.
 *
 * Source of truth: `tt_config['club_short_code']`. Falls back to a
 * 3-letter derivation from `tt_config['academy_name']` (strips
 * common Dutch club prefixes `vv `, `sv `, `rkvv `, `fc ` etc.)
 * when the operator has not set an explicit code.
 *
 * Also the home of the club's full name (#3662): the trial letters
 * were reading a `club_name` config key nothing ever writes, so every
 * letterhead carried the WordPress site title instead of the academy
 * the family is actually joining.
 */
final class ClubIdentity {

    /** @var string|null */
    private static $cached = null;

    /**
     * The club's full name, as it should appear on anything a family
     * reads: trial letters, reminder mails, report headers.
     *
     * `tt_config['academy_name']` is where the setup wizard and the
     * Configuration view both store it. The WordPress site title is a
     * fallback for an install whose operator never filled the field in
     * — it names the software installation, not the academy, so it is
     * the last resort rather than the source.
     */
    public static function name(): string {
        $configured = trim( (string) QueryHelpers::get_config( 'academy_name', '' ) );
        if ( $configured !== '' ) {
            return $configured;
        }
        $site = trim( (string) get_bloginfo( 'name' ) );
        if ( $site !== '' ) {
            return $site;
        }
        return __( 'The club', 'talenttrack' );
    }

    /**
     * Three-letter uppercase short code for the club. Used as the
     * home-team abbreviation on the match-execution score box.
     */
    public static function shortCode(): string {
        if ( self::$cached !== null ) {
            return self::$cached;
        }
        $stored = trim( (string) QueryHelpers::get_config( 'club_short_code', '' ) );
        if ( $stored !== '' ) {
            return self::$cached = mb_strtoupper( mb_substr( $stored, 0, 3 ) );
        }
        $derived = self::deriveFromName( (string) QueryHelpers::get_config( 'academy_name', '' ) );
        if ( $derived === '' ) {
            // Last-ditch fallback when the operator has not configured a
            // club name at all. Site title is the WP-level name; better
            // than rendering an em-dash on the scoreboard.
            $derived = self::deriveFromName( (string) get_bloginfo( 'name' ) );
        }
        return self::$cached = ( $derived !== '' ? $derived : __( 'HOM', 'talenttrack' ) );
    }

    /**
     * Clear the per-request cache. Useful from the Configuration
     * save handler so an operator change is visible immediately
     * without a full page reload.
     */
    public static function flushCache(): void {
        self::$cached = null;
    }

    /**
     * Three-letter uppercase abbreviation for an OPPONENT's name — the
     * label above the away side of any score box.
     *
     * Strips common age-group / team-number suffixes (`JO13`, `U14`, `-1`)
     * before deriving, so `Den Helder JO13` → `DEH` rather than `DEN13`.
     * Falls back to a localised `OPP` placeholder when the name is empty:
     * a score box never renders an unreadable em-dash.
     *
     * #3530 — moved here from `FrontendMatchExecutionView`, which had it
     * as a private helper. The Result card on the match page needs the
     * same abbreviation, and two derivations of one label is how the live
     * sheet and the record come to disagree about who they are describing.
     */
    public static function abbreviateOpponent( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) {
            return __( 'OPP', 'talenttrack' );
        }
        // Strip age-group / team-number suffixes (JO13, U14, O19, MO14, -1, etc.)
        // so `Den Helder JO13` derives from `Den Helder`, not the suffix.
        $name = preg_replace( '/\s*(?:JO|MO|O|U|-)\s*\d+\s*$/iu', '', $name );
        $name = (string) preg_replace( '/-\d+$/u', '', (string) $name );
        $name = trim( (string) $name );
        if ( $name === '' ) {
            return __( 'OPP', 'talenttrack' );
        }
        // Strip punctuation, split on whitespace.
        $clean = preg_replace( '/[^\p{L}\p{N}\s]/u', '', $name );
        $parts = preg_split( '/\s+/', (string) $clean, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) || count( $parts ) === 0 ) {
            return __( 'OPP', 'talenttrack' );
        }
        if ( count( $parts ) === 1 ) {
            return mb_strtoupper( mb_substr( $parts[0], 0, 3 ) );
        }
        // Two or more parts: take the first letter of each of the first
        // three significant parts (or pad from the last word's letters).
        $abbr = '';
        foreach ( $parts as $part ) {
            $abbr .= mb_substr( $part, 0, 1 );
            if ( mb_strlen( $abbr ) >= 3 ) break;
        }
        if ( mb_strlen( $abbr ) < 3 ) {
            // Pad from the last word so two-word names like "Den Helder"
            // come out as `DEH` rather than `DE`.
            $last = $parts[ count( $parts ) - 1 ];
            $abbr .= mb_substr( $last, 1, 3 - mb_strlen( $abbr ) );
        }
        return mb_strtoupper( $abbr );
    }

    /**
     * Strip common Dutch / English club prefixes (`vv `, `sv `, `fc `,
     * `rkvv `, `rkc `, `vvv `, `ac `) so `vv Hedel` derives to `HED`
     * rather than `VVH`. Returns up to three uppercase letters from
     * the remaining significant words.
     */
    private static function deriveFromName( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) {
            return '';
        }
        // Strip a leading club-type prefix word (case-insensitive).
        $name = (string) preg_replace(
            '/^(?:vv|sv|fc|ac|rkvv|rkc|vvv|ev|ovv|sc|asv|usv|csv)\s+/iu',
            '',
            $name
        );
        $name = trim( $name );
        if ( $name === '' ) {
            return '';
        }
        // Strip punctuation, split on whitespace.
        $clean = preg_replace( '/[^\p{L}\p{N}\s]/u', '', $name );
        $parts = preg_split( '/\s+/', (string) $clean, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) || count( $parts ) === 0 ) {
            return '';
        }
        if ( count( $parts ) === 1 ) {
            return mb_strtoupper( mb_substr( $parts[0], 0, 3 ) );
        }
        $abbr = '';
        foreach ( $parts as $part ) {
            $abbr .= mb_substr( $part, 0, 1 );
            if ( mb_strlen( $abbr ) >= 3 ) break;
        }
        if ( mb_strlen( $abbr ) < 3 ) {
            $first = $parts[0];
            $abbr .= mb_substr( $first, 1, 3 - mb_strlen( $abbr ) );
        }
        return mb_strtoupper( $abbr );
    }
}
