<?php
namespace TT\Infrastructure\Tenancy;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ClubIteration — how unattended work (a cron tick listener, a deferred
 * drain) walks every club. `clubIds()` enumerates the clubs; `withClub()`
 * pins `CurrentClub::id()` for one of them and restores it afterwards.
 * The one place to change when clubs are enumerated or pinned differently.
 */
final class ClubIteration {

    /**
     * Every club that has config rows, club 1 always included.
     *
     * @return list<int>
     */
    public static function clubIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT DISTINCT club_id FROM {$wpdb->prefix}tt_config" );
        $ids = array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) );
        if ( ! in_array( 1, $ids, true ) ) {
            $ids[] = 1;
        }
        return array_values( array_filter( $ids, static function ( int $id ): bool { return $id > 0; } ) );
    }

    /**
     * Run `$fn` with `tt_current_club_id` pinned to `$club_id`. The pin is
     * removed in a `finally`, so the previous club is current again whether
     * `$fn` returns or throws.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function withClub( int $club_id, callable $fn ) {
        $filter = static function () use ( $club_id ) { return $club_id; };
        add_filter( 'tt_current_club_id', $filter, 9999 );
        try {
            return $fn();
        } finally {
            remove_filter( 'tt_current_club_id', $filter, 9999 );
        }
    }
}
