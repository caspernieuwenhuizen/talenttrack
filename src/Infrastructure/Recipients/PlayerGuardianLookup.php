<?php
namespace TT\Infrastructure\Recipients;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerGuardianLookup (#3795) — who at home may be told about this player,
 * for any number of players in one query.
 *
 * The counterpart of `TeamHeadCoachLookup`, and it exists for the same
 * reason: the alerts sweep resolves recipients across the whole academy, so
 * a per-player guardian lookup would turn one sweep into hundreds of round
 * trips — the query shape `AlertInterface` forbids.
 *
 * `tt_player_parents` is the one live source of the parent → child link, the
 * same pivot `ParentChildResolver` and `Comms\Recipient\RecipientResolver`
 * read. `tt_players.guardian_email` is not consulted: it is an invite hint
 * that *creates* a pivot row, never a runtime linkage, and reading it here
 * would mean a family could be notified about a child nobody ever linked
 * them to.
 *
 * Club-scoped, always. A dropped club clause on a cron sweep crosses tenants
 * silently rather than failing, and the thing that would cross is a named
 * minor's record.
 */
final class PlayerGuardianLookup {

    /**
     * WP user ids of every linked guardian, keyed by player id, in one query.
     *
     * Players with no linked guardian are absent from the result rather than
     * present with an empty list, so a caller can tell "nobody to tell" from
     * "asked about a player that is not in the set".
     *
     * @param list<int> $player_ids
     * @return array<int,list<int>> player_id => list of wp_user_id
     */
    public static function forPlayers( array $player_ids ): array {
        global $wpdb;

        $player_ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ) ) ) );
        if ( empty( $player_ids ) ) return [];

        $table = $wpdb->prefix . 'tt_player_parents';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $list = implode( ',', $player_ids );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pp.player_id, pp.parent_user_id
               FROM {$table} pp
              WHERE pp.player_id IN ({$list})
                AND pp.club_id = %d
                AND pp.parent_user_id > 0
              ORDER BY pp.is_primary DESC, pp.parent_user_id ASC",
            CurrentClub::id()
        ) );
        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $player = (int) $row->player_id;
            $parent = (int) $row->parent_user_id;
            if ( $player <= 0 || $parent <= 0 ) continue;
            if ( ! isset( $out[ $player ] ) ) $out[ $player ] = [];
            if ( ! in_array( $parent, $out[ $player ], true ) ) $out[ $player ][] = $parent;
        }
        return $out;
    }

    /**
     * @return list<int>
     */
    public static function forPlayer( int $player_id ): array {
        $map = self::forPlayers( [ $player_id ] );
        return $map[ $player_id ] ?? [];
    }
}
