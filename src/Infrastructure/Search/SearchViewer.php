<?php
namespace TT\Infrastructure\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;

/**
 * SearchViewer (#4673) — the questions search asks about the viewer that
 * are not about one kind: is this a family viewer (a parent or a player,
 * not staff), and whose development records do they read.
 *
 * Staff is whoever reads players academy-wide or by team
 * (`AuthorizationService::playerReadScope()`). A coach whose child plays at
 * the academy is staff here, and still finds that child's own records
 * through {@see self::playerIdsReading()}.
 */
final class SearchViewer {

    /** @var array<int, object|null> */
    private static array $own = [];

    public static function isStaff( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        // Not `tt_view_players`: the matrix bridge grants that to a guardian
        // too, for their own children. Staff read players academy-wide or by
        // team; a family reads them one by one.
        $scope = AuthorizationService::playerReadScope( $user_id );
        return $scope['global'] || $scope['team_ids'] !== [];
    }

    /**
     * A parent or a player who is not also staff. They get no quick actions,
     * and no settings or modules in their results.
     */
    public static function isFamily( int $user_id ): bool {
        if ( $user_id <= 0 || self::isStaff( $user_id ) ) return false;
        return ParentSearchService::isParentCaller( $user_id ) || self::ownPlayer( $user_id ) !== null;
    }

    /** The viewer's own player record, when they are a player. */
    public static function ownPlayer( int $user_id ): ?object {
        if ( $user_id <= 0 ) return null;
        if ( ! array_key_exists( $user_id, self::$own ) ) {
            self::$own[ $user_id ] = QueryHelpers::get_player_for_user( $user_id );
        }
        return self::$own[ $user_id ];
    }

    /**
     * The players whose `$section` (`evaluations`, `goals`) this viewer reads
     * as family: their own record, and each linked child whose development
     * record they read, the guardian's matrix access allows and the child has
     * not kept from them. Never another player's.
     *
     * @return list<int>
     */
    public static function playerIdsReading( int $user_id, string $section ): array {
        if ( $user_id <= 0 ) return [];
        $out = [];

        $own = self::ownPlayer( $user_id );
        $own_id = $own !== null ? (int) ( $own->id ?? 0 ) : 0;
        if ( $own_id > 0 && AuthorizationService::mayReadPlayerSection( $user_id, $own_id, $section ) ) {
            $out[] = $own_id;
        }

        foreach ( ParentChildResolver::childIds( $user_id ) as $child_id ) {
            if ( in_array( $child_id, $out, true ) ) continue;
            if ( ! ParentChildResolver::guardianAllows( $user_id, $child_id, $section, 'read' ) ) continue;
            if ( ! AuthorizationService::mayReadPlayerSection( $user_id, $child_id, $section ) ) continue;
            $out[] = $child_id;
        }
        return $out;
    }

    /**
     * For a guardian, the child each activity belongs to: the child on the
     * activity's team, otherwise the child on its register (a guest
     * appearance for another team). The family pages resolve their subject
     * from `player_id`, and a parent of two landing on the wrong child is
     * the bug that would make a result useless. Activities with no child of
     * this user are left out.
     *
     * @param array<int,int> $team_by_activity activity id => team id
     * @return array<int,int> activity id => child player id
     */
    public static function childForActivities( int $user_id, array $team_by_activity ): array {
        if ( $user_id <= 0 || $team_by_activity === [] ) return [];
        $children = [];
        foreach ( ParentChildResolver::children( $user_id ) as $child ) {
            $row = (array) $child;
            $id  = (int) ( $row['id'] ?? 0 );
            if ( $id > 0 ) $children[ $id ] = (int) ( $row['team_id'] ?? 0 );
        }
        if ( $children === [] ) return [];

        $out     = [];
        $missing = [];
        foreach ( $team_by_activity as $activity_id => $team_id ) {
            $match = $team_id > 0 ? array_search( $team_id, $children, true ) : false;
            if ( $match !== false ) {
                $out[ (int) $activity_id ] = (int) $match;
            } else {
                $missing[] = (int) $activity_id;
            }
        }
        if ( $missing === [] ) return $out;

        global $wpdb;
        $kids = array_keys( $children );
        // Either kind of attendance row: a child on the planned squad of a
        // fixture next month has no recorded register yet. /* both-kinds-ok */
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id, MIN(player_id) AS player_id FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id IN (" . implode( ',', array_fill( 0, count( $missing ), '%d' ) ) . ')
                AND player_id IN (' . implode( ',', array_fill( 0, count( $kids ), '%d' ) ) . ')
           GROUP BY activity_id',
            ...array_merge( $missing, $kids )
        ) );
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row->activity_id ] = (int) $row->player_id;
        }
        return $out;
    }

    /** @var array<int, array<int,string>> */
    private static array $names = [];

    /**
     * The first names of a guardian's children, by player id, when there is
     * more than one: a result about one of them has to say which. Empty for
     * a single child or none.
     *
     * @return array<int,string>
     */
    public static function childNamesToShow( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        if ( ! isset( self::$names[ $user_id ] ) ) {
            $names = [];
            foreach ( ParentChildResolver::children( $user_id ) as $child ) {
                $row = (array) $child;
                $id  = (int) ( $row['id'] ?? 0 );
                if ( $id > 0 ) $names[ $id ] = trim( (string) ( $row['first_name'] ?? '' ) );
            }
            self::$names[ $user_id ] = count( $names ) > 1 ? $names : [];
        }
        return self::$names[ $user_id ];
    }

    /** Tests and long-running processes. */
    public static function flush(): void {
        self::$own   = [];
        self::$names = [];
    }
}
