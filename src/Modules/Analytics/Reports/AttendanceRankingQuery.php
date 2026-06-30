<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\AttendanceFlagService;

/**
 * AttendanceRankingQuery (#1488) — the single source of truth for the
 * per-player attendance aggregation that powers three surfaces:
 *
 *   - the player attendance report (`FrontendAttendancePlayerReportView`),
 *   - the dedicated leaderboard (`FrontendAttendanceLeaderboardView`),
 *   - the REST surface (`ReportsRestController`:
 *     `GET /reports/attendance-leaderboard`, `/reports/attendance-at-risk`).
 *
 * Keeping the SQL + ranking + flag logic here (and out of the view)
 * satisfies CLAUDE.md §4 — a future SaaS frontend consuming the REST
 * API gets identical answers to the PHP-rendered report.
 *
 * Window + scope:
 *   - `from` / `to` are `Y-m-d`; rows count completed, actual,
 *     non-guest attendance on non-archived activities / players —
 *     the same filters the report has always applied.
 *   - `allowed_team_ids` mirrors the analytics team-scope: `null` =
 *     unrestricted (global-scope read on `activities` — #1942); a
 *     non-empty list narrows to those teams; an empty list returns
 *     nothing.
 *   - Tenant-scoped on `tt_players.club_id` (no-op single-tenant
 *     today; structural for SaaS).
 *
 * "Missed" + the flag threshold are delegated to AttendanceFlagService
 * so the report badge / panel, this query, and the Comms cron can never
 * drift apart.
 */
final class AttendanceRankingQuery {

