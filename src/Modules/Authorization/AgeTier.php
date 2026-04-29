<?php
namespace TT\Modules\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * AgeTier — bucket a player's date of birth into one of four contact-
 * strategy tiers (#0042). Lives next to PersonaResolver because the
 * tier feeds dispatcher selection alongside persona.
 *
 *   u8_u10   — age <= 9   parent is the only direct contact surface
 *   u11_u12  — age 10-11  player phone (PWA push) + parent email
 *   u12_plus — age >= 12  player email + push on top
 *   unknown  — no birthdate on record
 *
 * Boundaries are calendar age in completed years; football-year
 * granularity is good enough for choosing a notification channel.
 * Coaches / staff / scouts always resolve to `unknown` — this helper
 * is only meaningful for player records.
 */
final class AgeTier {

    public const U8_U10   = 'u8_u10';
    public const U11_U12  = 'u11_u12';
    public const U12_PLUS = 'u12_plus';
    public const UNKNOWN  = 'unknown';

    /**
     * Resolve an age tier from a date string. Empty / unparseable
     * input returns `unknown`. Future dates also return `unknown` —
     * we won't pretend to bucket a player born tomorrow.
     */
    public static function fromBirthdate( ?string $dob ): string {
        if ( $dob === null || $dob === '' ) return self::UNKNOWN;
        $ts = strtotime( $dob );
        if ( $ts === false ) return self::UNKNOWN;
        $now = current_time( 'timestamp' );
        if ( $ts > $now ) return self::UNKNOWN;
        $age = (int) floor( ( $now - $ts ) / 31557600 ); // 365.25d
        if ( $age < 0 ) return self::UNKNOWN;
        if ( $age <= 9 )  return self::U8_U10;
        if ( $age <= 11 ) return self::U11_U12;
        return self::U12_PLUS;
    }

    /**
     * Resolve an age tier for a player record. Returns `unknown` if
     * no player row matches the id or the row carries no birthdate.
     */
    public static function forPlayer( int $player_id ): string {
        if ( $player_id <= 0 ) return self::UNKNOWN;
        global $wpdb;
        $dob = $wpdb->get_var( $wpdb->prepare(
            "SELECT date_of_birth FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d LIMIT 1",
            $player_id, CurrentClub::id()
        ) );
        return self::fromBirthdate( $dob === null ? null : (string) $dob );
    }

    /**
     * Resolve an age tier for the player record linked to a WP user,
     * if any. Returns `unknown` for users who are not players.
     */
    public static function forUser( int $user_id ): string {
        if ( $user_id <= 0 ) return self::UNKNOWN;
        global $wpdb;
        $dob = $wpdb->get_var( $wpdb->prepare(
            "SELECT date_of_birth FROM {$wpdb->prefix}tt_players WHERE wp_user_id = %d AND club_id = %d LIMIT 1",
            $user_id, CurrentClub::id()
        ) );
        return self::fromBirthdate( $dob === null ? null : (string) $dob );
    }

    /**
     * Whether the tier indicates the player has no direct contact
     * surface — used by invitation flows and notification routing
     * to hand everything off to the linked parent.
     */
    public static function parentOnly( string $tier ): bool {
        return $tier === self::U8_U10;
    }

    /**
     * Whether the tier should default to PWA push as the primary
     * channel (still falls back to email per the configured chain).
     */
    public static function pushPrimary( string $tier ): bool {
        return $tier === self::U11_U12 || $tier === self::U12_PLUS;
    }

    /**
     * @return array<string,string> machine key => translated label
     */
    public static function labels(): array {
        return [
            self::U8_U10   => __( 'U8 – U10 (parent contact)', 'talenttrack' ),
            self::U11_U12  => __( 'U11 – U12 (phone / push)', 'talenttrack' ),
            self::U12_PLUS => __( 'U12+ (email + push)',      'talenttrack' ),
            self::UNKNOWN  => __( 'Unknown',                  'talenttrack' ),
        ];
    }
}
