<?php
namespace TT\Modules\MatchExecution\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MatchExecutionRepository — single class spanning the three live-
 * match tables. Substitution + goal-event tables are append-only;
 * `reversed_at` is the soft-delete for undo.
 *
 * v4.12.5 (#988 PR-set 6) — the canonical match-execution state values
 * moved into `TT\Domain\Vocabularies\Enums\MatchExecutionState`. The
 * `STATE_*` constants below alias the new enum for one release per
 * #988's locked plan, and will be removed in the next minor; new code
 * should reference `MatchExecutionState::*` directly.
 */
class MatchExecutionRepository {

    /** @deprecated since v4.12.5 — use {@see MatchExecutionState::NOT_STARTED}; removed in next minor. */
    public const STATE_NOT_STARTED = MatchExecutionState::NOT_STARTED;

    /** @deprecated since v4.12.5 — use {@see MatchExecutionState::FIRST_HALF}; removed in next minor. */
    public const STATE_FIRST_HALF  = MatchExecutionState::FIRST_HALF;

    /** @deprecated since v4.12.5 — use {@see MatchExecutionState::HALF_TIME}; removed in next minor. */
    public const STATE_HALF_TIME   = MatchExecutionState::HALF_TIME;

    /** @deprecated since v4.12.5 — use {@see MatchExecutionState::SECOND_HALF}; removed in next minor. */
    public const STATE_SECOND_HALF = MatchExecutionState::SECOND_HALF;

    /** @deprecated since v4.12.5 — use {@see MatchExecutionState::FINISHED}; removed in next minor. */
    public const STATE_FINISHED    = MatchExecutionState::FINISHED;

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
            'state'         => MatchExecutionState::NOT_STARTED,
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
     * Logged playing minutes per player for a match, read from the
     * persisted tt_attendance.minutes_played the finish / finalize step
     * writes (recomputeAttendanceAndMinutes). This is the same single
     * source of truth the minutes report reads (Analytics MinutesQuery),
     * so the execution view and the report always agree. Players without
     * a recorded value are omitted — callers render a dash / nothing.
     *
     * @return array<int, int> player_id => minutes_played
     */
    public function loggedMinutesByActivity( int $activity_id ): array {
        if ( $activity_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT player_id, minutes_played
               FROM {$this->wpdb->prefix}tt_attendance
              WHERE activity_id = %d
                AND club_id = %d
                AND is_guest = 0
                AND minutes_played IS NOT NULL
                AND minutes_played > 0",
            $activity_id, CurrentClub::id()
        ) );
        $map = [];
        foreach ( (array) $rows as $r ) {
            $pid = (int) $r->player_id;
            if ( $pid > 0 ) $map[ $pid ] = (int) $r->minutes_played;
        }
        return $map;
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

    /**
     * #1048 — recompute attendance + minutes from the prep snapshot +
     * the current substitution log, and write the result to
     * `tt_attendance`. Extracted from the inline block in the original
     * `route_finish` (v4.15.0) so the same logic can fire on
     * PENDING_REVIEW edits and on Finalize.
     *
     * Idempotent: same prep + same sub log → same attendance rows. On
     * subsequent invocations the existing rows are UPDATEd in place;
     * orphans (attendance rows for players no longer in the
     * availability list — the v3 sweep added in #1032) are DELETEd.
     *
     * The user's #1048 decision (2026-05-30): on recompute failure
     * (DB error mid-write), this method swallows the error, logs it
     * at warn level, and returns false. The caller's primary action
     * (the edit) has already succeeded; aborting the edit because a
     * side-effect failed would be hostile, and the recompute will
     * fire again on the next edit or on finalize.
     *
     * @return bool true on success, false on caught exception.
     */
    public function recomputeAttendanceAndMinutes( int $execution_id ): bool {
        try {
            $exec = $this->wpdb->get_row( $this->wpdb->prepare(
                "SELECT id, activity_id FROM {$this->t_exec} WHERE id = %d AND club_id = %d",
                $execution_id, CurrentClub::id()
            ) );
            if ( ! $exec ) return false;
            $activity_id = (int) $exec->activity_id;

            $prep_repo = new MatchPrepRepository();
            $prep      = $prep_repo->findByActivity( $activity_id );
            if ( ! $prep ) return false;

            $prep_id = (int) $prep->id;
            $avail   = $prep_repo->listAvailability( $prep_id );
            $lineup  = $prep_repo->listLineup( $prep_id );

            $start1 = [];
            $start2 = [];
            foreach ( $lineup as $l ) {
                if ( (int) $l->half === 1 ) $start1[] = (int) $l->player_id;
                if ( (int) $l->half === 2 ) $start2[] = (int) $l->player_id;
            }

            $minutes_map = $this->computeMinutes(
                $execution_id,
                $start1,
                $start2,
                (int) $prep->half_length_minutes,
                (int) $prep->half_length_minutes
            );

            global $wpdb;
            $p = $wpdb->prefix;

            // #1032 — reconcile stale attendance rows before re-writing.
            $avail_pids = [];
            foreach ( $avail as $a ) {
                $pid = (int) $a->player_id;
                if ( $pid > 0 ) $avail_pids[] = $pid;
            }
            if ( ! empty( $avail_pids ) ) {
                $in = implode( ',', array_fill( 0, count( $avail_pids ), '%d' ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$p}tt_attendance
                      WHERE activity_id = %d
                        AND club_id     = %d
                        AND player_id NOT IN ($in)",
                    array_merge( [ $activity_id, CurrentClub::id() ], $avail_pids )
                ) );
            }

            foreach ( $avail as $a ) {
                $pid    = (int) $a->player_id;
                $status = (string) $a->status;
                if ( strcasecmp( $status, 'Present' ) === 0 ) {
                    $status = 'Present';
                }
                $minutes = $minutes_map[ $pid ] ?? 0;
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}tt_attendance
                      WHERE activity_id = %d AND player_id = %d AND club_id = %d LIMIT 1",
                    $activity_id, $pid, CurrentClub::id()
                ) );
                if ( $existing ) {
                    $wpdb->update( "{$p}tt_attendance", [
                        'status'         => $status,
                        'minutes_played' => $minutes,
                    ], [ 'id' => (int) $existing ] );
                } else {
                    $wpdb->insert( "{$p}tt_attendance", [
                        'club_id'        => CurrentClub::id(),
                        'activity_id'    => $activity_id,
                        'player_id'      => $pid,
                        'status'         => $status,
                        'minutes_played' => $minutes,
                    ] );
                }
            }
            return true;
        } catch ( \Throwable $e ) {
            // #1048 — swallow + log; the caller's edit succeeded, the
            // recompute is a side effect that will re-fire on the
            // next edit / finalize.
            Logger::warn( 'match_execution.recompute_failed', [
                'execution_id' => $execution_id,
                'message'      => $e->getMessage(),
            ] );
            return false;
        }
    }
}
