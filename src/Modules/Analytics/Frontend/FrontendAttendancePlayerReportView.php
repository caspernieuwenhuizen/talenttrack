<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendAttendancePlayerReportView (v3.110.109) — standard report:
 * player attendance statistics over a date range, optionally scoped
 * to a single team.
 *
 * Reached via the "Standard reports" section on the central Analytics
 * surface. One row per player; columns: activities in window, present
 * %, absent %, late %, excused %, injured %. Click-through to the
 * player profile.
 *
 * Status lookups use `LOWER(att.status)` for case insensitivity
 * (legacy mixed-case rows aggregate into the same bucket as the
 * v3.110.78-onward lowercase rows). Cap-gated on `tt_view_analytics`.
 * Scope: club-wide, narrowed by an optional `team_id` filter.
 */
final class FrontendAttendancePlayerReportView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        if ( ! current_user_can( 'tt_view_analytics' ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Not authorized', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'analytics', __( 'Analytics', 'talenttrack' ) ) ]
            );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this report.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            __( 'Player attendance', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'analytics', __( 'Analytics', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Player attendance statistics', 'talenttrack' ) );

        $defaults = self::defaultWindow();
        $from    = isset( $_GET['from'] )    ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : $defaults['from'];
        $to      = isset( $_GET['to'] )      ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) )   : $defaults['to'];
        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $defaults['from'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $defaults['to'];

        self::renderFilterForm( $from, $to, $team_id );

        $rows = self::query( $from, $to, $team_id );
        if ( $rows === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No attendance recorded in the selected window.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<div class="tt-table-wrap"><table class="tt-table tt-table-sortable" style="width:100%; margin-top:12px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Player',    'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Team',      'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Activities', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Present %', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Late %',    'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Absent %',  'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Excused %', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Injured %', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $player_name = trim( ( $r->first_name ?? '' ) . ' ' . ( $r->last_name ?? '' ) );
            if ( $player_name === '' ) $player_name = '#' . (int) $r->player_id;
            $player_url = BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'players', 'id' => (int) $r->player_id ],
                RecordLink::dashboardUrl()
            ) );
            $team_name = (string) ( $r->team_name ?? '' );
            echo '<tr>';
            echo '<td><a class="tt-record-link" href="' . esc_url( $player_url ) . '">' . esc_html( $player_name ) . '</a></td>';
            echo '<td>' . ( $team_name !== '' ? esc_html( $team_name ) : '<span class="tt-muted">&mdash;</span>' ) . '</td>';
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
     * @return list<object>
     */
    private static function query( string $from, string $to, int $team_id ): array {
        global $wpdb;
        $where_team = $team_id > 0
            ? $wpdb->prepare( ' AND a.team_id = %d', $team_id )
            : '';

        /** @var object[] $rows */
        $rows = $wpdb->get_results( $wpdb->prepare(
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
              LEFT JOIN {$wpdb->prefix}tt_teams t ON t.id = a.team_id
             WHERE p.club_id = %d
               AND att.is_guest = 0
               AND a.session_date BETWEEN %s AND %s
               AND a.plan_state = 'completed'
               {$where_team}
             GROUP BY p.id, p.first_name, p.last_name, t.name
             ORDER BY p.last_name, p.first_name",
            CurrentClub::id(), $from, $to
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    private static function pct( $part, $total ): string {
        $total = (int) $total;
        if ( $total <= 0 ) return '—';
        return number_format_i18n( ( (int) $part / $total ) * 100, 1 ) . '%';
    }

    /** @return array{from:string,to:string} */
    private static function defaultWindow(): array {
        return [
            'from' => gmdate( 'Y-m-d', strtotime( '-90 days' ) ),
            'to'   => gmdate( 'Y-m-d' ),
        ];
    }

    private static function renderFilterForm( string $from, string $to, int $team_id ): void {
        global $wpdb;
        /** @var object[] $teams */
        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}tt_teams
              WHERE club_id = %d AND ( archived_at IS NULL OR archived_at = '' )
              ORDER BY name ASC",
            CurrentClub::id()
        ) );

        echo '<form method="get" class="tt-filter-row" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:12px;">';
        echo '<input type="hidden" name="tt_view" value="attendance-report-player" />';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<select name="team_id">';
        echo '<option value="0">' . esc_html__( 'All teams', 'talenttrack' ) . '</option>';
        foreach ( (array) $teams as $t ) {
            $sel = selected( $team_id, (int) $t->id, false );
            echo '<option value="' . esc_attr( (string) $t->id ) . '" ' . $sel . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'From', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="from" value="' . esc_attr( $from ) . '" /></label>';
        echo '<label style="display:flex; flex-direction:column; gap:4px;"><span>' . esc_html__( 'To', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="to" value="' . esc_attr( $to ) . '" /></label>';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
        echo '</form>';
    }
}
