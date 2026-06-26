<?php
namespace TT\Modules\MatchExecution\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;

/**
 * MatchEventFeedService (#1713) — builds the chronological "Live verloop"
 * feed for a match by merging the two event logs the live surface
 * already records: home goals and substitutions. Red/yellow cards are
 * not modelled, so the feed is goals + subs only.
 *
 * Business logic lives here (not in the view) so the REST endpoint and
 * the PHP render share one answer — SaaS-ready per CLAUDE.md §4. The
 * feed is club-scoped through the repositories it calls.
 *
 * Running score: only home goals are timestamped (the live surface logs
 * a scorer for home goals; the away tally is a plain counter on the
 * execution row). The running_home value therefore advances per home
 * goal in the feed; running_away carries the execution row's away_score
 * as the known opponent total (un-timestamped) so the chip never lies
 * about the final scoreline.
 */
final class MatchEventFeedService {

    /**
     * Time-ordered feed for an activity. Empty when there is no
     * execution row yet (the match has not started).
     *
     * @return list<array{
     *   type:string,
     *   half:int,
     *   minute:int,
     *   running_home:int,
     *   running_away:int,
     *   player_name:string,
     *   player_off_name:string,
     *   player_on_name:string,
     *   label:string
     * }>
     */
    public function feedForActivity( int $activity_id ): array {
        if ( $activity_id <= 0 ) {
            return [];
        }

        $exec_repo = new MatchExecutionRepository();
        $execution = $exec_repo->findByActivity( $activity_id );
        if ( ! $execution ) {
            return [];
        }
        $execution_id = (int) $execution->id;
        $away_total   = (int) ( $execution->away_score ?? 0 );

        $goals = $exec_repo->listGoalEvents( $execution_id );
        $subs  = $exec_repo->listSubstitutions( $execution_id );

        $player_ids = [];
        foreach ( $goals as $g ) {
            $player_ids[] = (int) $g->player_id;
        }
        foreach ( $subs as $s ) {
            $player_ids[] = (int) $s->player_off_id;
            $player_ids[] = (int) $s->player_on_id;
        }
        $names = $this->playerNames( $player_ids );

        /** @var list<array{type:string,half:int,minute:int,sort:int,player_name:string,player_off_name:string,player_on_name:string,label:string}> $rows */
        $rows = [];

        foreach ( $goals as $g ) {
            $half   = (int) $g->half;
            $minute = (int) $g->minute_in_half;
            $pid    = (int) $g->player_id;
            $rows[] = [
                'type'            => 'goal',
                'half'            => $half,
                'minute'          => $minute,
                'sort'            => $half * 1000 + $minute,
                'player_name'     => $names[ $pid ] ?? '',
                'player_off_name' => '',
                'player_on_name'  => '',
                'label'           => __( 'Goal scored', 'talenttrack' ),
            ];
        }

        foreach ( $subs as $s ) {
            $half   = (int) $s->half;
            $minute = (int) $s->minute_in_half;
            $off    = (int) $s->player_off_id;
            $on     = (int) $s->player_on_id;
            $rows[] = [
                'type'            => 'substitution',
                'half'            => $half,
                'minute'          => $minute,
                'sort'            => $half * 1000 + $minute,
                'player_name'     => '',
                'player_off_name' => $names[ $off ] ?? '',
                'player_on_name'  => $names[ $on ] ?? '',
                'label'           => __( 'Substitution', 'talenttrack' ),
            ];
        }

        usort( $rows, static function ( array $a, array $b ): int {
            if ( $a['sort'] !== $b['sort'] ) {
                return $a['sort'] <=> $b['sort'];
            }
            // Goals before subs at the same minute so the scoreline reads
            // forward before the swap that often follows it.
            return ( $a['type'] === 'goal' ? 0 : 1 ) <=> ( $b['type'] === 'goal' ? 0 : 1 );
        } );

        $running_home = 0;
        $feed = [];
        foreach ( $rows as $row ) {
            if ( $row['type'] === 'goal' ) {
                $running_home++;
            }
            $feed[] = [
                'type'            => $row['type'],
                'half'            => $row['half'],
                'minute'          => $row['minute'],
                'running_home'    => $running_home,
                'running_away'    => $away_total,
                'player_name'     => $row['player_name'],
                'player_off_name' => $row['player_off_name'],
                'player_on_name'  => $row['player_on_name'],
                'label'           => $row['label'],
            ];
        }

        return $feed;
    }

    /**
     * @param list<int> $player_ids
     * @return array<int, string>
     */
    private function playerNames( array $player_ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ) ) ) );
        if ( empty( $ids ) ) {
            return [];
        }
        global $wpdb;
        /** @var \wpdb $wpdb */
        $in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE id IN ($in) AND club_id = %d",
            array_merge( $ids, [ CurrentClub::id() ] )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->id ] = QueryHelpers::player_display_name( $r );
        }
        return $out;
    }
}
