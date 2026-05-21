<?php
namespace TT\Modules\MatchExecution\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MatchExecutionRepository — single class spanning the three live-
 * match tables. Substitution + goal-event tables are append-only;
 * `reversed_at` is the soft-delete for undo.
 */
class MatchExecutionRepository {

    private \wpdb $wpdb;
    private string $t_exec;
    private string $t_subs;
    private string $t_goals;

    public function __construct() {
        global $wpdb;
        $this->wpdb    = $wpdb;
        $this->t_exec  = $wpdb->prefix . 'tt_match_execution';
        $this->t_subs  = $wpdb->prefix . 'tt_match_execution_substitutions';
        $this->t_goals = $wpdb->prefix . 'tt_match_execution_goal_events';
    }

    public function findByActivity( int $activity_id ): ?object {
        if ( $activity_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_exec} WHERE activity_id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * #879 — find the most-recently-updated live execution on one of
     * the supplied teams. "Live" means state ∈ {first_half, half_time,
     * second_half}. Returns null when none exists. Used by the coach
     * hero to surface a "Resume match" CTA.
     *
     * Returned row is enriched with the activity's title, opponent,
     * session_date, team_id and home/away score so the hero can render
     * the eyebrow + title + detail without a second query.
     *
     * @param list<int> $team_ids
     */
    public function findLiveForTeams( array $team_ids ): ?object {
        if ( $team_ids === [] ) return null;
        $club_id = (int) CurrentClub::id();
        $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
        $params = array_merge( [ $club_id ], array_map( 'intval', $team_ids ), [ $club_id ] );

        $activities = $this->wpdb->prefix . 'tt_activities';
        $sql = "SELECT e.*, a.title, a.opponent, a.session_date, a.team_id, a.location
                  FROM {$this->t_exec} e
                  INNER JOIN {$activities} a ON a.id = e.activity_id AND a.club_id = e.club_id
                 WHERE e.club_id = %d
                   AND a.team_id IN ({$placeholders})
                   AND e.state IN ('first_half','half_time','second_half')
                   AND a.club_id = %d
                 ORDER BY e.updated_at DESC, e.id DESC
                 LIMIT 1";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, ...$params ) );
        return $row ?: null;
    }

    /**
     * #879 — find a startable match today for one of the supplied teams.
     * Conditions: `session_date = today`, activity_type_key ∈
     * {'match','game'}, has a `tt_match_prep` row, and either no
     * execution row or `state IS NULL` / `'not_started'`.
     *
     * Returns the earliest-kickoff row enriched with title + opponent +
     * session_date + start_time + team_id + location. Used by the coach
     * hero to surface a "Start match" CTA on the day of a prepped match.
     *
     * @param list<int> $team_ids
     */
    public function findStartableForTeams( array $team_ids ): ?object {
        if ( $team_ids === [] ) return null;
        $club_id = (int) CurrentClub::id();
        $today   = current_time( 'Y-m-d' );
        $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );

        $activities = $this->wpdb->prefix . 'tt_activities';
        $prep       = $this->wpdb->prefix . 'tt_match_prep';

        // Params: club_id (activities), team_ids…, today, club_id (exec scope).
        $params = array_merge( [ $club_id ], array_map( 'intval', $team_ids ), [ $today, $club_id ] );

        $sql = "SELECT a.id AS activity_id, a.title, a.opponent, a.session_date,
                       a.start_time, a.team_id, a.location, a.activity_type_key,
                       p.id AS prep_id,
                       e.state AS exec_state
                  FROM {$activities} a
                  INNER JOIN {$prep} p ON p.activity_id = a.id AND p.club_id = a.club_id
                  LEFT JOIN  {$this->t_exec} e ON e.activity_id = a.id AND e.club_id = a.club_id
                 WHERE a.club_id = %d
                   AND a.team_id IN ({$placeholders})
                   AND a.session_date = %s
                   AND a.activity_type_key IN ('match','game')
                   AND a.archived_at IS NULL
                   AND ( e.state IS NULL OR e.state = 'not_started' )
                   AND a.club_id = %d
                 ORDER BY a.start_time ASC, a.id ASC
                 LIMIT 1";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, ...$params ) );
        return $row ?: null;
    }

    public function ensureForActivity( int $activity_id, int $match_prep_id ): int {
        $existing = $this->findByActivity( $activity_id );
        if ( $existing ) return (int) $existing->id;

        $this->wpdb->insert( $this->t_exec, [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => CurrentClub::id(),
            'activity_id'   => $activity_id,
            'match_prep_id' => $match_prep_id,
            'state'         => 'not_started',
            'created_by'    => get_current_user_id(),
        ] );
        return (int) $this->wpdb->insert_id;
    }

