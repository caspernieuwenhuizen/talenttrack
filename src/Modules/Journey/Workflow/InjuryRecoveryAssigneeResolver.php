<?php
namespace TT\Modules\Journey\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Recipients\TeamHeadCoachLookup;
use TT\Infrastructure\Recipients\TeamStaffLookup;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\TaskContext;

/**
 * InjuryRecoveryAssigneeResolver (#4246) — who is asked to decide on an
 * injury record: the team's physios and its head coach, or the head coach
 * alone when the team has no physio (owner decision D-11 on #4282). Only
 * people who may read this player's injuries are kept, so the reminder never
 * reaches someone the record itself would refuse.
 */
final class InjuryRecoveryAssigneeResolver implements AssigneeResolver {

    /** @return int[] */
    public function resolve( TaskContext $context ): array {
        $team_id   = (int) ( $context->team_id ?? 0 );
        $player_id = (int) ( $context->player_id ?? 0 );
        if ( $team_id <= 0 || $player_id <= 0 ) return [];

        $ids = TeamStaffLookup::forTeam( $team_id, [ 'physio' ] );
        $head_coach = TeamHeadCoachLookup::forTeam( $team_id );
        if ( $head_coach !== null && $head_coach > 0 ) $ids[] = $head_coach;

        $out = [];
        foreach ( array_unique( array_map( 'intval', $ids ) ) as $uid ) {
            if ( $uid > 0 && AuthorizationService::canRecordInjury( $uid, $player_id, 'read' ) ) {
                $out[] = $uid;
            }
        }
        return $out;
    }
}
