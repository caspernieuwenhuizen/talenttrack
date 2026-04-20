<?php
namespace TT\Infrastructure\Stats;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalRatingsRepository;

/**
 * TeamStatsService — team-level analytics built on top of PlayerStatsService.
 *
 * Sprint 2B (v2.15.0). Initial scope: top-N players per team ranked by
 * rolling-average overall rating, used by the front-end team podium
 * and the coach dashboard.
 *
 * Future sprints will extend this with team aggregate rate cards,
 * team-wide category averages, etc.
 */
class TeamStatsService {

    /**
     * Top N players on a team, ranked by rolling-average overall rating.
     * Players without any rated evaluations are excluded from ranking.
     *
     * @param int $team_id
     * @param int $n          Default 3 (the podium case).
     * @param int $rolling_n  Evaluations included in the rolling average. Default 5.
     *
     * @return array<int, array{
     *   player_id:int,
     *   player:object,
     *   rolling:float,
     *   eval_count:int
     * }>  Zero-indexed, sorted desc by rolling.
     */
    public function getTopPlayersForTeam( int $team_id, int $n = 3, int $rolling_n = 5 ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $team_id <= 0 ) return [];

        // Active players on this team.
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_players WHERE team_id = %d AND status = 'active'",
            $team_id
        ) );
        if ( ! is_array( $players ) || empty( $players ) ) return [];

        // Evaluations for all these players in one query.
        $player_ids = array_map( fn( $pl ) => (int) $pl->id, $players );
        $placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, player_id, eval_date
             FROM {$p}tt_evaluations
             WHERE player_id IN ($placeholders)
             ORDER BY eval_date ASC, id ASC",
            ...$player_ids
        ) );
        if ( ! is_array( $evals ) || empty( $evals ) ) return [];

        // Batch the overalls for all those evaluations.
        $ratings_repo = new EvalRatingsRepository();
        $eval_ids = array_map( fn( $e ) => (int) $e->id, $evals );
        $overalls = $ratings_repo->overallRatingsForEvaluations( $eval_ids );

        // Group overall values per player, chronological.
        $values_by_player = []; // player_id => [value, value, ...]
        foreach ( $evals as $ev ) {
            $pid = (int) $ev->player_id;
            $v   = $overalls[ (int) $ev->id ]['value'] ?? null;
            if ( $v === null ) continue;
            $values_by_player[ $pid ][] = (float) $v;
        }

        // Compute rolling average per player — mean of last N values.
        $scored = [];
        foreach ( $players as $pl ) {
            $pid    = (int) $pl->id;
            $values = $values_by_player[ $pid ] ?? [];
            if ( empty( $values ) ) continue;
            $tail   = array_slice( $values, - max( 1, $rolling_n ) );
            $rolling = array_sum( $tail ) / count( $tail );
            $scored[] = [
                'player_id'  => $pid,
                'player'     => $pl,
                'rolling'    => round( $rolling, 1 ),
                'eval_count' => count( $values ),
            ];
        }

        // Sort by rolling desc, then eval_count desc (more-rated wins ties),
        // then last_name asc as a final deterministic tie-break.
        usort( $scored, function ( $a, $b ) {
            if ( $a['rolling'] !== $b['rolling'] ) return $a['rolling'] < $b['rolling'] ? 1 : -1;
            if ( $a['eval_count'] !== $b['eval_count'] ) return $a['eval_count'] < $b['eval_count'] ? 1 : -1;
            $la = isset( $a['player']->last_name ) ? (string) $a['player']->last_name : '';
            $lb = isset( $b['player']->last_name ) ? (string) $b['player']->last_name : '';
            return strcasecmp( $la, $lb );
        } );

        return array_slice( $scored, 0, $n );
    }

    /**
     * All active teammates of a given player, EXCLUDING the player
     * themselves. Used by the "Mijn team" front-end tab to list
     * teammates without exposing their ratings.
     *
     * @return object[]  Sorted by last_name, first_name ASC.
     */
    public function getTeammatesOfPlayer( int $player_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $player_id <= 0 ) return [];

        $player = $wpdb->get_row( $wpdb->prepare(
            "SELECT team_id FROM {$p}tt_players WHERE id = %d LIMIT 1",
            $player_id
        ) );
        if ( ! $player || empty( $player->team_id ) ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_players
             WHERE team_id = %d AND status = 'active' AND id != %d
             ORDER BY last_name, first_name ASC",
            (int) $player->team_id, $player_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }
}
