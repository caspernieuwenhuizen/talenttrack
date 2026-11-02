<?php
namespace TT\Modules\Strava;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;

/**
 * Which players' connections a reader of the Strava club console sees.
 *
 * A club-wide reader (`strava_operator_console` read at global scope, or a
 * WordPress administrator) sees every connection in the club. A reader
 * at team scope, such as a head coach, sees the connections of players on
 * the teams they currently work with and no others. The REST roster and
 * any other consumer ask this one place.
 */
final class StravaConsoleScope {

    public const ENTITY = 'strava_operator_console';

    /**
     * Does the user read the console for the whole club? The club-wide
     * parts of the console, such as the webhook subscription status, ask
     * this rather than the team-scope read.
     */
    public static function isClubWide( int $user_id ): bool {
        return $user_id > 0 && self::teamIdsFor( $user_id ) === null;
    }

    /**
     * The team ids to narrow the roster to, or null for the whole club.
     *
     * @return list<int>|null
     */
    public static function teamIdsFor( int $user_id ): ?array {
        if ( $user_id <= 0 ) return [];
        if ( user_can( $user_id, 'administrator' ) ) return null;
        if ( MatrixGate::can( $user_id, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) ) return null;

        $ids = [];
        foreach ( QueryHelpers::get_permitted_teams( $user_id, self::ENTITY, MatrixGate::READ ) as $team ) {
            $vars = get_object_vars( (object) $team );
            $id   = isset( $vars['id'] ) ? (int) $vars['id'] : 0;
            if ( $id > 0 ) $ids[] = $id;
        }
        return $ids;
    }
}