    /** @param array<string,mixed> $patch */
    public function update( int $id, array $patch ): bool {
        if ( $id <= 0 || empty( $patch ) ) return false;
        $allowed = [
            'state',
            'first_half_started_at', 'first_half_ended_at',
            'second_half_started_at', 'second_half_ended_at',
            'first_half_pause_seconds', 'second_half_pause_seconds',
            'home_score', 'away_score',
        ];
        $clean = [];
        foreach ( $patch as $k => $v ) {
            if ( in_array( $k, $allowed, true ) ) $clean[ $k ] = $v;
        }
        if ( empty( $clean ) ) return false;
        return false !== $this->wpdb->update(
            $this->t_exec,
            $clean,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    public function logSubstitution( int $execution_id, string $event_uuid, int $half, int $minute, int $player_off_id, int $player_on_id ): bool {
        if ( $execution_id <= 0 || $event_uuid === '' || $player_off_id <= 0 || $player_on_id <= 0 ) return false;
        $ok = $this->wpdb->query( $this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->t_subs}
               (event_uuid, club_id, execution_id, half, minute_in_half, player_off_id, player_on_id)
             VALUES (%s, %d, %d, %d, %d, %d, %d)",
            $event_uuid, CurrentClub::id(), $execution_id, $half, max( 0, $minute ), $player_off_id, $player_on_id
        ) );
        return $ok !== false;
    }

    public function logGoalEvent( int $execution_id, string $event_uuid, int $player_id, int $half, int $minute ): bool {
        if ( $execution_id <= 0 || $event_uuid === '' || $player_id <= 0 ) return false;
        $ok = $this->wpdb->query( $this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->t_goals}
               (event_uuid, club_id, execution_id, player_id, half, minute_in_half)
             VALUES (%s, %d, %d, %d, %d, %d)",
            $event_uuid, CurrentClub::id(), $execution_id, $player_id, $half, max( 0, $minute )
        ) );
        return $ok !== false;
    }

    public function reverseGoalEvent( string $event_uuid ): bool {
        if ( $event_uuid === '' ) return false;
        return false !== $this->wpdb->update(
            $this->t_goals,
            [ 'reversed_at' => current_time( 'mysql', true ) ],
            [ 'event_uuid' => $event_uuid, 'club_id' => CurrentClub::id() ]
        );
    }

    /** @return object[] */
    public function listSubstitutions( int $execution_id ): array {
        if ( $execution_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_subs}
              WHERE execution_id = %d AND club_id = %d AND reversed_at IS NULL
              ORDER BY half ASC, minute_in_half ASC, id ASC",
            $execution_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return object[] */
    public function listGoalEvents( int $execution_id ): array {
        if ( $execution_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_goals}
              WHERE execution_id = %d AND club_id = %d AND reversed_at IS NULL
              ORDER BY half ASC, minute_in_half ASC, id ASC",
            $execution_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Compute per-player minutes from the substitution log + the half
     * lengths. Players who started the half + were never subbed off get
     * the full half length; subbed-off players get the minute they
     * came off; subbed-on players get half_length - minute_in_half.
     * Returns map player_id => minutes.
     *
     * @param list<int> $starting_xi_half1
     * @param list<int> $starting_xi_half2
     * @return array<int, int>
     */
    public function computeMinutes( int $execution_id, array $starting_xi_half1, array $starting_xi_half2, int $half1_length, int $half2_length ): array {
        $subs    = $this->listSubstitutions( $execution_id );
        $minutes = [];

        foreach ( [ 1 => [ $starting_xi_half1, $half1_length ], 2 => [ $starting_xi_half2, $half2_length ] ] as $half => $pair ) {
            [ $starting, $half_length ] = $pair;
            $on_pitch = array_fill_keys( $starting, 0 );
            $off_at   = []; // player_id => minute they came off
            $on_at    = []; // player_id => minute they came on

            foreach ( $subs as $sub ) {
                if ( (int) $sub->half !== $half ) continue;
                $minute = (int) $sub->minute_in_half;
                $off_at[ (int) $sub->player_off_id ] = $minute;
                $on_at[ (int) $sub->player_on_id ]   = $minute;
            }

            foreach ( $starting as $pid ) {
                $minutes_played = isset( $off_at[ $pid ] ) ? $off_at[ $pid ] : $half_length;
                $minutes[ $pid ] = ( $minutes[ $pid ] ?? 0 ) + $minutes_played;
            }
            foreach ( $on_at as $pid => $minute ) {
                if ( in_array( $pid, $starting, true ) ) continue; // already counted via starting
                $off_minute = $off_at[ $pid ] ?? $half_length;
                $minutes_played = max( 0, $off_minute - $minute );
                $minutes[ $pid ] = ( $minutes[ $pid ] ?? 0 ) + $minutes_played;
            }
        }

        return $minutes;
    }
}