    /**
     * Per-player attendance rows for the window, each enriched with a
     * derived `present_pct` (null when the player has no rows), a
     * `missed` count, and a boolean `flagged`.
     *
     * Default order is worst-attendance-first (lowest present %),
     * no-data rows last — the order the report + leaderboard both want.
     *
     * @param list<int>|null $allowed_team_ids
     * @param string $activity_type_key  when non-empty, narrows to that
     *        activity type (e.g. training / game) — #2136.
     * @return list<array{
     *     player_id:int, first_name:string, last_name:string, team_name:string,
     *     activities:int, total:int,
     *     present:int, late:int, absent:int, excused:int, injured:int,
     *     present_pct:?float, missed:int, flagged:bool
     * }>
     */
    public function rows( string $from, string $to, int $team_id = 0, ?array $allowed_team_ids = null, string $activity_type_key = '' ): array {
        global $wpdb;

        if ( $allowed_team_ids !== null && $allowed_team_ids === [] ) {
            return [];
        }

        $where_team = $team_id > 0
            ? $wpdb->prepare( ' AND a.team_id = %d', $team_id )
            : '';

        // #2136 — optional activity-type narrowing, shared by the report
        // render + the REST surface so both apply the same filter.
        $where_type = $activity_type_key !== ''
            ? $wpdb->prepare( ' AND a.activity_type_key = %s', $activity_type_key )
            : '';

        $where_scope = '';
        if ( $allowed_team_ids !== null ) {
            $placeholders = implode( ',', array_fill( 0, count( $allowed_team_ids ), '%d' ) );
            $where_scope  = $wpdb->prepare( " AND a.team_id IN ($placeholders)", ...$allowed_team_ids );
        }

        /** @var object[] $raw */
        $raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                p.id   AS player_id,
                p.first_name,
                p.last_name,
                t.name AS team_name,
                COUNT(DISTINCT a.id) AS activities,
                COUNT(att.id) AS total,
                SUM( CASE WHEN LOWER(att.status) = 'present' THEN 1 ELSE 0 END ) AS present,
                SUM( CASE WHEN LOWER(att.status) = 'late'    THEN 1 ELSE 0 END ) AS late,
                SUM( CASE WHEN LOWER(att.status) = 'absent'  THEN 1 ELSE 0 END ) AS absent,
                SUM( CASE WHEN LOWER(att.status) = 'excused' THEN 1 ELSE 0 END ) AS excused,
                SUM( CASE WHEN LOWER(att.status) = 'injured' THEN 1 ELSE 0 END ) AS injured
              FROM {$wpdb->prefix}tt_attendance att
              JOIN {$wpdb->prefix}tt_activities a ON a.id = att.activity_id AND a.archived_at IS NULL
              JOIN {$wpdb->prefix}tt_players    p ON p.id = att.player_id  AND p.archived_at IS NULL
              LEFT JOIN {$wpdb->prefix}tt_teams t ON t.id = p.team_id
             WHERE p.club_id = %d
               AND att.is_guest = 0
               AND att.record_type = 'actual'
               AND a.session_date BETWEEN %s AND %s
               AND a.plan_state = 'completed'
               AND a.session_date <= CURDATE()
               {$where_team}
               {$where_type}
               {$where_scope}
             GROUP BY p.id, p.first_name, p.last_name, t.name
             ORDER BY p.last_name, p.first_name",
            CurrentClub::id(), $from, $to
        ) );
        if ( ! is_array( $raw ) || $raw === [] ) return [];

        $threshold = AttendanceFlagService::threshold();
        $rows = [];
        foreach ( $raw as $r ) {
            $total   = (int) ( $r->total ?? 0 );
            $present = (int) ( $r->present ?? 0 );
            $missed  = AttendanceFlagService::missed( $r );
            $rows[]  = [
                'player_id'   => (int) $r->player_id,
                'first_name'  => (string) ( $r->first_name ?? '' ),
                'last_name'   => (string) ( $r->last_name ?? '' ),
                'team_name'   => (string) ( $r->team_name ?? '' ),
                'activities'  => (int) ( $r->activities ?? 0 ),
                'total'       => $total,
                'present'     => $present,
                'late'        => (int) ( $r->late ?? 0 ),
                'absent'      => (int) ( $r->absent ?? 0 ),
                'excused'     => (int) ( $r->excused ?? 0 ),
                'injured'     => (int) ( $r->injured ?? 0 ),
                'present_pct' => $total > 0 ? round( ( $present / $total ) * 100, 1 ) : null,
                'missed'      => $missed,
                'flagged'     => $missed >= $threshold,
            ];
        }

        usort( $rows, [ self::class, 'compareWorstFirst' ] );
        return $rows;
    }

    /**
     * The at-risk subset: players flagged on the configurable
     * missed-activities threshold, ordered worst-first by missed count.
     * Optionally augmented with a declining-trend marker.
     *
     * @param list<int>|null $allowed_team_ids
     * @return list<array<string,mixed>>  rows from {@see rows()} that are flagged,
     *                                     each carrying an extra `declining` bool.
     */
    public function atRisk( string $from, string $to, int $team_id = 0, ?array $allowed_team_ids = null, string $activity_type_key = '' ): array {
        $rows    = $this->rows( $from, $to, $team_id, $allowed_team_ids, $activity_type_key );
        $at_risk = [];
        foreach ( $rows as $row ) {
            if ( empty( $row['flagged'] ) ) continue;
            $row['declining'] = $this->isDeclining( (int) $row['player_id'], $to );
            $at_risk[] = $row;
        }
        usort( $at_risk, static fn( $a, $b ) => (int) $b['missed'] <=> (int) $a['missed'] );
        return $at_risk;
    }

    /**
     * League-table slice for the leaderboard view: the bottom `n`
     * (worst attendance) and the top `n` (best attendance) players who
     * have at least one recorded activity in the window.
     *
     * @param list<int>|null $allowed_team_ids
     * @return array{bottom:list<array<string,mixed>>, top:list<array<string,mixed>>, total:int}
     */
    public function leaderboard( string $from, string $to, int $n = 10, int $team_id = 0, ?array $allowed_team_ids = null, string $activity_type_key = '' ): array {
        $n    = max( 1, min( 50, $n ) );
        $rows = array_values( array_filter(
            $this->rows( $from, $to, $team_id, $allowed_team_ids, $activity_type_key ),
            static fn( array $r ): bool => $r['present_pct'] !== null
        ) );
        // rows() is already worst-first.
        $bottom = array_slice( $rows, 0, $n );
        $top    = array_slice( array_reverse( $rows ), 0, $n );
        return [
            'bottom' => $bottom,
            'top'    => $top,
            'total'  => count( $rows ),
        ];
    }

    /**
     * Declining-trend heuristic: of the player's last six completed,
     * actual, non-guest activities (up to `$as_of`), the most recent
     * three contain strictly more misses than the prior three. Returns
     * false when there isn't enough history to judge.
     */
    private function isDeclining( int $player_id, string $as_of ): bool {
        global $wpdb;
        /** @var object[] $recent */
        $recent = $wpdb->get_results( $wpdb->prepare(
            "SELECT LOWER(att.status) AS status
               FROM {$wpdb->prefix}tt_attendance att
               JOIN {$wpdb->prefix}tt_activities a ON a.id = att.activity_id AND a.archived_at IS NULL
              WHERE att.player_id = %d
                AND att.is_guest = 0
                AND att.record_type = 'actual'
                AND a.plan_state = 'completed'
                AND a.session_date <= %s
                AND a.session_date <= CURDATE()
              ORDER BY a.session_date DESC, a.id DESC
              LIMIT 6",
            $player_id, $as_of
        ) );
        if ( ! is_array( $recent ) || count( $recent ) < 6 ) return false;

        $missed = static fn( string $s ): int => in_array( $s, [ 'absent', 'excused', 'injured' ], true ) ? 1 : 0;
        $recent_missed = 0;
        $prior_missed  = 0;
        foreach ( $recent as $i => $row ) {
            $m = $missed( (string) ( $row->status ?? '' ) );
            if ( $i < 3 ) { $recent_missed += $m; } else { $prior_missed += $m; }
        }
        return $recent_missed > $prior_missed;
    }

    /**
     * Worst-attendance-first comparator. No-data rows (null present %)
     * sort last; ties break alphabetically by last then first name.
     */
    private static function compareWorstFirst( array $a, array $b ): int {
        $pa = $a['present_pct'] ?? 1000.0;
        $pb = $b['present_pct'] ?? 1000.0;
        if ( $pa === $pb ) {
            $cmp = strcasecmp( (string) $a['last_name'], (string) $b['last_name'] );
            return $cmp !== 0 ? $cmp : strcasecmp( (string) $a['first_name'], (string) $b['first_name'] );
        }
        return $pa <=> $pb;
    }
}
