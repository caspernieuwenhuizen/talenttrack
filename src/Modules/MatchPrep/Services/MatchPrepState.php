<?php
namespace TT\Modules\MatchPrep\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\MatrixGate;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MatchPrepState (#3587) — one match prep as data: the header, the squad
 * (`availability`), the line-up per half, the per-player goals and the role
 * assignments.
 *
 * `GET match-prep/{activity_id}` returns it, `PUT` answers with it, and the
 * prep screen builds its bootstrap maps from the same helpers, so the screen
 * and the API cannot disagree about what a prep says.
 *
 * The squad contract: a player is in the squad when their `availability`
 * status is `Present`; `lineup` places players per half as slot → player id.
 * A Present player without a half-1 slot is on the bench.
 */
final class MatchPrepState {

    public const GOAL_FIELDS = [ 'goals_general', 'goals_attack', 'goals_defend', 'goals_attack_setpiece', 'goals_defend_setpiece' ];

    public const FALLBACK_STARTER_TARGET = 11;

    /**
     * @param int $viewer_id The user the prep is read for; decides whether the
     *                       suspension list is included (#4245).
     * @return array<string,mixed>|null null when no prep exists for the activity.
     */
    public static function forActivity( int $activity_id, int $viewer_id = 0 ): ?array {
        $repo = new MatchPrepRepository();
        $prep = $repo->findByActivity( $activity_id );
        if ( ! $prep ) return null;

        $prep        = (array) $prep;
        $prep_id     = (int) ( $prep['id'] ?? 0 );
        $template_id = (int) ( $prep['formation_template_id'] ?? 0 );
        $team_id     = ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )->activityTeamId( $activity_id );
        $half_length = (int) ( $prep['half_length_minutes'] ?? 0 );

        $state = [
            'prep_id'               => $prep_id,
            'activity_id'           => $activity_id,
            'formation_template_id' => $template_id > 0 ? $template_id : null,
            'formation_shape'       => FormationLayoutResolver::shapeFor( $template_id, $team_id ),
            'starter_target'        => self::starterTarget( $template_id, $team_id ),
            'half_length_minutes'   => $half_length,
        ];
        // #3682 — what the activity says the match lasts, and whether the
        // prep disagrees with it. A readout, not a sync.
        foreach ( self::halfLengthAgainstActivity( $activity_id, $half_length ) as $key => $value ) {
            $state[ $key ] = $value;
        }
        foreach ( self::GOAL_FIELDS as $field ) {
            $state[ $field ] = (string) ( $prep[ $field ] ?? '' );
        }

        // #4353 — every stored slot, so the editor can restore a line-up when
        // the coach switches back to a bigger formation.
        $lineup = self::lineupByHalf( $repo->listLineupAll( $prep_id ) );

        $roster_ids = [];
        foreach ( \TT\Infrastructure\Query\QueryHelpers::get_players( $team_id ) as $player ) {
            $pid = (int) ( ( (array) $player )['id'] ?? 0 );
            if ( $pid > 0 ) $roster_ids[] = $pid;
        }

        // #4285 — only players on the team's current roster, the same squad
        // the prep screen lists. The saved rows of a player who left stay in
        // the table; they are just not part of this squad any more.
        $state['availability'] = (object) self::availabilityByPlayer( $repo->listAvailability( $prep_id ), $roster_ids );
        $state['lineup']       = [ '1' => (object) $lineup[1], '2' => (object) $lineup[2] ];
        $state['player_goals'] = (object) self::playerGoalsByPlayer( $repo->listPlayerGoals( $prep_id ) );
        $state['roles']        = (object) self::rolesByKey( $repo->listRoles( $prep_id ) );

        // #4103 — who a recorded suspension covers in this match, so a
        // client can pre-mark them Suspended the way the drawer does. Only
        // for a viewer who may read this team's injuries (#4245).
        if ( self::viewerSeesInjuries( $viewer_id, $team_id ) ) {
            $state['suspended'] = (object) self::suspendedByPlayer( $activity_id, $roster_ids );
        }
        // #4245 — who cannot play in this match (injured on its date, or
        // suspended), as the one neutral state planned attendance shows.
        // Derived and never stored: the coach's `availability` stays theirs.
        $unavailable          = self::unavailableByPlayer( $activity_id, $roster_ids );
        $state['unavailable'] = (object) $unavailable;
        // #4246 / #4269 — available, but an injury record is overdue or
        // incomplete on the match date: a neutral marker, separate from
        // `unavailable`, derived and never stored.
        $state['availability_check'] = (object) self::checkByPlayer( $activity_id, $roster_ids, $unavailable );

        return $state;
    }

    /**
     * #4245 — may this viewer read the injuries of this team's players?
     *
     * A viewer who may not gets one combined `unavailable` set and no
     * separate suspension list: with both, "unavailable but not suspended"
     * would name the injured players. The staff scopes of
     * `AuthorizationService::canRecordInjury()` (global, then the team), asked
     * once for the team rather than once per player, as the injury overview
     * asks them.
     */
    public static function viewerSeesInjuries( int $viewer_id, int $team_id ): bool {
        if ( $viewer_id <= 0 || $team_id <= 0 ) return false;
        if ( MatrixGate::can( $viewer_id, 'player_injuries', MatrixGate::READ, 'global' ) ) return true;
        return MatrixGate::can( $viewer_id, 'player_injuries', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $team_id );
    }

    /**
     * #4245 — the suspension list a viewer may see: `suspendedByPlayer()` for
     * a viewer with injury access to the activity's team, empty otherwise.
     *
     * @param array<int, int> $player_ids
     * @return array<int, array{match:int, of:int, reason:string}>
     */
    public static function suspendedForViewer( int $activity_id, array $player_ids, int $viewer_id ): array {
        $team_id = ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )->activityTeamId( $activity_id );
        if ( ! self::viewerSeesInjuries( $viewer_id, $team_id ) ) return [];
        return self::suspendedByPlayer( $activity_id, $player_ids );
    }

    /**
     * #4245 — the players in `$player_ids` who cannot play in this match,
     * from `PlayerAvailability`. The state only: no cause, no injury detail
     * (#4005 decision 3), so it is safe for every role that opens the prep.
     *
     * @param array<int, int> $player_ids
     * @return array<int, true>
     */
    public static function unavailableByPlayer( int $activity_id, array $player_ids ): array {
        return \TT\Modules\Activities\Services\PlayerAvailability::unavailableForActivity( $player_ids, $activity_id );
    }

    /**
     * #4246 / #4269 — the players in `$player_ids` the coach should check for
     * this match: available, with an injury record that is overdue or
     * incomplete on its date. The marker only, safe for every role that opens
     * the prep, like `unavailableByPlayer()`.
     *
     * @param array<int, int>       $player_ids
     * @param array<int, true>|null $unavailable From `unavailableByPlayer()` when the caller has it.
     * @return array<int, true>
     */
    public static function checkByPlayer( int $activity_id, array $player_ids, ?array $unavailable = null ): array {
        return \TT\Modules\Activities\Services\PlayerAvailability::checkForActivity( $player_ids, $activity_id, null, $unavailable );
    }

    /**
     * #4280 — how many starters the line-up holds: the slot count of the
     * resolved layout, so a 3-2-1 six-a-side prep needs six, not eleven.
     */
    public static function starterTarget( int $formation_template_id, int $team_id ): int {
        $slots = count( FormationLayoutResolver::layoutFor( $formation_template_id, $team_id ) );
        return $slots > 0 ? $slots : self::FALLBACK_STARTER_TARGET;
    }

    /**
     * The starter target for an activity's prep: the prep's bound template
     * when one exists, else the team's football-form default.
     */
    public static function starterTargetForActivity( int $activity_id ): int {
        $prep        = ( new MatchPrepRepository() )->findByActivity( $activity_id );
        $template_id = $prep ? (int) ( ( (array) $prep )['formation_template_id'] ?? 0 ) : 0;
        $team_id     = ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )->activityTeamId( $activity_id );
        return self::starterTarget( $template_id, $team_id );
    }

    /**
     * #4103 — the players in `$player_ids` whom a recorded suspension covers
     * for this match, with which match of how many it is and the reason text
     * the availability step and the drawer pre-mark them Suspended with.
     *
     * @param array<int, int> $player_ids
     * @return array<int, array{match:int, of:int, reason:string}>
     */
    public static function suspendedByPlayer( int $activity_id, array $player_ids ): array {
        $out = [];
        foreach ( \TT\Infrastructure\Journey\SuspensionService::coveredSet( $player_ids, $activity_id ) as $pid => $cover ) {
            $out[ $pid ] = [
                'match'  => $cover['match'],
                'of'     => $cover['of'],
                'reason' => \TT\Infrastructure\Journey\SuspensionService::progressLabel( $cover['match'], $cover['of'] ),
            ];
        }
        return $out;
    }

    /**
     * #3682 — the match length the activity carries, alongside whether
     * the prep's half length disagrees with it.
     *
     * A match stores its length twice: `tt_activities.match_length_minutes`
     * (#1726) and `tt_match_prep.half_length_minutes`. A new prep is seeded
     * from the first, but they never sync afterwards — match execution, the
     * minutes queries and the match analysis all read the prep, and silently
     * rewriting it under a coach who had already planned around 2 x 35 would
     * move a player's recorded minutes without asking. So the disagreement is
     * reported instead, and the coach decides.
     *
     * Computed here rather than in the prep screen so `GET` / `PUT
     * match-prep/{id}` answer with it too and a non-WordPress client can draw
     * the same warning (CLAUDE.md section 4).
     *
     * @return array{activity_match_length_minutes:int|null,half_length_mismatch:bool}
     */
    public static function halfLengthAgainstActivity( int $activity_id, int $half_length_minutes ): array {
        $length = ( new MatchLengthResolver() )->activityMatchLength( $activity_id );

        return [
            'activity_match_length_minutes' => $length > 0 ? $length : null,
            'half_length_mismatch'          => $length > 0
                && MatchLengthResolver::halfOf( $length ) !== $half_length_minutes,
        ];
    }

    /**
     * @param array<int,object>    $rows
     * @param array<int, int>|null $roster_ids #4285 — when given, only rows for
     *                                         these players: a player who left
     *                                         the team is not in its squad.
     * @return array<int,array{status:string,reason:string}>
     */
    public static function availabilityByPlayer( array $rows, ?array $roster_ids = null ): array {
        $on_roster = $roster_ids === null ? null : array_flip( array_map( 'intval', $roster_ids ) );
        $out       = [];
        foreach ( $rows as $row ) {
            $row = (array) $row;
            $pid = (int) ( $row['player_id'] ?? 0 );
            if ( $on_roster !== null && ! isset( $on_roster[ $pid ] ) ) continue;
            $out[ $pid ] = [
                'status' => (string) ( $row['status'] ?? 'Present' ),
                'reason' => (string) ( $row['reason'] ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * @param array<int,object> $rows
     * @return array{1:array<int,int>,2:array<int,int>} half => slot => player id
     */
    public static function lineupByHalf( array $rows ): array {
        $out = [ 1 => [], 2 => [] ];
        foreach ( $rows as $row ) {
            $row  = (array) $row;
            $half = (int) ( $row['half'] ?? 0 );
            if ( $half !== 1 && $half !== 2 ) continue;
            $out[ $half ][ (int) ( $row['slot_number'] ?? 0 ) ] = (int) ( $row['player_id'] ?? 0 );
        }
        return $out;
    }

    /**
     * @param array<int,object> $rows
     * @return array<int,array{attention_text:string,is_specific_goal:bool,analyst_appointed:bool}>
     */
    public static function playerGoalsByPlayer( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $row = (array) $row;
            $out[ (int) ( $row['player_id'] ?? 0 ) ] = [
                'attention_text'    => (string) ( $row['attention_text'] ?? '' ),
                'is_specific_goal'  => ! empty( $row['is_specific_goal'] ),
                'analyst_appointed' => ! empty( $row['analyst_appointed'] ),
            ];
        }
        return $out;
    }

    /**
     * @param array<int,object> $rows
     * @return array<string,int> role key => player id
     */
    public static function rolesByKey( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $row = (array) $row;
            $out[ (string) ( $row['role_key'] ?? '' ) ] = (int) ( $row['player_id'] ?? 0 );
        }
        return $out;
    }

    /**
     * #4260 — where each player stands in the match-day squad the coach
     * picked in match prep: `start` (a half-1 slot on a position the layout
     * draws), `bench` (Present without one) or `out` (any other status). A
     * player the prep has no row for is not in the map. The same reading
     * the attendance projection writes `lineup_role` from.
     *
     * The squad position only: never the prep's status or reason, which can
     * name a medical cause (#4005, decision 3).
     *
     * @param array<int, int> $player_ids
     * @return array<int, string>|null player id => start|bench|out; null when
     *                                 the activity has no match prep.
     *                                 Callers give it to staff readers only
     *                                 (D-22).
     */
    public static function matchSquadForActivity( int $activity_id, array $player_ids ): ?array {
        $repo = new MatchPrepRepository();
        $prep = $repo->findByActivity( $activity_id );
        if ( ! $prep ) return null;

        $prep_id       = (int) ( $prep->id ?? 0 );
        $slot_position = FormationLayoutResolver::labelsFor(
            (int) ( $prep->formation_template_id ?? 0 ),
            ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )->activityTeamId( $activity_id )
        );

        $starting = [];
        $known    = [];
        foreach ( $repo->listLineupAll( $prep_id ) as $row ) {
            $row = (array) $row;
            $pid = (int) ( $row['player_id'] ?? 0 );
            $known[ $pid ] = true;
            if ( (int) ( $row['half'] ?? 0 ) === 1 && isset( $slot_position[ (int) ( $row['slot_number'] ?? 0 ) ] ) ) {
                $starting[ $pid ] = true;
            }
        }
        $present = [];
        foreach ( $repo->listAvailability( $prep_id ) as $row ) {
            $pid = (int) ( $row->player_id ?? 0 );
            $known[ $pid ] = true;
            if ( strcasecmp( (string) ( $row->status ?? '' ), 'Present' ) === 0 ) {
                $present[ $pid ] = true;
            }
        }

        // A player the prep has no row for (added to the plan after the prep
        // was last saved) is left out of the map: the coach never left them
        // out, so `out` would be read as "dropped".
        $out = [];
        foreach ( $player_ids as $pid ) {
            $pid = (int) $pid;
            if ( isset( $starting[ $pid ] ) ) {
                $out[ $pid ] = 'start';
            } elseif ( isset( $present[ $pid ] ) ) {
                $out[ $pid ] = 'bench';
            } elseif ( isset( $known[ $pid ] ) ) {
                $out[ $pid ] = 'out';
            }
        }
        return $out;
    }
}
