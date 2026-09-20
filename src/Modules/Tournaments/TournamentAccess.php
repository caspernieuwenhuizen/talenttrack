<?php
namespace TT\Modules\Tournaments;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\MatrixGate;

/**
 * TournamentAccess — who may read, plan and delete a tournament (#3703).
 *
 * v1 shipped the module admin-only and said so in the seed: the
 * coach / head-of-development expansion was "a separate, deliberate
 * future change". This class is that change's decision layer, so the
 * REST controller, the rendered planner and the dashboard tile cannot
 * answer it three different ways.
 *
 * Authority is resolved through the `tournaments` matrix entity, never a
 * role-name compare. A WordPress settings admin keeps the club-wide view
 * they have always had, which is the same fallback the attendance
 * reports and the minutes surfaces use.
 *
 * ## Which teams a tournament belongs to
 *
 * `tt_tournaments.team_id` is the *anchor* team, but the squad is a list
 * of players and a player belongs to a team, so a tournament day can pull
 * a squad from several age groups. Every decision below is made against
 * the full set — anchor plus every squad member's team — because the
 * anchor alone would let a coach reach a fixture whose players are not
 * theirs, and would refuse one whose players are.
 *
 * ## Read and change: any of them. Delete: all of them.
 *
 * Reading and planning are things you do to the part of the day that is
 * yours, so holding one participating team is enough. Deleting is not
 * partial — it takes the whole tournament away from every squad in it —
 * so a team-scoped actor may delete only when every participating team is
 * one of theirs. A global-scope actor is unaffected by that rule.
 */
final class TournamentAccess {

    public const ENTITY = 'tournaments';

    /**
     * Does the user hold the activity somewhere — the question a tile or a
     * collection route asks, before there is an id in hand.
     */
    public static function canAnywhere( int $user_id, string $activity ): bool {
        if ( $user_id <= 0 ) return false;
        return self::hasGlobal( $user_id, $activity )
            || MatrixGate::canAnyScope( $user_id, self::ENTITY, $activity );
    }

    /** Club-wide authority: a global matrix grant, or the WordPress settings admin. */
    public static function hasGlobal( int $user_id, string $activity ): bool {
        if ( $user_id <= 0 ) return false;
        if ( user_can( $user_id, 'tt_edit_settings' ) ) return true;
        return MatrixGate::can( $user_id, self::ENTITY, $activity, MatrixGate::SCOPE_GLOBAL );
    }

    /** May the user read this tournament? One participating team of theirs is enough. */
    public static function canView( int $user_id, int $tournament_id ): bool {
        return self::holdsAny( $user_id, $tournament_id, MatrixGate::READ );
    }

    /** May the user plan it — squad, matches, assignments, kickoff, complete? */
    public static function canEdit( int $user_id, int $tournament_id ): bool {
        return self::holdsAny( $user_id, $tournament_id, MatrixGate::CHANGE );
    }

    /**
     * May the user create a tournament anchored on this team?
     *
     * `$team_id` is the anchor the caller is asking for, so this is the one
     * decision made before a tournament exists and therefore before there
     * is a squad to widen the answer.
     */
    public static function canCreateForTeam( int $user_id, int $team_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( self::hasGlobal( $user_id, MatrixGate::CREATE_DELETE ) ) return true;
        if ( $team_id <= 0 ) return false;
        return MatrixGate::can( $user_id, self::ENTITY, MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM, $team_id );
    }

    /**
     * May the user delete it?
     *
     * A global grant deletes anything. A team-scoped grant deletes only a
     * tournament every one of whose participating teams it holds — see
     * `spansTeamsOutsideScope()` for the refusal the caller turns into a
     * message.
     */
    public static function canDelete( int $user_id, int $tournament_id ): bool {
        if ( $user_id <= 0 || $tournament_id <= 0 ) return false;
        if ( self::hasGlobal( $user_id, MatrixGate::CREATE_DELETE ) ) return true;

        $teams = self::participatingTeamIds( $tournament_id );
        if ( $teams === [] ) return false;

        foreach ( $teams as $team_id ) {
            if ( ! MatrixGate::can( $user_id, self::ENTITY, MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM, $team_id ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when the delete is refused *because the tournament reaches past
     * the actor's teams*, rather than because they hold no delete right at
     * all. The two refusals read identically to a user and mean entirely
     * different things, so the caller distinguishes them.
     */
    public static function spansTeamsOutsideScope( int $user_id, int $tournament_id ): bool {
        if ( $user_id <= 0 || $tournament_id <= 0 ) return false;
        if ( self::hasGlobal( $user_id, MatrixGate::CREATE_DELETE ) ) return false;

        $teams = self::participatingTeamIds( $tournament_id );
        if ( $teams === [] ) return false;

        $held  = 0;
        $unheld = 0;
        foreach ( $teams as $team_id ) {
            if ( MatrixGate::can( $user_id, self::ENTITY, MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM, $team_id ) ) {
                $held++;
            } else {
                $unheld++;
            }
        }
        return $held > 0 && $unheld > 0;
    }

    /**
     * Every team taking part: the anchor, plus the team of each player in
     * the squad. Club-scoped, de-duplicated, ascending.
     *
     * @return list<int>
     */
    public static function participatingTeamIds( int $tournament_id ): array {
        if ( $tournament_id <= 0 ) return [];

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = CurrentClub::id();

        $ids = [];

        $anchor = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$p}tt_tournaments WHERE id = %d AND club_id = %d",
            $tournament_id,
            $club
        ) );
        if ( $anchor > 0 ) $ids[] = $anchor;

        $squad = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pl.team_id
               FROM {$p}tt_tournament_squad s
         INNER JOIN {$p}tt_players pl ON pl.id = s.player_id
              WHERE s.tournament_id = %d AND s.club_id = %d",
            $tournament_id,
            $club
        ) );
        foreach ( (array) $squad as $team_id ) {
            $team_id = (int) $team_id;
            if ( $team_id > 0 ) $ids[] = $team_id;
        }

        $ids = array_values( array_unique( $ids ) );
        sort( $ids );
        return $ids;
    }

    /**
     * The teams a team-scoped reader may list tournaments for. Empty when
     * they hold none — which the list route turns into an empty page, not
     * into the whole academy.
     *
     * @return list<int>
     */
    public static function readableTeamIds( int $user_id ): array {
        $out = [];
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $team ) {
            $team_id = (int) ( $team->id ?? 0 );
            if ( $team_id <= 0 ) continue;
            if ( MatrixGate::can( $user_id, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $team_id ) ) {
                $out[] = $team_id;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /** Shared body of `canView()` / `canEdit()`: global, or any participating team. */
    private static function holdsAny( int $user_id, int $tournament_id, string $activity ): bool {
        if ( $user_id <= 0 || $tournament_id <= 0 ) return false;
        if ( self::hasGlobal( $user_id, $activity ) ) return true;

        foreach ( self::participatingTeamIds( $tournament_id ) as $team_id ) {
            if ( MatrixGate::can( $user_id, self::ENTITY, $activity, MatrixGate::SCOPE_TEAM, $team_id ) ) {
                return true;
            }
        }
        return false;
    }
}
