<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalRatingsRepository;
use TT\Infrastructure\PlayerStatus\PlayerAttendanceCalculator;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Stats\TeamStatsService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\PdpVerdictsRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * CohortBoardService (#1383) — read-only end-of-season decision board.
 *
 * One row per active player on a team, composed from the player's
 * rolling rating + trend, attendance %, PDP conversation count, and the
 * current PDP verdict. The HoD uses it to make retain / promote /
 * release calls at season end. Read-only: verdicts are set in the PDP
 * file, never here.
 *
 * Every read is club-scoped (CLAUDE.md §4 tenancy). Business logic lives
 * here so the REST controller and the PHP view share one source of truth.
 */
final class CohortBoardService {

    /**
     * Compose the cohort board rows for a team in the current season.
     *
     * @return array<int, array{
     *   player_id:int,
     *   name:string,
     *   status:string,
     *   status_label:string,
     *   rolling:?float,
     *   trend:string,
     *   attendance_pct:?float,
     *   attendance_low_confidence:bool,
     *   pdp_conversations:int,
     *   verdict:?string,
     *   verdict_label:string,
     *   pdp_file_id:?int,
     *   pdp_url:string
     * }>
     */
    public function rowsForTeam( int $team_id ): array {
        if ( $team_id <= 0 ) return [];

        global $wpdb;
        $p = $wpdb->prefix;

        /** @var object[] $players */
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, status
               FROM {$p}tt_players
              WHERE team_id = %d AND status = 'active' AND club_id = %d
              ORDER BY last_name ASC, first_name ASC",
            $team_id, CurrentClub::id()
        ) );
        if ( ! is_array( $players ) || $players === [] ) return [];

        $season = ( new SeasonsRepository() )->current();
        $season_id    = $season !== null ? (int) $season->id : 0;
        $season_start = $season !== null ? (string) $season->start_date : '';
        $season_end   = $season !== null ? (string) $season->end_date : '';

        $stats      = new TeamStatsService();
        $attendance = new PlayerAttendanceCalculator();
        $files_repo = new PdpFilesRepository();
        $verdicts   = new PdpVerdictsRepository();

        $trends = $this->trendByPlayer( $players );

        $rows = [];
        foreach ( $players as $player ) {
            $player_id = (int) $player->id;
            $name      = trim( (string) $player->first_name . ' ' . (string) $player->last_name );
            $status    = (string) $player->status;

            $rank    = $stats->getRankInTeam( $player_id );
            $rolling = $rank !== null ? (float) $rank['rolling'] : null;

            $attendance_pct = null;
            $low_confidence = true;
            if ( $season_start !== '' && $season_end !== '' ) {
                $att            = $attendance->scoreFor( $player_id, $season_start, $season_end );
                $attendance_pct = $att['score'];
                $low_confidence = (bool) $att['low_confidence'];
            }

            $pdp_conversations = $files_repo->countConductedConversationsForPlayer( $player_id );

            $pdp_file_id   = null;
            $verdict       = null;
            $verdict_label = '';
            if ( $season_id > 0 ) {
                $file = $files_repo->findByPlayerSeason( $player_id, $season_id );
                if ( $file !== null ) {
                    $pdp_file_id = (int) $file->id;
                    $verdict_row = $verdicts->findForFile( $pdp_file_id );
                    if ( $verdict_row !== null ) {
                        $verdict       = (string) $verdict_row->decision;
                        $verdict_label = isset( $verdict_row->decision_localised )
                            ? (string) $verdict_row->decision_localised
                            : PdpVerdictsRepository::label( $verdict );
                    }
                }
            }
            if ( $verdict === null ) {
                $verdict_label = __( 'Pending', 'talenttrack' );
            }

            $rows[] = [
                'player_id'                 => $player_id,
                'name'                      => $name,
                'status'                    => $status,
                'status_label'              => LabelTranslator::playerStatus( $status ),
                'rolling'                   => $rolling,
                'trend'                     => $trends[ $player_id ] ?? 'flat',
                'attendance_pct'            => $attendance_pct,
                'attendance_low_confidence' => $low_confidence,
                'pdp_conversations'         => $pdp_conversations,
                'verdict'                   => $verdict,
                'verdict_label'             => $verdict_label,
                'pdp_file_id'               => $pdp_file_id,
                'pdp_url'                   => $this->pdpUrl( $pdp_file_id, $player_id ),
            ];
        }

        return $rows;
    }

    /**
     * Compute a recent-vs-older trend per player from overall evaluation
     * ratings: average of the last 3 ratings vs the previous 3. Returns
     * 'up' / 'down' / 'flat'; a player with thin data (< 2 ratings) is
     * 'flat'. A 0.2-point dead-band keeps tiny wobble out of the arrow.
     *
     * @param object[] $players rows with an `id` property
     * @return array<int,string> player_id => trend
     */
    private function trendByPlayer( array $players ): array {
        $player_ids = array_values( array_filter(
            array_map( static fn( $pl ) => (int) $pl->id, $players ),
            static fn( $id ) => $id > 0
        ) );
        if ( $player_ids === [] ) return [];

        global $wpdb;
        $p            = $wpdb->prefix;
        $placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );

        /** @var object[] $evals */
        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, player_id
               FROM {$p}tt_evaluations
              WHERE player_id IN ($placeholders) AND club_id = %d
              ORDER BY eval_date ASC, id ASC",
            ...array_merge( $player_ids, [ CurrentClub::id() ] )
        ) );
        if ( ! is_array( $evals ) || $evals === [] ) return [];

        $eval_ids = array_map( static fn( $e ) => (int) $e->id, $evals );
        $overalls = ( new EvalRatingsRepository() )->overallRatingsForEvaluations( $eval_ids );

        /** @var array<int, list<float>> $values_by_player chronological */
        $values_by_player = [];
        foreach ( $evals as $ev ) {
            $eval_id = (int) $ev->id;
            $value   = $overalls[ $eval_id ]['value'] ?? null;
            if ( $value === null ) continue;
            $values_by_player[ (int) $ev->player_id ][] = (float) $value;
        }

        $trends = [];
        foreach ( $values_by_player as $pid => $values ) {
            $trends[ (int) $pid ] = self::trendFromSeries( $values );
        }
        return $trends;
    }

    /**
     * Recent-vs-older trend from a chronological rating series.
     *
     * @param list<float> $values oldest → newest
     */
    private static function trendFromSeries( array $values ): string {
        $count = count( $values );
        if ( $count < 2 ) return 'flat';

        $window = min( 3, (int) floor( $count / 2 ) );
        $window = max( 1, $window );

        $recent = array_slice( $values, -$window );
        $older  = array_slice( $values, -2 * $window, $window );
        if ( $older === [] ) return 'flat';

        $recent_avg = array_sum( $recent ) / count( $recent );
        $older_avg  = array_sum( $older ) / count( $older );
        $delta      = $recent_avg - $older_avg;

        if ( $delta > 0.2 ) return 'up';
        if ( $delta < -0.2 ) return 'down';
        return 'flat';
    }

    /**
     * Deep-link into the PDP file for a player. Links to the file detail
     * when a file exists this season; otherwise to the PDP create flow
     * scoped to the player so the HoD can open one.
     */
    private function pdpUrl( ?int $pdp_file_id, int $player_id ): string {
        $dashboard = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
        if ( $pdp_file_id !== null && $pdp_file_id > 0 ) {
            return add_query_arg(
                [ 'tt_view' => 'pdp', 'id' => $pdp_file_id ],
                $dashboard
            );
        }
        return add_query_arg(
            [ 'tt_view' => 'pdp', 'action' => 'new', 'player_id' => $player_id ],
            $dashboard
        );
    }
}
