<?php
namespace TT\Modules\Activities\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\AttendanceDateRule;

/**
 * AttendanceGridQuery (#2382) — the players × activities matrix that backs
 * the desktop attendance-entry grid (epic #2381).
 *
 * The transpose of the read-only Minutes-audit matrix: here rows are the
 * team's ACTIVE roster (columns of a coach's Excel register) and columns are
 * the team's activities in the window (training + matches). Each cell is the
 * recorded `record_type='actual'` attendance status for that player at that
 * activity, or empty when nothing has been recorded yet.
 *
 * Primary-entry semantics (#2381): rows come from the ACTIVE roster
 * (QueryHelpers::get_players), NOT from existing attendance rows — so a
 * brand-new activity with no attendance still shows every player. This is
 * the deliberate difference from MinutesAuditQuery, which resolves its
 * rows from attendance because it audits what was recorded.
 *
 * #4009 — that on its own loses a previous season. The roster is the
 * player's CURRENT team with no date awareness, so after a summer's
 * age-group moves the rows and the cells were two disjoint sets of
 * players: every row rendered empty and last season's register was
 * unreachable. So the rows are the UNION of the active roster and the
 * players who actually have a recorded mark in the window, with the
 * latter flagged `on_current_roster: false` (carrying the team they are
 * on now) and `editable: false`. They are read-only on purpose: the
 * bulk-write guard refuses a mark for a player off the activity's team
 * and counts it as `skipped`, silently, so an editable control there
 * would be an edit that quietly does nothing.
 *
 * The flagged rows are derived from the attendance table rather than
 * from `tt_player_team_history`: nothing in production resolves a roster
 * as-at a date, and the archive cascade zeroes that history, so it
 * cannot be the only source anyway.
 *
 * Tenant-scoped on `club_id` (no-op single-tenant today; structural for the
 * SaaS migration, CLAUDE.md §4).
 */
final class AttendanceGridQuery {

    /**
     * #3656 — is this one activity a column in the grid?
     *
     * The same rule `matrix()` applies in SQL, asked about a single
     * activity: one dated today or earlier always is; a later one only
     * once it carries a recorded mark (a pre-recorded absence), which is
     * the only thing there is to see or clear on it.
     *
     * `ActivityGridLink` gates the deep-links into this grid on it, so the
     * affordance and the column it promises can't answer differently — a
     * coach opening next Monday's training used to land on "No activities
     * for this team in the chosen period".
     *
     * @param string      $session_date Y-m-d (a datetime is accepted).
     * @param string|null $today        Y-m-d; site time when null.
     */
    public static function isColumn( int $activity_id, string $session_date, ?string $today = null ): bool {
        if ( $activity_id <= 0 ) return false;
        $date = substr( trim( $session_date ), 0, 10 );
        if ( $date === '' ) return false;
        if ( ! AttendanceDateRule::isUpcoming( $date, $today ) ) return true;
        return self::hasRecordedMark( $activity_id );
    }

