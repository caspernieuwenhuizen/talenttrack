<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * EvalCoverageService (#1380) — answers "which players have NOT been
 * evaluated this window, and which coach owns the gap?".
 *
 * Config-based windows (EvalWindowsRepository), no new entity, no
 * reminders. All reads are club-scoped via CurrentClub::id(). This is
 * the single source of truth for both the REST controller and the PHP
 * view per CLAUDE.md §4 — no coverage logic lives in the renderer.
 *
 * @phpstan-type EvalWindow array{name:string,start:string,end:string}
 * @phpstan-type CoverageCell array{covered:bool,evaluator_name:string}
 * @phpstan-type CoveragePlayer array{player_id:int,player_name:string,coach_id:int,coach_name:string,cells:list<CoverageCell>,gap_count:int}
 * @phpstan-type CoverageTeam array{team_id:int,team_name:string,players:list<CoveragePlayer>}
 * @phpstan-type CoachGap array{coach_id:int,coach_name:string,gap_count:int}
 * @phpstan-type CoverageData array{windows:list<EvalWindow>,teams:list<CoverageTeam>,coach_gaps:list<CoachGap>,total_players:int,total_gaps:int}
 * @phpstan-type AttendanceRow array{team_id:int,team_name:string,completed:int,with_attendance:int,percent:float|null}
 * @phpstan-type Evaluator array{coach_id:int,coach_name:string}
 */
final class EvalCoverageService {

    private EvalWindowsRepository $windows;

    public function __construct( ?EvalWindowsRepository $windows = null ) {
        $this->windows = $windows ?? new EvalWindowsRepository();
    }

    /**
     * The configured evaluation windows for the current season.
     *
     * @return list<EvalWindow>
     */
    public function windows(): array {
        return $this->windows->all();
    }

    /**
     * Build the coverage matrix: players (grouped by team) × windows,
     * each cell flagged covered / gap, plus per-coach gap counts.
     *
     * `$season_id` is accepted for forward compatibility with a future
     * seasons entity; today the windows config IS the current season, so
     * the parameter is unused and the data is club-scoped only.
     *
     * @return CoverageData
     */
    public function coverage( int $season_id = 0 ): array {
        unset( $season_id );
        $windows = $this->windows();
        $players = $this->fetchPlayers();
        $covered = $windows === [] ? [] : $this->fetchCoveredEvaluations( $windows );

        /** @var array<int,CoverageTeam> $teams */
        $teams = [];
        /** @var array<int,CoachGap> $coach_gaps */
        $coach_gaps = [];
        $total_gaps = 0;

        foreach ( $players as $p ) {
            /** @var list<CoverageCell> $cells */
            $cells     = [];
            $gap_count = 0;
            foreach ( $windows as $i => $_window ) {
                $hit       = $covered[ $p['player_id'] ][ $i ] ?? null;
                $is_hit    = $hit !== null;
                $cells[]   = [
                    'covered'        => $is_hit,
                    'evaluator_name' => $is_hit ? (string) $hit : '',
                ];
                if ( ! $is_hit ) {
                    $gap_count++;
                    $total_gaps++;
                }
            }

            $team_id = $p['team_id'];
            if ( ! isset( $teams[ $team_id ] ) ) {
                $teams[ $team_id ] = [
                    'team_id'   => $team_id,
                    'team_name' => $p['team_name'],
                    'players'   => [],
                ];
            }
            $teams[ $team_id ]['players'][] = [
                'player_id'   => $p['player_id'],
                'player_name' => $p['player_name'],
                'coach_id'    => $p['coach_id'],
                'coach_name'  => $p['coach_name'],
                'cells'       => $cells,
                'gap_count'   => $gap_count,
            ];

            // Per-coach gap tally — the coach who owns the player's team
            // owns the gap. Players with no head coach roll up under a
            // synthetic "Unassigned" bucket (coach_id 0).
            if ( $gap_count > 0 ) {
                $cid = $p['coach_id'];
                if ( ! isset( $coach_gaps[ $cid ] ) ) {
                    $coach_gaps[ $cid ] = [
                        'coach_id'   => $cid,
                        'coach_name' => $p['coach_name'],
                        'gap_count'  => 0,
                    ];
                }
                $coach_gaps[ $cid ]['gap_count'] += $gap_count;
            }
        }

        // Re-index teams + sort coach gaps high-to-low.
        $team_list = array_values( $teams );
        $coach_gap_list = array_values( $coach_gaps );
        usort( $coach_gap_list, static fn( array $a, array $b ): int => $b['gap_count'] <=> $a['gap_count'] );

        return [
            'windows'       => $windows,
            'teams'         => $team_list,
            'coach_gaps'    => $coach_gap_list,
            'total_players' => count( $players ),
            'total_gaps'    => $total_gaps,
        ];
    }

    /**
     * Per-team attendance-recording compliance for a single window:
     * the share of COMPLETED activities that have ANY attendance row.
     *
     * A coach who never records attendance (low %, real activities)
     * reads differently from a team with no completed activity at all
     * (activities = 0, percent = null).
     *
     * @param EvalWindow $window
     * @return list<AttendanceRow>
     */
    public function attendanceCompliance( array $window ): array {
        global $wpdb;
        $start = (string) ( $window['start'] ?? '' );
        $end   = (string) ( $window['end'] ?? '' );
        if ( $start === '' || $end === '' ) return [];

        /** @var list<object> $rows */
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                t.id   AS team_id,
                t.name AS team_name,
                COUNT(DISTINCT a.id) AS completed,
                COUNT(DISTINCT CASE WHEN att.id IS NOT NULL THEN a.id END) AS with_attendance
              FROM {$wpdb->prefix}tt_teams t
              JOIN {$wpdb->prefix}tt_activities a
                ON a.team_id = t.id
               AND a.archived_at IS NULL
               AND a.plan_state = 'completed'
               AND a.session_date BETWEEN %s AND %s
              LEFT JOIN {$wpdb->prefix}tt_attendance att
                ON att.activity_id = a.id
               AND att.record_type = 'actual'
               AND att.is_guest = 0
             WHERE t.club_id = %d
               AND t.archived_at IS NULL
             GROUP BY t.id, t.name
             ORDER BY t.name ASC",
            $start, $end, CurrentClub::id()
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $completed = (int) ( $row->completed ?? 0 );
            $with      = (int) ( $row->with_attendance ?? 0 );
            $out[] = [
                'team_id'         => (int) ( $row->team_id ?? 0 ),
                'team_name'       => (string) ( $row->team_name ?? '' ),
                'completed'       => $completed,
                'with_attendance' => $with,
                'percent'         => $completed > 0 ? round( $with / $completed * 100, 1 ) : null,
            ];
        }
        return $out;
    }

    /**
     * Distinct coaches who own at least one evaluation, for the coach
     * filter on the evaluations list.
     *
     * @return list<Evaluator>
     */
    public function evaluators(): array {
        global $wpdb;
        /** @var list<object> $rows */
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT e.coach_id, u.display_name
               FROM {$wpdb->prefix}tt_evaluations e
               JOIN {$wpdb->users} u ON u.ID = e.coach_id
              WHERE e.club_id = %d
                AND e.archived_at IS NULL
                AND e.coach_id > 0
              ORDER BY u.display_name ASC",
            CurrentClub::id()
        ) );
        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[] = [
                'coach_id'   => (int) ( $row->coach_id ?? 0 ),
                'coach_name' => (string) ( $row->display_name ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Active players grouped-flat with their team's head coach.
     *
     * The head coach is resolved through the team-staff path that
     * replaced the retired `tt_teams.head_coach_id` column (#1315):
     * `tt_team_people` joined to `tt_functional_roles.role_key =
     * 'head_coach'` → `tt_people` (and its `wp_user_id`). The coach map
     * is built once and applied per player so the query stays one row
     * per player.
     *
     * @return list<array{
     *   player_id:int,player_name:string,
     *   team_id:int,team_name:string,
     *   coach_id:int,coach_name:string
     * }>
     */
    private function fetchPlayers(): array {
        global $wpdb;

        $coaches = $this->fetchHeadCoaches();

        /** @var list<object> $rows */
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                p.id AS player_id,
                p.first_name,
                p.last_name,
                t.id   AS team_id,
                t.name AS team_name
              FROM {$wpdb->prefix}tt_players p
              JOIN {$wpdb->prefix}tt_teams t
                ON t.id = p.team_id
               AND t.club_id = p.club_id
               AND t.archived_at IS NULL
             WHERE p.club_id = %d
               AND p.status = 'active'
               AND p.archived_at IS NULL
             ORDER BY t.name ASC, p.last_name ASC, p.first_name ASC",
            CurrentClub::id()
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $first   = (string) ( $row->first_name ?? '' );
            $last    = (string) ( $row->last_name ?? '' );
            $name    = trim( $first . ' ' . $last );
            $team_id = (int) ( $row->team_id ?? 0 );
            $coach   = $coaches[ $team_id ] ?? [ 'coach_id' => 0, 'coach_name' => '' ];
            $out[] = [
                'player_id'   => (int) ( $row->player_id ?? 0 ),
                'player_name' => $name !== '' ? $name : '#' . (int) ( $row->player_id ?? 0 ),
                'team_id'     => $team_id,
                'team_name'   => (string) ( $row->team_name ?? '' ),
                'coach_id'    => $coach['coach_id'],
                'coach_name'  => $coach['coach_name'],
            ];
        }
        return $out;
    }

    /**
     * Map team_id → its head coach (wp_user_id + display name). Where a
     * team has several head-coach assignments, the first by surname wins.
     *
     * @return array<int,array{coach_id:int,coach_name:string}>
     */
    private function fetchHeadCoaches(): array {
        global $wpdb;
        /** @var list<object> $rows */
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tp.team_id, pe.wp_user_id, pe.first_name, pe.last_name
               FROM {$wpdb->prefix}tt_team_people tp
               JOIN {$wpdb->prefix}tt_functional_roles fr
                 ON fr.id = tp.functional_role_id
                AND fr.club_id = tp.club_id
                AND fr.role_key = 'head_coach'
               JOIN {$wpdb->prefix}tt_people pe
                 ON pe.id = tp.person_id
                AND pe.club_id = tp.club_id
              WHERE tp.club_id = %d
              ORDER BY tp.team_id ASC, pe.last_name ASC, pe.first_name ASC",
            CurrentClub::id()
        ) );

        $map = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $team_id = (int) ( $row->team_id ?? 0 );
            if ( $team_id <= 0 || isset( $map[ $team_id ] ) ) continue;
            $first = (string) ( $row->first_name ?? '' );
            $last  = (string) ( $row->last_name ?? '' );
            $name  = trim( $first . ' ' . $last );
            $map[ $team_id ] = [
                'coach_id'   => (int) ( $row->wp_user_id ?? 0 ),
                'coach_name' => $name,
            ];
        }
        return $map;
    }

    /**
     * For each window, the players with at least one evaluation whose
     * eval_date falls in [start,end]. Returns a map
     * player_id → window_index → evaluator display name (the most recent
     * evaluator in that window).
     *
     * @param list<array{name:string,start:string,end:string}> $windows
     * @return array<int,array<int,string>>
     */
    private function fetchCoveredEvaluations( array $windows ): array {
        global $wpdb;

        // Bound the scan to the overall span the windows cover, then
        // bucket each evaluation into the windows it lands in (windows
        // may overlap, so a single eval can satisfy more than one).
        $min = null;
        $max = null;
        foreach ( $windows as $w ) {
            if ( $min === null || $w['start'] < $min ) $min = $w['start'];
            if ( $max === null || $w['end']   > $max ) $max = $w['end'];
        }
        if ( $min === null || $max === null ) return [];

        /** @var list<object> $rows */
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.player_id, e.eval_date, u.display_name AS evaluator_name
               FROM {$wpdb->prefix}tt_evaluations e
               LEFT JOIN {$wpdb->users} u ON u.ID = e.coach_id
              WHERE e.club_id = %d
                AND e.archived_at IS NULL
                AND e.eval_date BETWEEN %s AND %s
              ORDER BY e.eval_date ASC",
            CurrentClub::id(), $min, $max
        ) );

        $map = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $pid  = (int) ( $row->player_id ?? 0 );
            $date = (string) ( $row->eval_date ?? '' );
            $name = (string) ( $row->evaluator_name ?? '' );
            if ( $pid <= 0 || $date === '' ) continue;
            foreach ( $windows as $i => $w ) {
                if ( $date >= $w['start'] && $date <= $w['end'] ) {
                    // ORDER BY eval_date ASC means later rows overwrite —
                    // the cell shows the most recent evaluator in-window.
                    $map[ $pid ][ $i ] = $name;
                }
            }
        }
        return $map;
    }
}
