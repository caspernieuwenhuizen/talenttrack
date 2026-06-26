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

        return $this->rankPlayers( $players, $values_by_player, $n, $rolling_n );
    }

    /**
     * Top N players for several teams in one batched pass.
     *
     * Returns a map keyed by team_id; each value is the same zero-indexed,
     * rolling-sorted list of rows the single-team method produces. Teams with
     * no rated players are present with an empty array.
     *
     * Collapses the per-team 3-query pattern into 3 queries total regardless
     * of the number of teams (one player query, one evaluation query, one
     * batched overall-ratings lookup).
     *
     * @param int[] $team_ids
     * @param int   $n          Default 3 (the podium case).
     * @param int   $rolling_n  Evaluations included in the rolling average. Default 5.
     *
     * @return array<int, array<int, array{
     *   player_id:int,
     *   player:object,
     *   rolling:float,
     *   eval_count:int
     * }>>  team_id => ranked rows.
     */
    public function getTopPlayersForTeams( array $team_ids, int $n = 3, int $rolling_n = 5 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $team_ids = array_values( array_unique( array_filter( array_map( 'intval', $team_ids ), static fn( $id ) => $id > 0 ) ) );
        if ( empty( $team_ids ) ) return [];

        // Active players across all requested teams in one query.
        $team_placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_players WHERE team_id IN ($team_placeholders) AND status = 'active'",
            ...$team_ids
        ) );
        if ( ! is_array( $players ) || empty( $players ) ) return [];

        // Group players by team, preserving query order within each team.
        $players_by_team = [];
        foreach ( $players as $pl ) {
            $players_by_team[ (int) $pl->team_id ][] = $pl;
        }

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

        // Batch the overalls for all those evaluations (one query).
        $values_by_player = []; // player_id => [value, value, ...]
        if ( is_array( $evals ) && ! empty( $evals ) ) {
            $ratings_repo = new EvalRatingsRepository();
            $eval_ids = array_map( fn( $e ) => (int) $e->id, $evals );
            $overalls = $ratings_repo->overallRatingsForEvaluations( $eval_ids );

            foreach ( $evals as $ev ) {
                $pid = (int) $ev->player_id;
                $v   = $overalls[ (int) $ev->id ]['value'] ?? null;
                if ( $v === null ) continue;
                $values_by_player[ $pid ][] = (float) $v;
            }
        }

        // Rank per team, sharing the exact scoring/sort/slice logic.
        $result = [];
        foreach ( $players_by_team as $tid => $team_players ) {
            $result[ $tid ] = $this->rankPlayers( $team_players, $values_by_player, $n, $rolling_n );
        }
        return $result;
    }

    /**
     * Score, sort and slice a set of players to the top N by rolling-average
     * overall rating. Shared by the single-team and batched methods so the
     * ranking logic can never drift between them.
     *
     * @param array<int, \stdClass>      $players           $wpdb player rows (id, last_name, …)
     * @param array<int, array<float>>   $values_by_player  player_id => chronological overall values
     * @param int                        $n
     * @param int                        $rolling_n
     *
     * @return array<int, array{player_id:int,player:object,rolling:float,eval_count:int}>
     */
    private function rankPlayers( array $players, array $values_by_player, int $n, int $rolling_n ): array {
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
     * Resolve a player's rank within their team's rolling-rating order.
     * Returns null when the player has no team, no evaluations yet, or
     * isn't on an active roster. Otherwise returns a small array with
     * the player's 1-based rank and the team's rated-player count —
     * the My team view uses this for the "you're #N of M" badge
     * **without** exposing individual rankings of other teammates.
     *
     * @return null|array{rank:int,total:int,rolling:float}
     */
    public function getRankInTeam( int $player_id, int $rolling_n = 5 ): ?array {
        if ( $player_id <= 0 ) return null;
        global $wpdb;
        $p = $wpdb->prefix;
        $team_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$p}tt_players WHERE id = %d LIMIT 1",
            $player_id
        ) );
        if ( $team_id <= 0 ) return null;

        // Reuse the ranked list. Pass a large N so every rated player
        // is in the result. The list is already sorted desc-by-rolling.
        $ranked = $this->getTopPlayersForTeam( $team_id, 999, $rolling_n );
        if ( empty( $ranked ) ) return null;

        foreach ( $ranked as $i => $row ) {
            if ( (int) $row['player_id'] === $player_id ) {
                return [
                    'rank'    => $i + 1,
                    'total'   => count( $ranked ),
                    'rolling' => (float) $row['rolling'],
                ];
            }
        }
        return null;
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
