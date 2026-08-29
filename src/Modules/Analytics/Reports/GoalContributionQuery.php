<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * GoalContributionQuery (#2859) — goals and assists per player, read from
 * the match-execution goal log.
 *
 * Until this existed, `tt_match_execution_goal_events` had no reader outside
 * its own module: a player could score a hat-trick and nothing on their
 * record would say so. The plugin measured a player's **exposure** (minutes,
 * via {@see MinutesQuery}) and a coach's **judgement** (evaluations, tracked
 * actions) but never their **output**, which is half of what a conversation
 * about an attacker is actually about.
 *
 * Counting rules, which are the whole substance of this class:
 *
 *   - **Goals** are ours (`team = 'home'`), not own goals, with a scorer.
 *     A goal nobody attributed (`player_id = 0`) belongs to the match, not
 *     to a player, and is counted for nobody rather than being guessed at.
 *   - **Assists** are credited from `assist_player_id`, independently of who
 *     scored. A player credited with a goal and an assist in the same match
 *     counts once in each.
 *   - **Own goals** are tracked but never added to a scorer's goal tally.
 *     Putting one into your own net is not an attacking contribution, and a
 *     tally that quietly said otherwise would be worse than not showing it.
 *   - **Reversed** (undone) events count for nobody, on either side.
 *
 * Reads are club-scoped and filtered to real, non-cancelled match activities,
 * matching the set {@see MinutesQuery} counts, so goals and minutes on the
 * same screen always describe the same matches.
 */
final class GoalContributionQuery {

    /** Activity types that can carry a match scoreline. Mirrors MinutesQuery. */
    private const MATCH_TYPES = [ 'match', 'game', 'tournament' ];

    /**
     * One player's contribution over a window.
     *
     * @param array{from?:string, to?:string, team_id?:int} $filters
     * @return array{
     *     player_id:int, goals:int, assists:int, own_goals:int,
     *     contributions:int, matches_with_a_contribution:int,
     *     per_match:array<int, array{activity_id:int, session_date:string, goals:int, assists:int}>
     * }
     */
    public function forPlayer( int $player_id, array $filters = [] ): array {
        $empty = [
            'player_id'                   => $player_id,
            'goals'                       => 0,
            'assists'                     => 0,
            'own_goals'                   => 0,
            'contributions'               => 0,
            'matches_with_a_contribution' => 0,
            'per_match'                   => [],
        ];
        if ( $player_id <= 0 ) return $empty;

        $filters['player_id'] = $player_id;
        $rows = $this->fetchEvents( $filters );
        if ( empty( $rows ) ) return $empty;

        $per_match = [];
        $own_goals = 0;

        foreach ( $rows as $row ) {
            $aid      = (int) ( $row['activity_id'] ?? 0 );
            $date     = (string) ( $row['session_date'] ?? '' );
            $scorer   = (int) ( $row['player_id'] ?? 0 );
            $assist   = (int) ( $row['assist_player_id'] ?? 0 );
            $own      = ! empty( $row['is_own_goal'] );
            $is_ours  = ( (string) ( $row['team'] ?? 'home' ) === 'home' );

            $scored   = ( $is_ours && ! $own && $scorer === $player_id );
            $assisted = ( $assist === $player_id );

            if ( $own && $scorer === $player_id ) $own_goals++;
            if ( ! $scored && ! $assisted ) continue;

            if ( ! isset( $per_match[ $aid ] ) ) {
                $per_match[ $aid ] = [
                    'activity_id'  => $aid,
                    'session_date' => $date,
                    'goals'        => 0,
                    'assists'      => 0,
                ];
            }
            if ( $scored )   $per_match[ $aid ]['goals']++;
            if ( $assisted ) $per_match[ $aid ]['assists']++;
        }

        $goals = 0;
        $assists = 0;
        foreach ( $per_match as $m ) {
            $goals   += $m['goals'];
            $assists += $m['assists'];
        }

        return [
            'player_id'                   => $player_id,
            'goals'                       => $goals,
            'assists'                     => $assists,
            'own_goals'                   => $own_goals,
            'contributions'               => $goals + $assists,
            'matches_with_a_contribution' => count( $per_match ),
            'per_match'                   => $per_match,
        ];
    }

