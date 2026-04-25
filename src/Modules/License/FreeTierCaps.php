<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FreeTierCaps — numeric caps for free tier.
 *
 * Decision (Q2): 1 team / 25 players / unlimited evaluations. The
 * caps catch abuse and signal scale; feature gates do the conversion
 * lifting. Numbers are filterable (`tt_free_tier_cap_<type>`) so
 * Casper can tune without a release.
 *
 * Cap checks query active rows only (archived rows don't count) and
 * skip demo-mode rows so a customer in demo mode isn't capped on
 * fake data.
 */
class FreeTierCaps {

    public const CAP_TEAMS   = 'teams';
    public const CAP_PLAYERS = 'players';

    public static function teamCap(): int {
        return (int) apply_filters( 'tt_free_tier_cap_teams', 1 );
    }

    public static function playerCap(): int {
        return (int) apply_filters( 'tt_free_tier_cap_players', 25 );
    }

    /**
     * @param string $cap_type 'teams' | 'players'
     */
    public static function isAtCap( string $cap_type ): bool {
        $count = self::currentCount( $cap_type );
        $cap   = self::capFor( $cap_type );
        return $count >= $cap;
    }

    public static function currentCount( string $cap_type ): int {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $cap_type === self::CAP_TEAMS ) {
            return (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$p}tt_teams t
                 WHERE t.archived_at IS NULL"
            );
        }
        if ( $cap_type === self::CAP_PLAYERS ) {
            return (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$p}tt_players pl
                 WHERE pl.status = 'active' AND pl.archived_at IS NULL"
            );
        }
        return 0;
    }

    public static function capFor( string $cap_type ): int {
        if ( $cap_type === self::CAP_TEAMS )   return self::teamCap();
        if ( $cap_type === self::CAP_PLAYERS ) return self::playerCap();
        return PHP_INT_MAX;
    }
}
