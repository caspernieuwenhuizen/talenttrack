<?php
namespace TT\Modules\Activities\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * ActivitiesRepository — shared read-path for `tt_activities` + the
 * roster attendance rows joined into the same view.
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
        return $row ?: null;
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
}
