<?php
namespace TT\Modules\Measurements\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;

/**
 * MeasurementCoverageService (#1882) — "who's due / overdue" per team.
 *
 * Player-centric (CLAUDE.md §1): starts from the team roster and, for each
 * scheduled test (one with a real cadence), reports how many players are
 * up to date vs. the gap — and names the players who need testing. Pure
 * composition over the foundation repositories; no logic in the view (§4).
 */
class MeasurementCoverageService {

    /**
     * @return array{definitions: list<array<string,mixed>>, player_count: int}
     */
    public function forTeam( int $team_id ): array {
        $empty = [ 'definitions' => [], 'player_count' => 0 ];
        if ( $team_id <= 0 ) return $empty;

        // Only definitions with a real cadence count toward coverage.
        $defs = ( new MeasurementDefinitionsRepository() )->listActive();
        $scheduled = array_values( array_filter( $defs, static function ( $d ): bool {
            return MeasurementScheduleService::intervalDays( (string) ( $d->frequency ?? '' ) ) !== null;
        } ) );
        if ( empty( $scheduled ) ) return $empty;

        $players = QueryHelpers::get_players( $team_id );
        if ( empty( $players ) ) return [ 'definitions' => [], 'player_count' => 0 ];

        $results_repo = new MeasurementResultsRepository();
        $latest_by_player = []; // player_id => [definition_id => row]
        foreach ( $players as $p ) {
            $latest_by_player[ (int) $p->id ] = $results_repo->latestPerDefinitionForPlayer( (int) $p->id );
        }

        $now   = (int) current_time( 'timestamp', true );
        $total = count( $players );
        $out   = [];

        foreach ( $scheduled as $d ) {
            $did   = (int) $d->id;
            $freq  = (string) $d->frequency;
            $counts = [
                MeasurementScheduleService::UP_TO_DATE => 0,
                MeasurementScheduleService::DUE_SOON   => 0,
                MeasurementScheduleService::OVERDUE    => 0,
                MeasurementScheduleService::NEVER      => 0,
            ];
            $needs = [];

            foreach ( $players as $p ) {
                $pid  = (int) $p->id;
                $row  = $latest_by_player[ $pid ][ $did ] ?? null;
                $last = $row ? (string) ( $row->recorded_date ?? '' ) : null;
                $st   = MeasurementScheduleService::status( $freq, $last, $now );

                if ( isset( $counts[ $st ] ) ) $counts[ $st ]++;
                if ( in_array( $st, MeasurementScheduleService::GAP_STATUSES, true ) ) {
                    $needs[] = [
                        'player_id' => $pid,
                        'name'      => trim( (string) $p->first_name . ' ' . (string) $p->last_name ),
                        'status'    => $st,
                        'last_date' => $last ?? '',
                    ];
                }
            }

            $out[] = [
                'definition_id'  => $did,
                'name'           => (string) $d->name,
                'frequency'      => $freq,
                'category_label' => (string) ( $d->category_label ?? '' ),
                'total'          => $total,
                'up_to_date'     => $counts[ MeasurementScheduleService::UP_TO_DATE ],
                'counts'         => $counts,
                'needs'          => $needs,
            ];
        }

        return [ 'definitions' => $out, 'player_count' => $total ];
    }
}
