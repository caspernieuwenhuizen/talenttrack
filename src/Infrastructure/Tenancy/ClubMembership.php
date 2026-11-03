<?php
namespace TT\Infrastructure\Tenancy;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ClubMembership (#4787) — which clubs an account belongs to, and which
 * accounts belong to a club.
 *
 * Derived from the club-scoped rows that already tie an account to a club:
 * a staff record (`tt_people.wp_user_id`), a player record
 * (`tt_players.wp_user_id`) and a guardian link
 * (`tt_player_parents.parent_user_id`), each read with its own `club_id`.
 * Never from `wp_usermeta` or `wp_options`, which are global to the install
 * and so cannot say which tenant an account is in (CLAUDE.md §4).
 *
 * Use this rather than a lookup of your own wherever a feature asks "is
 * this account part of this club". Answers are memoised for the request;
 * {@see flush()} drops them after a write that changes membership.
 */
final class ClubMembership {

    /** @var array<int, list<int>> user id => club ids */
    private static array $clubs_by_user = [];

    /** @var array<int, list<int>> club id => user ids */
    private static array $users_by_club = [];

    /**
     * The clubs an account belongs to, ascending. Empty for an account with
     * no staff, player or guardian row in any club.
     *
     * @return list<int>
     */
    public static function clubIdsForUser( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        if ( isset( self::$clubs_by_user[ $user_id ] ) ) return self::$clubs_by_user[ $user_id ];

        global $wpdb;
        $p   = $wpdb->prefix;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT club_id FROM {$p}tt_people WHERE wp_user_id = %d
             UNION SELECT club_id FROM {$p}tt_players WHERE wp_user_id = %d
             UNION SELECT club_id FROM {$p}tt_player_parents WHERE parent_user_id = %d",
            $user_id, $user_id, $user_id
        ) );

        self::$clubs_by_user[ $user_id ] = self::ids( $ids );
        return self::$clubs_by_user[ $user_id ];
    }

    /**
     * The accounts that belong to a club, ascending.
     *
     * @return list<int>
     */
    public static function userIdsForClub( int $club_id ): array {
        if ( $club_id <= 0 ) return [];
        if ( isset( self::$users_by_club[ $club_id ] ) ) return self::$users_by_club[ $club_id ];

        global $wpdb;
        $p   = $wpdb->prefix;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT wp_user_id FROM {$p}tt_people WHERE club_id = %d AND wp_user_id > 0
             UNION SELECT wp_user_id FROM {$p}tt_players WHERE club_id = %d AND wp_user_id > 0
             UNION SELECT parent_user_id FROM {$p}tt_player_parents WHERE club_id = %d AND parent_user_id > 0",
            $club_id, $club_id, $club_id
        ) );

        self::$users_by_club[ $club_id ] = self::ids( $ids );
        return self::$users_by_club[ $club_id ];
    }

    /** Forget the memoised answers, after a write that changes membership. */
    public static function flush(): void {
        self::$clubs_by_user = [];
        self::$users_by_club = [];
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function ids( $raw ): array {
        $ids = array_values( array_unique( array_filter(
            array_map( 'intval', is_array( $raw ) ? $raw : [] ),
            static fn ( int $id ): bool => $id > 0
        ) ) );
        sort( $ids );
        return $ids;
    }
}