    /**
     * Does this activity carry a recorded register — a non-guest,
     * `record_type='actual'`, non-empty mark?
     */
    public static function hasRecordedMark( int $activity_id ): bool {
        if ( $activity_id <= 0 ) return false;
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT ' . self::recordedMarkExistsSql( '%d', '%d' ),
            $activity_id,
            (int) CurrentClub::id()
        ) );
    }

    /**
     * The "carries a recorded mark" existence test, as SQL. `matrix()`'s
     * column query and `hasRecordedMark()` both build from this one
     * fragment, which is what keeps the grid and its entry links in step.
     *
     * Both arguments are SQL expressions the two callers choose — a column
     * reference or a `prepare()` placeholder — never caller input.
     */
    private static function recordedMarkExistsSql( string $activity_expr, string $club_expr ): string {
        global $wpdb;
        return "EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_attendance a
                          WHERE a.activity_id = {$activity_expr} AND a.club_id = {$club_expr}
                            AND a.is_guest = 0 AND a.record_type = 'actual'
                            AND a.status <> '' )";
    }

    /**
     * Build the grid for a team over a window.
     *
     * `completes` (#2521) marks a column whose activity would change status
     * when the grid is saved: past-dated and not yet completed or cancelled.
     * The view uses it to flag the column and to name the affected sessions
     * in the confirmation before anything is written.
     *
     * @param string      $type_filter 'all' | 'training' | 'match'
     * @param string|null $today       Y-m-d; site time when null.
     * @return array{
     *   activities: list<array{ activity_id:int, session_date:string, title:string, type_key:string, is_match:bool, status_key:string, completes:bool }>,
     *   players: list<array{ player_id:int, first_name:string, last_name:string, jersey_number:?int, on_current_roster:bool, editable:bool, current_team_id:?int, current_team_name:string }>,
     *   cells: array<int, array<int, string>>,
     *   summary: array{ total_activities:int, total_players:int, former_squad_players:int }
     * }
     */
    public function matrix( int $team_id, string $from, string $to, string $type_filter = 'all', ?string $today = null ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        $empty = [
            'activities' => [],
            'players'    => [],
            'cells'      => [],
            'summary'    => [ 'total_activities' => 0, 'total_players' => 0, 'former_squad_players' => 0 ],
        ];
        if ( $team_id <= 0 ) return $empty;

        $date_col = 'sess' . 'ion_date'; // legacy date column (#0035 lint-safe)

        // Activity-type narrowing. 'match' folds the three game-ish types the
        // minutes surfaces use; 'training' is the single training type; 'all'
        // keeps both families (the two the entry grid is for).
        $match_types = "'match','game','tournament'";
        if ( $type_filter === 'match' ) {
            $where_type = " AND LOWER(activity_type_key) IN ($match_types)";
        } elseif ( $type_filter === 'training' ) {
            $where_type = " AND LOWER(activity_type_key) = 'training'";
        } else {
            $where_type = " AND LOWER(activity_type_key) IN ($match_types, 'training')";
        }

        // 1. Activities for the team in the window (columns), oldest first so
        //    the register reads left-to-right in time like an Excel sheet.
        //
        //    #3586 — an activity after today is a column only when it already
        //    carries a recorded mark (a pre-recorded absence), so that mark
        //    can be seen and cleared; an upcoming activity with nothing on it
        //    has no register to enter yet. Those are shown whenever the window
        //    reaches today, even past its end, because the default window
        //    ends today and would otherwise hide every one of them. "Today"
        //    is site time, the clock the completion rule uses.
        //
        //    #3656 — the existence test is `recordedMarkExistsSql()`, the
        //    same fragment `isColumn()` asks per activity, so the columns
        //    and the links into them cannot drift apart.
        $today       = $today ?? AttendanceDateRule::today();
        $mark_exists = self::recordedMarkExistsSql( 's.id', 's.club_id' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $activity_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.activity_type_key, s.{$date_col} AS session_date, s.title,
                    s.activity_status_key,
                    (s.{$date_col} <= %s) AS is_past
               FROM {$p}tt_activities s
              WHERE s.club_id = %d
                AND s.team_id = %d
                AND (
                      ( s.{$date_col} BETWEEN %s AND %s AND s.{$date_col} <= %s )
                   OR ( %s >= %s AND s.{$date_col} > %s AND s.{$date_col} >= %s
                        AND {$mark_exists} )
                )
                AND s.archived_at IS NULL
                AND s.trashed_at IS NULL
                AND s.plan_state <> 'cancelled'
                AND ( s.activity_status_key IS NULL OR s.activity_status_key <> 'cancelled' )
                {$where_type}
              ORDER BY s.{$date_col} ASC, s.id ASC",
            $today, $club_id, $team_id,
            $from, $to, $today,
            $to, $today, $today, $from
        ) );

        $activities = [];
        foreach ( (array) $activity_rows as $a ) {
            $type = strtolower( (string) ( $a->activity_type_key ?? '' ) );
            // #2521 — the grid shows planned sessions too, and saving a
            // register completes the past-dated ones. The view needs both
            // facts to warn the coach before that happens.
            $status = strtolower( trim( (string) ( $a->activity_status_key ?? '' ) ) );
            $activities[] = [
                'activity_id'  => (int) $a->id,
                'session_date' => (string) $a->session_date,
                'title'        => (string) ( $a->title ?? '' ),
                'type_key'     => $type,
                'is_match'     => in_array( $type, [ 'match', 'game', 'tournament' ], true ),
                'status_key'   => $status,
                'completes'    => $status !== 'completed' && $status !== 'cancelled' && (int) ( $a->is_past ?? 0 ) === 1,
            ];
        }

        // 2. Active roster for the team (rows). Primary-entry: the roster is
        //    the source of rows, not the attendance rows.
        $players = [];
        foreach ( QueryHelpers::get_players( $team_id ) as $pl ) {
            $players[] = [
                'player_id'         => (int) $pl->id,
                'first_name'        => (string) ( $pl->first_name ?? '' ),
                'last_name'         => (string) ( $pl->last_name ?? '' ),
                'jersey_number'     => isset( $pl->jersey_number ) && $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
                'on_current_roster' => true,
                'editable'          => true,
                'current_team_id'   => $team_id,
                'current_team_name' => '',
            ];
        }
        // Row order of the register: jersey number asc (nulls last), then
        // last name — the same ordering the audit matrix uses for players.
        usort( $players, static fn( array $a, array $b ): int => self::compareRows( $a, $b ) );

        if ( $activities === [] ) {
            return [
                'activities' => $activities,
                'players'    => $players,
                'cells'      => [],
                'summary'    => [
                    'total_activities'     => 0,
                    'total_players'        => count( $players ),
                    'former_squad_players' => 0,
                ],
            ];
        }

        // 3. Existing recorded attendance for those activities (cells). Only
        //    non-guest `actual` rows — the same scope the attendance reports
        //    sum, so the grid and the reports agree on what "recorded" means.
        $activity_ids = array_map( static fn( array $a ): int => $a['activity_id'], $activities );
        $in_ids = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $att_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id, player_id, status
               FROM {$p}tt_attendance
              WHERE activity_id IN ($in_ids)
                AND club_id = %d
                AND is_guest = 0
                AND player_id > 0
                AND record_type = 'actual'
              ORDER BY id ASC",
            array_merge( $activity_ids, [ $club_id ] )
        ) );

        // #4009 — the players those marks belong to, before the fold, so a
        // recorded mark can never end up in `cells` under a player the rows
        // do not carry. That orphaning is the bug: the view can only render
        // a cell that has a row.
        $recorded_ids = [];
        foreach ( (array) $att_rows as $r ) {
            $pid = (int) $r->player_id;
            if ( $pid > 0 ) $recorded_ids[ $pid ] = true;
        }
        $roster_ids = [];
        foreach ( $players as $row ) $roster_ids[ (int) $row['player_id'] ] = true;

        $former = self::formerSquadRows( array_keys( array_diff_key( $recorded_ids, $roster_ids ) ), $club_id );
        usort( $former, static fn( array $a, array $b ): int => self::compareRows( $a, $b ) );
        // Former-squad rows go last: the current roster is what a coach
        // enters, and these are history they can only read.
        $players = array_merge( $players, $former );

        $known = $roster_ids;
        foreach ( $former as $row ) $known[ (int) $row['player_id'] ] = true;

        // Fold into cells. Ordering by id ascending means that if legacy dirty
        // data left more than one `actual` row for the same (activity, player)
        // — e.g. a wizard row and a match-execution row — the LATEST row wins
        // deterministically, matching the row the bulk-save upsert keeps.
        $cells = [];
        foreach ( (array) $att_rows as $r ) {
            $pid = (int) $r->player_id;
            $aid = (int) $r->activity_id;
            $st  = strtolower( trim( (string) $r->status ) );
            if ( $pid <= 0 || $aid <= 0 || $st === '' ) continue;
            // A mark for a player record that no longer exists has no row to
            // sit in. Dropping it keeps the payload's own invariant: every
            // key of `cells` is a player in `players`.
            if ( ! isset( $known[ $pid ] ) ) continue;
            $cells[ $pid ][ $aid ] = $st;
        }

        return [
            'activities' => $activities,
            'players'    => $players,
            'cells'      => $cells,
            'summary'    => [
                'total_activities'     => count( $activities ),
                'total_players'        => count( $players ),
                'former_squad_players' => count( $former ),
            ],
        ];
    }

    /**
     * #4009 — the read-only rows for players who have a recorded mark in the
     * window but are not on the team's active roster now: last season's
     * squad, after the age-group conveyor moved them up.
     *
     * Carries the team they are on today so the view can say where they went
     * rather than just that they are gone. Status is not filtered: a released
     * or inactive player still played the sessions their marks record, and a
     * register that hid them would be wrong about what happened.
     *
     * @param list<int> $player_ids
     * @return list<array{ player_id:int, first_name:string, last_name:string, jersey_number:?int, on_current_roster:bool, editable:bool, current_team_id:?int, current_team_name:string }>
     */
    private static function formerSquadRows( array $player_ids, int $club_id ): array {
        if ( $player_ids === [] ) return [];

        global $wpdb;
        $p      = $wpdb->prefix;
        $in_ids = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id, pl.first_name, pl.last_name, pl.jersey_number, pl.team_id,
                    t.name AS team_name
               FROM {$p}tt_players pl
               LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id AND t.club_id = pl.club_id
              WHERE pl.id IN ($in_ids)
                AND pl.club_id = %d",
            array_merge( $player_ids, [ $club_id ] )
        ) );

        $out = [];
        foreach ( (array) $rows as $pl ) {
            $team_id = isset( $pl->team_id ) ? (int) $pl->team_id : 0;
            $out[] = [
                'player_id'         => (int) $pl->id,
                'first_name'        => (string) ( $pl->first_name ?? '' ),
                'last_name'         => (string) ( $pl->last_name ?? '' ),
                'jersey_number'     => isset( $pl->jersey_number ) ? (int) $pl->jersey_number : null,
                'on_current_roster' => false,
                // The bulk-write guard refuses these and reports nothing, so
                // the row says so instead of offering an edit that no-ops.
                'editable'          => false,
                'current_team_id'   => $team_id > 0 ? $team_id : null,
                'current_team_name' => (string) ( $pl->team_name ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Jersey number ascending (nulls last), then last name — the ordering
     * the audit matrix uses, applied to the roster rows and to the
     * former-squad rows separately so the two blocks stay apart.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function compareRows( array $a, array $b ): int {
        $ja = isset( $a['jersey_number'] ) ? (int) $a['jersey_number'] : PHP_INT_MAX;
        $jb = isset( $b['jersey_number'] ) ? (int) $b['jersey_number'] : PHP_INT_MAX;
        if ( $ja !== $jb ) return $ja <=> $jb;
        return strcasecmp( (string) ( $a['last_name'] ?? '' ), (string) ( $b['last_name'] ?? '' ) );
    }
}
