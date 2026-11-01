<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Analytics\Reports\MinutesQuery;

/**
 * What is missing and needs fixing before next month (#4590).
 */
final class QualityComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        $coverage = CoverageComposer::compose( $ctx );
        $counts   = ( new MinutesQuery() )->matchCountsForTeam( $ctx->team_id, $ctx->from, $ctx->to );
        $cover    = ( new EvalCoverageService() )->coverageBetween( $ctx->team_id, $ctx->from, $ctx->to );
        $players  = $ctx->players();

        $evaluated     = array_flip( $cover['evaluated_ids'] );
        $not_evaluated = [];
        foreach ( $players as $pid => $p ) {
            if ( isset( $evaluated[ $pid ] ) ) continue;
            $not_evaluated[] = [ 'player_id' => $pid, 'name' => TeamReportFigures::name( $p ) ];
        }

        $incomplete = [];
        foreach ( $ctx->verdictsAt( $ctx->to ) as $pid => $v ) {
            if ( $v->isComplete() ) continue;
            $incomplete[] = [
                'player_id'      => $pid,
                'name'           => isset( $players[ $pid ] ) ? TeamReportFigures::name( $players[ $pid ] ) : '',
                'missing_inputs' => $v->missing_inputs,
            ];
        }

        // #3860 — a match with no opponent stored prints as "Unknown
        // opponent" in the fixtures above, and the reader cannot tell
        // whether the record is wrong or merely incomplete. Named by date
        // and title, because the title is where the opponent usually is.
        $without_opponent = [];
        foreach ( $ctx->fixtures() as $match ) {
            if ( trim( (string) $match['opponent'] ) !== '' ) continue;
            $without_opponent[] = [
                'activity_id' => (int) $match['activity_id'],
                'date'        => (string) $match['date'],
                'title'       => (string) $match['title'],
            ];
        }

        return [
            'activities_without_register'    => $coverage['missing'],
            'activities_never_closed'        => $coverage['never_closed'],
            'matches_without_minutes'        => max( 0, $counts['played'] - $counts['recorded'] ),
            'matches_without_opponent'       => $without_opponent,
            'players_not_evaluated'          => $not_evaluated,
            'players_with_incomplete_status' => $incomplete,
        ];
    }
}
