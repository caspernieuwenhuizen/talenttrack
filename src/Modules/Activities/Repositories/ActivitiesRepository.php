<?php
namespace TT\Modules\Activities\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ActivitiesRepository — shared read-path for `tt_activities` + the
 * roster attendance rows joined into the same view. Since the #1320
 * admin-CRUD slice it also owns the wp-admin write paths (create /
 * update / roster replace / delete), so `Admin\ActivitiesPage` no
 * longer touches `$wpdb` directly.
 *
 * v4.20.32 (#1190) — extracted from
 * `FrontendActivitiesManageView::loadSession()`/`loadAttendance()` and
 * `ActivityBriefPdfExporter::collect()`, which previously inlined
 * `$wpdb` queries with subtly different filter sets:
 *
 *   - on-screen view: demo-scope only on `tt_activities`; no `club_id`
 *     filter on the attendance JOIN.
 *   - PDF exporter: club_id-strict on `tt_activities` AND on the
 *     `tt_players` join; missing `is_guest = 0` (so guests leaked into
 *     the printed roster while the on-screen list excluded them).
 *
 * Audit 10 (#1184) flagged this as the same data-fork pattern #1059
 * established the fix for: share one repository call between the
 * on-screen view and the print/PDF surface so the two outputs can't
 * silently drift.
 *
 * `findById()` matches the on-screen `loadSession` filter shape; the
 * exporter's previous strict `club_id` filter on the activity is dropped
 * here because the WordPress install's single tenancy is already
 * enforced at the request boundary (CurrentClub resolution), and
 * sprinkling per-helper club_id WHEREs was the inconsistency #1188 just
 * resolved on `get_player()`. Demo-scope stays.
 *
 * `listRosterAttendance()` keeps the on-screen view's filters
 * (`is_guest = 0`) and the exporter's player-join shape so the printed
 * roster table no longer includes guests by default. Callers wanting
 * guests pass `includeGuests = true`.
 */
final class ActivitiesRepository {

    /**
     * Fetch an activity row + joined team name, applying the same
     * `archived_at IS NULL` + demo-scope filters the on-screen view uses.
     *
     * #1324 — when the activity links to a tournament
     * (`tournament_id` not null), the returned object also carries a
     * `tournament` sub-object: `{ id, name, start_date, end_date,
     * match_count }`. When `tournament_id` is null or the linked row
     * is archived/missing, `$row->tournament` is null.
     */
    public function findById( int $activity_id ): ?object {
        global $wpdb;
        $p     = $wpdb->prefix;
        $scope = QueryHelpers::apply_demo_scope( 's', 'activity' );
        /** @var object|null $row */
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, t.name AS team_name FROM {$p}tt_activities s
             LEFT JOIN {$p}tt_teams t ON t.id = s.team_id AND t.club_id = s.club_id
             WHERE s.id = %d AND s.archived_at IS NULL {$scope}",
            $activity_id
        ) );
        if ( ! $row ) return null;

        // #1324 — hydrate the linked tournament (if any).
        $row->tournament = null;
        $tournament_id   = isset( $row->tournament_id ) ? (int) $row->tournament_id : 0;
        if ( $tournament_id > 0 ) {
            $row->tournament = self::hydrateTournament( $tournament_id, (int) ( $row->club_id ?? 0 ) );
        }

        return $row;
    }

    /**
     * #1324 — narrow tournament shape: id, name, start_date, end_date,
     * match_count. Returns null when the tournament is missing or
     * archived (the activity stays in tournament-typed limbo with
     * `tournament_id` populated but no hydrated sub-object).
     */
    private static function hydrateTournament( int $tournament_id, int $club_id ): ?object {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, start_date, end_date
               FROM {$p}tt_tournaments
              WHERE id = %d AND archived_at IS NULL AND club_id = %d
              LIMIT 1",
            $tournament_id, $club_id
        ) );
        if ( ! $row ) return null;
        $row->match_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_tournament_matches WHERE tournament_id = %d",
            $tournament_id
        ) );
        return $row;
    }

    /**
     * Fetch roster attendance rows for an activity, joined to the
     * player's profile columns the print/PDF + on-screen views render.
     *
     * @param bool $include_guests Default false; mirrors the on-screen
     *                             attendance-table behaviour (which
     *                             lists guests in a separate panel).
     * @return list<object>
     */
    public function listRosterAttendance( int $activity_id, bool $include_guests = false ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $guest_filter = $include_guests ? '' : 'AND att.is_guest = 0';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT att.id, att.player_id, att.status, att.notes AS att_notes,
                    att.is_guest, att.record_type,
                    pl.first_name, pl.last_name, pl.jersey_number, pl.preferred_positions
               FROM {$p}tt_attendance att
               JOIN {$p}tt_players pl ON pl.id = att.player_id
              WHERE att.activity_id = %d {$guest_filter}
              ORDER BY pl.last_name ASC, pl.first_name ASC",
            $activity_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #1320 — recent-window list of activities a player attended,
     * shared by the player profile Activities tab + header timeline
     * + player dashboard. The 3 surfaces previously inlined the same
     * JOIN-on-attendance shape with subtly different filter sets
     * (the Activities tab gained an ASC display in #1316; the others
     * stayed DESC); every schema change to `tt_activities` /
     * `tt_attendance` requires updating each one independently.
     *
     * Inner query selects the MOST RECENT $limit activities (DESC),
     * matching #1316's recent-window semantics. Outer SELECT reverses
     * to ASC when callers want chronological display.
     *
     * Filter shape:
     *   - `include_guests`     (default false) — attendance rows where
     *                          `is_guest = 1` joined via `guest_player_id`.
     *   - `record_types`       (default `['actual']`) — list of
     *                          `att.record_type` values. The player
     *                          dashboard wants `['actual']`; the
     *                          Activities tab also accepts planned
     *                          rows (it filters by `plan_state` instead).
     *   - `include_archived`   (default false) — when true, drops the
     *                          `a.archived_at IS NULL` filter.
     *   - `plan_states`        (optional) — list of `a.plan_state`
     *                          values to filter on. Empty = no filter.
     *   - `only_past_completed` (default false) — when true, completed
     *                          activities are only included if
     *                          `session_date <= CURDATE()` (the
     *                          Activities tab's "no future-dated
     *                          completed shows" rule).
     *
     * @param array{
     *     include_guests?: bool,
     *     record_types?: list<string>,
     *     include_archived?: bool,
     *     plan_states?: list<string>,
     *     only_past_completed?: bool
     * } $filters
     * @param 'ASC'|'DESC' $display_order
     * @return list<object>
     */
    public function listForPlayer( int $player_id, int $limit, string $display_order = 'DESC', array $filters = [] ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $display_order = strtoupper( $display_order ) === 'ASC' ? 'ASC' : 'DESC';
        $limit         = max( 1, min( 100, $limit ) );

        $include_guests     = ! empty( $filters['include_guests'] );
        // record_types: array_key_exists distinguishes "not set" (default
        // to ['actual']) from explicit null (skip the filter entirely).
        $record_types_key   = array_key_exists( 'record_types', $filters );
        $record_types       = $record_types_key
            ? ( is_array( $filters['record_types'] ) ? array_values( array_filter( array_map( 'strval', $filters['record_types'] ) ) ) : null )
            : [ 'actual' ];
        $include_archived   = ! empty( $filters['include_archived'] );
        $plan_states        = isset( $filters['plan_states'] ) && is_array( $filters['plan_states'] )
            ? array_values( array_filter( array_map( 'strval', $filters['plan_states'] ) ) )
            : [];
        $only_past_completed = ! empty( $filters['only_past_completed'] );

        $where  = [];
        $params = [];

        if ( $include_guests ) {
            $where[]  = '( att.player_id = %d OR att.guest_player_id = %d )';
            $params[] = $player_id;
            $params[] = $player_id;
        } else {
            $where[]  = 'att.player_id = %d';
            $where[]  = 'att.is_guest = 0';
            $params[] = $player_id;
        }

        if ( is_array( $record_types ) && ! empty( $record_types ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $record_types ), '%s' ) );
            $where[]      = "att.record_type IN ({$placeholders})";
            $params       = array_merge( $params, $record_types );
        }

        if ( ! $include_archived ) {
            $where[] = 'a.archived_at IS NULL';
        }

        if ( ! empty( $plan_states ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $plan_states ), '%s' ) );
            $where[]      = "a.plan_state IN ({$placeholders})";
            $params       = array_merge( $params, $plan_states );
        }

        if ( $only_past_completed ) {
            // Completed rows must be in the past; in-flight rows
            // (planned/scheduled) pass through regardless of date.
            $where[] = "( ( a.plan_state = 'completed' AND a.session_date <= CURDATE() ) OR a.plan_state IN ( 'planned', 'scheduled' ) )";
        }

        $where_sql = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT * FROM (
                    SELECT a.id, a.title, a.session_date, a.activity_type_key, a.plan_state, a.team_id, a.activity_status_key,
                           att.id AS attendance_id, att.status, att.notes AS att_notes, att.is_guest, att.record_type
                      FROM {$p}tt_attendance att
                      JOIN {$p}tt_activities a ON a.id = att.activity_id
                     WHERE {$where_sql}
                  ORDER BY a.session_date DESC, a.id DESC
                     LIMIT %d
                ) recent
                ORDER BY recent.session_date {$display_order}, recent.id {$display_order}";

        $params[] = $limit;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #1320 slice 2 — short list of completed activities the player
     * attended. Used by the behaviour-rating popovers + the player
     * status-capture surface as the "Related activity" dropdown source.
     *
     * Filters on `activity_status_key = 'completed'` (NOT `plan_state`
     * — those are different lifecycle axes per migration 0144) so
     * coaches don't tie a behaviour rating to an activity that hasn't
     * happened yet. Returns id / session_date / title only — the
     * dropdown doesn't need a full row.
     *
     * @return list<object>
     */
    public function listRecentCompletedForPlayer( int $player_id, int $limit ): array {
        global $wpdb;
        $p     = $wpdb->prefix;
        $limit = max( 1, min( 100, $limit ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT a.id, a.session_date, a.title
               FROM {$p}tt_activities a
               JOIN {$p}tt_attendance att ON att.activity_id = a.id
              WHERE att.player_id = %d
                AND a.activity_status_key = %s
                AND a.archived_at IS NULL
              ORDER BY a.session_date DESC
              LIMIT %d",
            $player_id, 'completed', $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #1358 — attendance summary for the player-profile "Attendance"
     * KPI: present rows vs. all actual attendance rows on completed,
     * non-archived activities in the trailing window. Matches the
     * "actual attendance" scope of the player Activities tab — only
     * completed activities count.
     *
     * v4.20.48 (#1227) — `att.record_type = 'actual'` filter so the
     * denominator doesn't inflate once #788 ship 2 lands with
     * pre-filled expected rows. Audit 7 (#1181).
     *
     * @return object|null `{present_n: int|null, total_n: int}`; null on query failure.
     */
    public function attendanceRateForPlayer( int $player_id, int $days = 30 ): ?object {
        if ( $player_id <= 0 ) return null;
        $days = max( 1, $days );

        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN att.status = 'present' THEN 1 ELSE 0 END) AS present_n,
                COUNT(*) AS total_n
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d
                AND att.is_guest = 0
                AND att.record_type = 'actual'
                AND a.archived_at IS NULL
                AND a.plan_state = 'completed'
                AND a.session_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
            $player_id, $days
        ) );
        return $row ?: null;
    }

    /**
     * Variant for the on-screen view's edit form, which keys
     * attendance rows by `player_id` for fast lookups. Excludes
     * guests by contract.
     *
     * @return array<int, object>
     */
    public function attendanceMapByPlayer( int $activity_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        // v4.20.48 (#1227) — added `record_type = 'actual'` so the edit
        // form's per-player attendance map doesn't double up once #788
        // ship 2 lands with pre-filled expected rows. Audit 7 (#1181).
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_attendance WHERE activity_id = %d AND is_guest = 0 AND record_type = 'actual'",
            $activity_id
        ) );
        $out = [];
        foreach ( $rows ?: [] as $r ) {
            if ( $r->player_id !== null ) $out[ (int) $r->player_id ] = $r;
        }
        return $out;
    }

    /**
     * #1320 admin-CRUD slice — the wp-admin activities list
     * (`Admin\ActivitiesPage::render_page`). Club-strict, archive-tab
     * aware, demo-scoped, optionally filtered to one activity type.
     *
     * `$type_key` is validated against the live `activity_type` lookup
     * here (not in the caller) so an unknown value silently means "no
     * type filter" — same lenient wp-admin semantics as before.
     *
     * `coach_name` rides along for parity with the historical inline
     * query even though the current list table doesn't render it.
     *
     * @param string $view 'active' | 'archived' | 'all' (re-sanitized here).
     * @return list<object>
     */
    public function listForAdmin( string $view = 'active', string $type_key = '', int $limit = 50 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $view_clause = ArchiveRepository::filterClause( ArchiveRepository::sanitizeView( $view ) );
        $scope       = QueryHelpers::apply_demo_scope( 'a', 'activity' );
        $limit       = max( 1, min( 200, $limit ) );

        $type_clause = '';
        $params      = [ CurrentClub::id() ];
        if ( $type_key !== '' ) {
            $valid_types = array_map(
                static fn( $row ) => (string) $row->name,
                QueryHelpers::get_lookups( 'activity_type' )
            );
            if ( in_array( $type_key, $valid_types, true ) ) {
                $type_clause = ' AND a.activity_type_key = %s';
                $params[]    = $type_key;
            }
        }
        $params[] = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, t.name AS team_name, u.display_name AS coach_name
               FROM {$p}tt_activities a
               LEFT JOIN {$p}tt_teams t ON a.team_id = t.id AND t.club_id = a.club_id
               LEFT JOIN {$wpdb->users} u ON a.coach_id = u.ID
              WHERE a.{$view_clause}
                AND a.club_id = %d
                {$scope}
                {$type_clause}
           ORDER BY a.session_date DESC
              LIMIT %d",
            ...$params
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #1320 admin-CRUD slice — single activity for the wp-admin edit
     * form. Unlike `findById` (the frontend detail shape) this is
     * club-strict, ignores demo scope, and DOES return archived rows —
     * the admin archive tab links straight into the edit form.
     */
    public function findForAdmin( int $activity_id ): ?object {
        if ( $activity_id <= 0 ) return null;
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * #1320 admin-CRUD slice — guest attendance rows for one activity,
     * joined to the linked player + their team for display. Read-only
     * panel on the wp-admin edit form (#0077 M2 parity); guest CRUD
     * stays on the frontend modal flow.
     *
     * @return list<object>
     */
    public function listGuestAttendance( int $activity_id ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT att.*, pl.first_name, pl.last_name, t.name AS guest_team_name
               FROM {$p}tt_attendance att
               LEFT JOIN {$p}tt_players pl ON pl.id = att.guest_player_id AND pl.club_id = att.club_id
               LEFT JOIN {$p}tt_teams   t  ON t.id = pl.team_id           AND t.club_id  = pl.club_id
              WHERE att.activity_id = %d AND att.is_guest = 1 AND att.club_id = %d
              ORDER BY att.id ASC",
            $activity_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #1320 admin-CRUD slice — insert a new activity. The caller
     * supplies the sanitized column map (including
     * `activity_source_key`); `club_id` is stamped here.
     *
     * @return int|null New activity id, or null when the insert failed
     *                  (read `lastError()` for the DB message).
     */
    public function create( array $data ): ?int {
        global $wpdb;
        $p               = $wpdb->prefix;
        $data['club_id'] = CurrentClub::id();
        $ok              = $wpdb->insert( "{$p}tt_activities", $data );
        return $ok === false ? null : (int) $wpdb->insert_id;
    }

    /**
     * #1320 admin-CRUD slice — club-scoped update of one activity.
     *
     * @return bool False only on a DB error ("0 rows changed" is true,
     *              matching the historical `$ok !== false` check).
     */
    public function update( int $activity_id, array $data ): bool {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->update(
            "{$p}tt_activities",
            $data,
            [ 'id' => $activity_id, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }

    /**
     * #1320 admin-CRUD slice — wipe + rewrite the roster attendance
     * rows for an activity. Only touches `is_guest = 0` rows: guest
     * rows are managed via the frontend / REST endpoints and survive a
     * legacy admin save cycle (#0026).
     *
     * @param array<int, array{status: string, notes: string}> $entries
     *                  Keyed by player id; values already sanitized.
     * @return array<int, string> Player id => DB error message, for
     *                  each row whose insert failed (caller logs).
     */
    public function replaceRosterAttendance( int $activity_id, array $entries ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_attendance", [ 'activity_id' => $activity_id, 'is_guest' => 0, 'club_id' => CurrentClub::id() ] );
        $failed = [];
        foreach ( $entries as $player_id => $entry ) {
            $ok = $wpdb->insert( "{$p}tt_attendance", [
                'activity_id' => $activity_id,
                'player_id'   => (int) $player_id,
                'status'      => (string) ( $entry['status'] ?? 'Present' ),
                'notes'       => (string) ( $entry['notes'] ?? '' ),
                'is_guest'    => 0,
                'club_id'     => CurrentClub::id(),
            ] );
            if ( $ok === false ) $failed[ (int) $player_id ] = (string) $wpdb->last_error;
        }
        return $failed;
    }

    /**
     * #1320 admin-CRUD slice — hard-delete an activity and ALL its
     * attendance rows (roster + guests). Club-scoped.
     */
    public function deleteWithAttendance( int $activity_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_attendance", [ 'activity_id' => $activity_id, 'club_id' => CurrentClub::id() ] );
        $wpdb->delete( "{$p}tt_activities", [ 'id' => $activity_id, 'club_id' => CurrentClub::id() ] );
    }

    /**
     * Last DB error message, for callers surfacing write failures
     * without reaching into `$wpdb` themselves.
     */
    public function lastError(): string {
        global $wpdb;
        return (string) $wpdb->last_error;
    }
}
