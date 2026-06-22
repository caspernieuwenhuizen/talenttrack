<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendAttendanceTeamReportView (v3.110.109) — standard report:
 * team attendance statistics over a date range.
 *
 * Reached via the "Standard reports" section on the central Analytics
 * surface (`?tt_view=analytics`). One row per team; columns: activities
 * in window, present %, absent %, late %, excused %, injured %.
 *
 * Date range filter (default last 90 days) via GET form. Status %s use
 * `LOWER(att.status)` to match the v3.110.78 case-insensitivity fix —
 * legacy mixed-case rows aggregate into the same bucket as the
 * current-shape lowercase rows.
 *
 * Cap-gated on `tt_view_analytics` (same as the parent Analytics view).
 * Scope: club-wide.
 */
final class FrontendAttendanceTeamReportView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        if ( ! current_user_can( 'tt_view_analytics' ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Not authorized', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
            );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this report.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            __( 'Team attendance', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Team attendance statistics', 'talenttrack' ) );

        $defaults = self::defaultWindow();
        $from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : $defaults['from'];
        $to   = isset( $_GET['to'] )   ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) )   : $defaults['to'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $defaults['from'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $defaults['to'];

        // v4.20.4 (#1147) — same team-scope pattern as the player report.
        $is_scope_admin = $is_admin || current_user_can( 'tt_view_all_teams' );
        $allowed_team_ids = $is_scope_admin
            ? null
            : array_values( array_map( 'intval', array_column( QueryHelpers::get_teams_for_coach( $user_id ), 'id' ) ) );

        if ( ! $is_scope_admin && $allowed_team_ids === [] ) {
            echo '<p class="tt-notice">' . esc_html__( "You don't coach any teams yet, so there is no attendance to show. Ask an administrator to assign you to a team.", 'talenttrack' ) . '</p>';
            return;
        }

        self::renderFilterForm( $from, $to );

        $rows = self::query( $from, $to, $allowed_team_ids );
        if ( $rows === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No attendance recorded in the selected window.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<div class="tt-table-wrap"><table class="tt-table tt-table-sortable" style="width:100%; margin-top:12px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Activities', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Present %', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Late %',    'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Absent %',  'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Excused %', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Injured %', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $team_url = BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'teams', 'id' => (int) $r->team_id ],
                RecordLink::dashboardUrl()
            ) );
            $name = (string) ( $r->team_name ?? '' );
            if ( $name === '' ) $name = '#' . (int) $r->team_id;
            echo '<tr>';
            echo '<td><a class="tt-record-link" href="' . esc_url( $team_url ) . '">' . esc_html( $name ) . '</a></td>';
            echo '<td style="text-align:right;">' . (int) $r->activities . '</td>';
            echo '<td style="text-align:right;">' . esc_html( self::pct( $r->present, $r->total ) ) . '</td>';
            echo '<td style="text-align:right;">' . esc_html( self::pct( $r->late,    $r->total ) ) . '</td>';
            echo '<td style="text-align:right;">' . esc_html( self::pct( $r->absent,  $r->total ) ) . '</td>';
            echo '<td style="text-align:right;">' . esc_html( self::pct( $r->excused, $r->total ) ) . '</td>';
            echo '<td style="text-align:right;">' . esc_html( self::pct( $r->injured, $r->total ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * @param list<int>|null $allowed_team_ids null = unrestricted
     * @return list<object>  one row per team in the window with raw
     *                        counters; PHP `pct()` does the formatting.
     */
    private static function query( string $from, string $to, ?array $allowed_team_ids ): array {
        global $wpdb;
        $where_scope = '';
        if ( $allowed_team_ids !== null ) {
            if ( $allowed_team_ids === [] ) return [];
            $placeholders = implode( ',', array_fill( 0, count( $allowed_team_ids ), '%d' ) );
            $where_scope  = $wpdb->prepare( " AND t.id IN ($placeholders)", ...$allowed_team_ids );
        }
        /** @var object[] $rows */
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                t.id   AS team_id,
                t.name AS team_name,
                COUNT(DISTINCT a.id) AS activities,
                COUNT(att.id) AS total,
                SUM( CASE WHEN LOWER(att.status) = 'present' THEN 1 ELSE 0 END ) AS present,
                SUM( CASE WHEN LOWER(att.status) = 'late'    THEN 1 ELSE 0 END ) AS late,
                SUM( CASE WHEN LOWER(att.status) = 'absent'  THEN 1 ELSE 0 END ) AS absent,
                SUM( CASE WHEN LOWER(att.status) = 'excused' THEN 1 ELSE 0 END ) AS excused,
                SUM( CASE WHEN LOWER(att.status) = 'injured' THEN 1 ELSE 0 END ) AS injured
              FROM {$wpdb->prefix}tt_teams t
              JOIN {$wpdb->prefix}tt_activities a ON a.team_id = t.id AND a.archived_at IS NULL
              JOIN {$wpdb->prefix}tt_attendance att ON att.activity_id = a.id AND att.is_guest = 0
             WHERE t.club_id = %d
               AND att.record_type = 'actual'
               AND a.session_date BETWEEN %s AND %s
               AND a.plan_state = 'completed'
               {$where_scope}
             GROUP BY t.id, t.name
             ORDER BY t.name ASC",
            CurrentClub::id(), $from, $to
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    private static function pct( $part, $total ): string {
        $total = (int) $total;
        if ( $total <= 0 ) return '—';
        return number_format_i18n( ( (int) $part / $total ) * 100, 1 ) . '%';
    }

    /**
     * Default window: 90 days back from today.
     * @return array{from:string,to:string}
     */
    private static function defaultWindow(): array {
        return [
            'from' => gmdate( 'Y-m-d', strtotime( '-90 days' ) ),
            'to'   => gmdate( 'Y-m-d' ),
        ];
    }

    private static function renderFilterForm( string $from, string $to ): void {
        $action = remove_query_arg( [ 'from', 'to' ] );
        echo '<form method="get" class="tt-filter-row" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:12px;">';
        echo '<input type="hidden" name="tt_view" value="attendance-report-team" />';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'From', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="from" value="' . esc_attr( $from ) . '" /></label>';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'To', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="to" value="' . esc_attr( $to ) . '" /></label>';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
        echo '</form>';
    }
}
