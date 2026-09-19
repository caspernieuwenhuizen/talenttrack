<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * AccountPlayerLinks — which player records a logged-in account is linked
 * to, as its own player and as a guardian (#3568).
 *
 * The rendered front end has always resolved this server-side, from
 * `tt_players.wp_user_id` and the parent pivot, and nothing exposed it:
 * a non-WordPress client logged in as a player had no way to learn the id
 * every per-player route asks for. This is that answer, in the domain
 * layer, so `GET me` and any future consumer read the same rules.
 *
 * Both halves go through the canonical resolvers — `get_player_for_user()`
 * (club, active status, demo scope) for self and
 * `ParentChildResolver::children()` (club, active status) for guardians —
 * and additionally drop archived records, which neither filters.
 */
final class AccountPlayerLinks {

    public const REASON_NOT_LINKED = 'no_linked_player';

    /**
     * @return array{
     *     player: array{id:int, name:string, team_id:int, status:string}|null,
     *     children: list<array{id:int, name:string, team_id:int, status:string}>,
     *     reason: string|null
     * }
     */
    public static function forUser( int $user_id ): array {
        $player   = null;
        $children = [];

        if ( $user_id > 0 ) {
            $own = QueryHelpers::get_player_for_user( $user_id );
            if ( $own !== null && self::isLive( $own ) ) {
                $player = self::summary( $own );
            }

            foreach ( ParentChildResolver::children( $user_id ) as $child ) {
                if ( self::isLive( $child ) ) $children[] = self::summary( $child );
            }
        }

        return [
            'player'   => $player,
            'children' => $children,
            'reason'   => ( $player === null && $children === [] ) ? self::REASON_NOT_LINKED : null,
        ];
    }

    private static function isLive( object $row ): bool {
        $r = (array) $row;
        return (int) ( $r['id'] ?? 0 ) > 0 && empty( $r['archived_at'] );
    }

    /** @return array{id:int, name:string, team_id:int, status:string} */
    private static function summary( object $row ): array {
        $r = (array) $row;
        return [
            'id'      => (int) ( $r['id'] ?? 0 ),
            'name'    => QueryHelpers::player_display_name( $row ),
            'team_id' => (int) ( $r['team_id'] ?? 0 ),
            'status'  => (string) ( $r['status'] ?? '' ),
        ];
    }
}