    /**
     * Per-player totals across a team's matches in the window. Only players
     * who actually contributed appear; the caller joins this onto its own
     * roster so a player with none renders a zero rather than vanishing.
     *
     * @param array{from?:string, to?:string} $filters
     * @return array<int, array{player_id:int, goals:int, assists:int, own_goals:int, contributions:int}>
     */
    public function forTeam( int $team_id, array $filters = [] ): array {
        if ( $team_id <= 0 ) return [];

        $filters['team_id'] = $team_id;
        $rows = $this->fetchEvents( $filters );
        if ( empty( $rows ) ) return [];

        $out = [];
        $touch = static function ( array &$out, int $pid ): void {
            if ( $pid > 0 && ! isset( $out[ $pid ] ) ) {
                $out[ $pid ] = [
                    'player_id'     => $pid,
                    'goals'         => 0,
                    'assists'       => 0,
                    'own_goals'     => 0,
                    'contributions' => 0,
                ];
            }
        };

        foreach ( $rows as $row ) {
            $scorer  = (int) ( $row['player_id'] ?? 0 );
            $assist  = (int) ( $row['assist_player_id'] ?? 0 );
            $own     = ! empty( $row['is_own_goal'] );
            $is_ours = ( (string) ( $row['team'] ?? 'home' ) === 'home' );

            if ( $scorer > 0 && $own ) {
                $touch( $out, $scorer );
                $out[ $scorer ]['own_goals']++;
            } elseif ( $scorer > 0 && $is_ours ) {
                $touch( $out, $scorer );
                $out[ $scorer ]['goals']++;
            }

            if ( $assist > 0 ) {
                $touch( $out, $assist );
                $out[ $assist ]['assists']++;
            }
        }

        foreach ( $out as $pid => $row ) {
            $out[ $pid ]['contributions'] = $row['goals'] + $row['assists'];
        }

        return $out;
    }

    /**
     * Non-reversed goal events joined to their activity, club-scoped and
     * windowed. One query; the counting happens above so both entry points
     * apply exactly the same rules.
     *
     * Rows come back as associative arrays rather than objects: every column
     * here is read through a `??` default, and an array says that plainly
     * where a stdClass only says it to the reader who already knows.
     *
     * @param array{from?:string, to?:string, team_id?:int, player_id?:int} $filters
     * @return array<int, array<string, mixed>>
     */
    private function fetchEvents( array $filters ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        $from = isset( $filters['from'] ) ? (string) $filters['from'] : '';
        $to   = isset( $filters['to'] ) ? (string) $filters['to'] : '';
        $team = isset( $filters['team_id'] ) ? (int) $filters['team_id'] : 0;

        $types = implode( ',', array_fill( 0, count( self::MATCH_TYPES ), '%s' ) );
        $params = array_merge( [ $club_id, $club_id ], self::MATCH_TYPES );

        $where = "ge.club_id = %d
                    AND ge.reversed_at IS NULL
                    AND a.club_id = %d
                    AND LOWER(a.activity_type_key) IN ({$types})
                    AND a.archived_at IS NULL
                    AND a.trashed_at IS NULL
                    AND a.plan_state <> 'cancelled'
                    AND ( a.activity_status_key IS NULL OR a.activity_status_key <> 'cancelled' )";

        if ( $team > 0 ) {
            $where .= ' AND a.team_id = %d';
            $params[] = $team;
        }
        if ( $from !== '' && $to !== '' ) {
            $where .= ' AND a.session_date BETWEEN %s AND %s';
            $params[] = $from;
            $params[] = $to;
        }
        // A player profile only needs the events that mention them. Narrowing
        // here rather than in PHP keeps the read proportional to one player's
        // contribution instead of the whole club's goal log.
        $player = isset( $filters['player_id'] ) ? (int) $filters['player_id'] : 0;
        if ( $player > 0 ) {
            $where .= ' AND ( ge.player_id = %d OR ge.assist_player_id = %d )';
            $params[] = $player;
            $params[] = $player;
        }

        // #3094 — joined on the goal's own `activity_id` rather than through
        // its execution. The old INNER JOIN was what made a goal impossible
        // to record without a live match sheet: no execution, no row in the
        // result, and a club that does its admin on a Sunday evening had
        // players whose output was permanently blank.
        //
        // A manual goal has no half and no minute, so both sort NULL. MySQL
        // sorts NULL first ascending, which puts a remembered goal ahead of
        // the observed ones within its own match — the honest order, since
        // nobody knows when it happened.
        $sql = "SELECT ge.player_id, ge.assist_player_id, ge.is_own_goal, ge.team,
                       a.id AS activity_id, a.session_date, a.team_id
                  FROM {$p}tt_match_execution_goal_events ge
            INNER JOIN {$p}tt_activities a
                    ON a.id = ge.activity_id AND a.club_id = ge.club_id
                 WHERE {$where}
              ORDER BY a.session_date ASC, ge.half ASC, ge.minute_in_half ASC, ge.id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }
}
