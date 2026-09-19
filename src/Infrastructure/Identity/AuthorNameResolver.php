<?php
namespace TT\Infrastructure\Identity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * AuthorNameResolver (#3672) — the name to show beside something a WP
 * account wrote.
 *
 * An account's `display_name` is whatever WordPress was told at sign-up.
 * It is not the academy's name for the human behind it: linking an
 * existing account to a player never renamed the account, and renaming a
 * player never touched it either. A player posting on their own goal
 * thread could therefore appear under a stranger's name, which is worse
 * than no name at all — a parent reading the thread cannot tell which
 * messages are their child's.
 *
 * So the record wins over the account. `tt_players.id` is the identity
 * and `wp_user_id` is only a mapping to one authentication backend
 * (CLAUDE.md § 4); the account keeps its own `display_name` untouched.
 *
 * Order: the linked player, then the linked person (staff, parents),
 * then `display_name`, then ''. Resolution is batched — a thread of
 * twenty messages from three authors costs two table reads and one
 * primed user cache, not twenty lookups.
 */
final class AuthorNameResolver {

    /**
     * Names for many accounts at once.
     *
     * Ids that resolve to nothing are omitted, so a caller can tell
     * "no name" from "empty name" and keep its own fallback.
     *
     * @param  list<int>          $user_ids
     * @return array<int,string>  wp_user_id => display name
     */
    public static function namesFor( array $user_ids ): array {
        $ids = array_values( array_unique( array_filter(
            array_map( 'intval', $user_ids ),
            static function ( int $id ): bool { return $id > 0; }
        ) ) );
        if ( $ids === [] ) return [];

        $names = self::playerNames( $ids );

        $remaining = array_values( array_diff( $ids, array_keys( $names ) ) );
        if ( $remaining !== [] ) {
            $names += self::personNames( $remaining );
        }

        $remaining = array_values( array_diff( $ids, array_keys( $names ) ) );
        if ( $remaining !== [] ) {
            $names += self::accountNames( $remaining );
        }

        return $names;
    }

    /**
     * The name for one account, or '' when nothing resolves.
     *
     * Convenience for a single-message path; anything rendering a list
     * calls {@see self::namesFor()} once instead.
     */
    public static function nameFor( int $user_id ): string {
        $names = self::namesFor( [ $user_id ] );
        return (string) ( $names[ $user_id ] ?? '' );
    }

    /**
     * Linked player rows, in this club.
     *
     * Archived and released players are included on purpose: an old
     * message keeps the author it was written by, and a player leaving
     * the academy must not rewrite the history of their own goal
     * conversation. Trashed rows are excluded — those are on their way
     * out of the database.
     *
     * @param  list<int> $ids
     * @return array<int,string>
     */
    private static function playerNames( array $ids ): array {
        global $wpdb;
        $in = implode( ',', array_map( 'intval', $ids ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids are cast to int above.
        $rows = $wpdb->get_results(
            "SELECT wp_user_id, first_name, last_name
               FROM {$wpdb->prefix}tt_players
              WHERE wp_user_id IN ({$in})
                AND " . QueryHelpers::clubScopeWhere() . "
                AND trashed_at IS NULL",
            ARRAY_A
        );

        return self::collect( is_array( $rows ) ? $rows : [] );
    }

    /**
     * Linked person rows, in this club. Active rows win: an inactive
     * row keeps its `wp_user_id` and must not outrank the live link.
     *
     * @param  list<int> $ids
     * @return array<int,string>
     */
    private static function personNames( array $ids ): array {
        global $wpdb;
        $in = implode( ',', array_map( 'intval', $ids ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids are cast to int above.
        $rows = $wpdb->get_results(
            "SELECT wp_user_id, first_name, last_name
               FROM {$wpdb->prefix}tt_people
              WHERE wp_user_id IN ({$in})
                AND " . QueryHelpers::clubScopeWhere() . "
              ORDER BY ( status = 'active' ) DESC, id ASC",
            ARRAY_A
        );

        return self::collect( is_array( $rows ) ? $rows : [] );
    }

    /**
     * The accounts' own `display_name`, the last resort.
     *
     * `cache_users()` primes the whole set in one query, so the
     * `get_userdata()` calls below hit the object cache.
     *
     * @param  list<int> $ids
     * @return array<int,string>
     */
    private static function accountNames( array $ids ): array {
        cache_users( $ids );

        $out = [];
        foreach ( $ids as $id ) {
            $user = get_userdata( $id );
            if ( ! $user instanceof \WP_User ) continue;
            $name = trim( (string) $user->display_name );
            if ( $name !== '' ) $out[ $id ] = $name;
        }
        return $out;
    }

    /**
     * First row per account wins, so callers control precedence by
     * ordering the query.
     *
     * @param  array<int,mixed> $rows
     * @return array<int,string>
     */
    private static function collect( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $uid = (int) ( $row['wp_user_id'] ?? 0 );
            if ( $uid <= 0 || isset( $out[ $uid ] ) ) continue;
            $name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            if ( $name !== '' ) $out[ $uid ] = $name;
        }
        return $out;
    }
}
