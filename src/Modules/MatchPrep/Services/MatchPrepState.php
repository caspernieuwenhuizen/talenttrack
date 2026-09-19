<?php
namespace TT\Modules\MatchPrep\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

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

    /**
     * @return array<string,mixed>|null null when no prep exists for the activity.
     */
    public static function forActivity( int $activity_id ): ?array {
        $repo = new MatchPrepRepository();
        $prep = $repo->findByActivity( $activity_id );
        if ( ! $prep ) return null;

        $prep_id     = (int) $prep->id;
        $template_id = (int) ( $prep->formation_template_id ?? 0 );
        $team_id     = ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )->activityTeamId( $activity_id );

        $state = [
            'prep_id'               => $prep_id,
            'activity_id'           => $activity_id,
            'formation_template_id' => $template_id > 0 ? $template_id : null,
            'formation_shape'       => FormationLayoutResolver::shapeFor( $template_id, $team_id ),
            'half_length_minutes'   => (int) ( $prep->half_length_minutes ?? 0 ),
        ];
        foreach ( self::GOAL_FIELDS as $field ) {
            $state[ $field ] = (string) ( $prep->{$field} ?? '' );
        }

        $lineup = self::lineupByHalf( $repo->listLineup( $prep_id ) );

        $state['availability'] = (object) self::availabilityByPlayer( $repo->listAvailability( $prep_id ) );
        $state['lineup']       = [ '1' => (object) $lineup[1], '2' => (object) $lineup[2] ];
        $state['player_goals'] = (object) self::playerGoalsByPlayer( $repo->listPlayerGoals( $prep_id ) );
        $state['roles']        = (object) self::rolesByKey( $repo->listRoles( $prep_id ) );

        return $state;
    }

    /**
     * @param array<int,object> $rows
     * @return array<int,array{status:string,reason:string}>
     */
    public static function availabilityByPlayer( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row->player_id ] = [
                'status' => (string) ( $row->status ?? 'Present' ),
                'reason' => (string) ( $row->reason ?? '' ),
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
            $half = (int) $row->half;
            if ( $half !== 1 && $half !== 2 ) continue;
            $out[ $half ][ (int) $row->slot_number ] = (int) $row->player_id;
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
            $out[ (int) $row->player_id ] = [
                'attention_text'    => (string) ( $row->attention_text ?? '' ),
                'is_specific_goal'  => ! empty( $row->is_specific_goal ),
                'analyst_appointed' => ! empty( $row->analyst_appointed ),
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
            $out[ (string) $row->role_key ] = (int) $row->player_id;
        }
        return $out;
    }
}
