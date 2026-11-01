<?php
namespace TT\Modules\Activities;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\MatrixGate;
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
     * May this user write to this team's activities? `activities` change at
     * global scope writes every team; at team scope, the teams it is held
     * on. Asked of the change grant, never the read one: a coach who also
     * holds a club-wide read seat writes only their own teams.
     * An activity with no team has no team to be out of scope for, so the
     * capability gate is the whole answer for it. The activities write
     * routes and the attendance wizards ask this one question.
     */
    public static function canWriteTeam( int $user_id, ?int $team_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( MatrixGate::can( $user_id, 'activities', MatrixGate::CHANGE, MatrixGate::SCOPE_GLOBAL ) ) return true;
        if ( $team_id === null || $team_id <= 0 ) return true;
        return MatrixGate::can( $user_id, 'activities', MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM, $team_id );
    }

    /**
     * The teams whose activities this user may write: null for every team,
     * otherwise the coached teams {@see self::canWriteTeam()} admits.
     *
     * @return list<int>|null
     */
    public static function writableTeamIds( int $user_id ): ?array {
        if ( $user_id > 0 && MatrixGate::can( $user_id, 'activities', MatrixGate::CHANGE, MatrixGate::SCOPE_GLOBAL ) ) return null;
        return array_values( array_filter(
            self::coachedTeamIds( $user_id ),
            static fn( int $team_id ): bool => self::canWriteTeam( $user_id, $team_id )
        ) );
    }

    /**
     * {@see self::canWriteTeam()} for one stored activity, archived ones
     * included. False when the activity does not exist.
     */
    public static function canWriteActivity( int $user_id, int $activity_id ): bool {
        if ( $activity_id <= 0 ) return false;
        $activity = ( new ActivitiesRepository() )->findByIdIncludingArchived( $activity_id );
        if ( $activity === null ) return false;
        $team_id = (int) ( $activity->team_id ?? 0 );
        return self::canWriteTeam( $user_id, $team_id > 0 ? $team_id : null );
    }

    /**
     * True when the user is this player, or a verified parent of them.
     */
    public static function canReadAsPlayerOrParent( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;
        $repo = new ActivitiesRepository();
        if ( $repo->linkedPlayerIdForUser( $user_id ) === $player_id ) return true;
        return self::isScheduleGuardian( $user_id, $player_id );
    }

    /**
     * #4089 — may this reader see the coach's free-text note on the child's
     * attendance row? Everybody who reads the row, except a guardian who
     * reaches the child only because the child is on trial: the note is a
     * staff remark ("left early, knee"), and a trialist's family reads the
     * schedule and whether the child was there, not the staff's remarks.
     */
    public static function canReadAttendanceNotes( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;
        if ( ! ParentChildResolver::isTrialFor( $user_id, $player_id ) ) return true;
        return AuthorizationService::canViewPlayer( $user_id, $player_id );
    }

    /**
     * #4089 — a guardian reads their child's schedule and attendance on
     * every access level but none: a trialist's family too, which reads
     * nothing else of the child's.
     */
    private static function isScheduleGuardian( int $user_id, int $player_id ): bool {
        return ParentChildResolver::isParentOf( $user_id, $player_id, ParentChildResolver::NEED_SCHEDULE );
    }

    /**
     * #3972 — a scout reads activities only through a `player`-scoped
     * grant, for the players they are linked to. True when this player is
     * one of them. A scout holds no team, so without this branch the list
     * would answer a linked scout with nothing at all.
     */
    public static function canReadAsLinkedScout( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;
        return in_array(
            $player_id,
            AuthorizationService::scoutLinkedReadablePlayerIds( $user_id, 'activities' ),
            true
        );
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

        if ( $requested_player_id > 0 && self::isScheduleGuardian( $user_id, $requested_player_id ) ) {
            return $requested_player_id;
        }
        return 0;
    }

    /**
     * Every player whose activities this user may read as that player or as
     * their parent: their own linked player plus the children whose
     * schedule they read (#4089: a trialist included).
     *
     * @return list<int>
     */
    public static function readablePlayerIds( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        $ids = [];
        $own = ( new ActivitiesRepository() )->linkedPlayerIdForUser( $user_id );
        if ( $own > 0 ) $ids[] = $own;
        foreach ( ParentChildResolver::childIds( $user_id, ParentChildResolver::NEED_SCHEDULE ) as $child_id ) {
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
        // #3972 — a linked scout reads the activities their players took
        // part in, by the same visibility clause.
        foreach ( AuthorizationService::scoutLinkedReadablePlayerIds( $user_id, 'activities' ) as $player_id ) {
            if ( $repo->isVisibleToPlayer( $activity_id, $player_id ) ) return true;
        }
        return false;
    }
}
