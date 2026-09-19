<?php
namespace TT\Modules\Activities;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * ActivityAccess — "may this user read this activity?" (#3688).
 *
 * `GET /activities` has always answered that question for a list: staff with
 * global `activities` read see every team, other staff see the teams they
 * coach, and players and parents see only what belongs to a player they are
 * verified for. The rule lived inline in the list handler, so there was no
 * single-record form of it, and the activity peek fell back to the
 * capability alone. `tt_view_activities` is club-wide, so a coach could peek
 * every team's trainings by walking ids. An activity's title, team and date
 * say where a group of minors will be and when.
 *
 * `canRead()` is the per-record form. The list handler calls the same
 * building blocks, so the list and the peek cannot drift apart. The SQL for
 * the player branch lives in the repository, next to the list query that
 * uses the same clause.
 */
final class ActivityAccess {

    /** Global `activities` read: head of development, academy admin, scouts. */
    public static function hasGlobalRead( int $user_id ): bool {
        return QueryHelpers::user_has_global_entity_read( $user_id, 'activities' );
    }

    /**
     * Holds the staff activities capability, directly or through the
     * matrix. That says *whether* the user reads activities, never *whose*:
     * without global read, a staff reader is narrowed to the teams they coach.
     */
    public static function isStaffReader( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        return AuthorizationService::userCanOrMatrix( $user_id, 'tt_view_activities' )
            || AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_activities' );
    }

    /**
     * The teams whose activities a staff reader without global read may see.
     *
     * @return list<int>
     */
    public static function coachedTeamIds( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        return array_map(
            'intval',
            array_column( QueryHelpers::get_teams_for_coach( $user_id ), 'id' )
        );
    }

    /**
     * True when the user is this player, or a verified parent of them.
     */
    public static function canReadAsPlayerOrParent( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;
        $repo = new ActivitiesRepository();
        if ( $repo->linkedPlayerIdForUser( $user_id ) === $player_id ) return true;
        return $repo->userIsParentOfPlayer( $user_id, $player_id );
    }

    /**
     * The player a non-staff caller of the list is scoped to, derived from
     * the session rather than trusted from the query:
     *   - their own linked player, when they have one (the requested id is
     *     ignored for self-scope);
     *   - the requested child, only once the parent link is verified;
     *   - 0 when nothing resolves, which the list turns into an empty set.
     */
    public static function playerScopeFor( int $user_id, int $requested_player_id ): int {
        if ( $user_id <= 0 ) return 0;
        $repo = new ActivitiesRepository();

        $own = $repo->linkedPlayerIdForUser( $user_id );
        if ( $own > 0 ) return $own;

        if ( $requested_player_id > 0 && $repo->userIsParentOfPlayer( $user_id, $requested_player_id ) ) {
            return $requested_player_id;
        }
        return 0;
    }

    /**
     * Every player whose activities this user may read as that player or as
     * their parent: their own linked player plus their active children.
     *
     * @return list<int>
     */
    public static function readablePlayerIds( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        $ids = [];
        $own = ( new ActivitiesRepository() )->linkedPlayerIdForUser( $user_id );
        if ( $own > 0 ) $ids[] = $own;
        foreach ( \TT\Infrastructure\Players\ParentChildResolver::childIds( $user_id ) as $child_id ) {
            if ( ! in_array( $child_id, $ids, true ) ) $ids[] = $child_id;
        }
        return $ids;
    }

    /**
     * May this user read activities at all? Used to decide whether an id
     * that does not exist is a 404 (you may read activities, there is no
     * such one) or a 403 (you learn nothing).
     */
    public static function mayReadAny( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        return self::hasGlobalRead( $user_id )
            || self::isStaffReader( $user_id )
            || self::readablePlayerIds( $user_id ) !== [];
    }

    /**
     * May this user read this activity? The per-record form of the rule
     * `GET /activities` applies to its rows.
     *
     * @param object $activity A `tt_activities` row; `id` and `team_id` are read.
     */
    public static function canRead( int $user_id, object $activity ): bool {
        if ( $user_id <= 0 ) return false;

        $row         = (array) $activity;
        $activity_id = (int) ( $row['id'] ?? 0 );
        $team_id     = (int) ( $row['team_id'] ?? 0 );
        if ( $activity_id <= 0 ) return false;

        if ( self::hasGlobalRead( $user_id ) ) return true;

        if ( $team_id > 0
             && self::isStaffReader( $user_id )
             && in_array( $team_id, self::coachedTeamIds( $user_id ), true ) ) {
            return true;
        }

        $repo = new ActivitiesRepository();
        foreach ( self::readablePlayerIds( $user_id ) as $player_id ) {
            if ( $repo->isVisibleToPlayer( $activity_id, $player_id ) ) return true;
        }
        return false;
    }
}
