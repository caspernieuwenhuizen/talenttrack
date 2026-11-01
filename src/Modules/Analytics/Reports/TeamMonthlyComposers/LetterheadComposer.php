<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\EvalCoverageService;

/**
 * The monthly report's letterhead: the team, the window, the head coach, the
 * squad size and how many activities the window held (#4590).
 */
final class LetterheadComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        $team = $ctx->team();
        return [
            'team_id'        => $ctx->team_id,
            'team_name'      => $team !== null ? (string) ( $team->name ?? '' ) : '',
            'age_group'      => $team !== null ? (string) ( $team->age_group ?? '' ) : '',
            'head_coach'     => ( new EvalCoverageService() )->headCoachNameForTeam( $ctx->team_id ),
            'from'           => $ctx->from,
            'to'             => $ctx->to,
            'squad_size'     => count( $ctx->players() ),
            // Everything on the coach's activities list for the window, not
            // only what was closed (#3746). A month with eight sessions and
            // one closed one used to print "1 activity".
            'activity_count' => count( $ctx->scheduledIn( $ctx->from, $ctx->to ) ),
            'generated_at'   => gmdate( 'Y-m-d H:i:s' ),
        ];
    }
}
