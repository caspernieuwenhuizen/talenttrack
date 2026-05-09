<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendReportDetailView — frontend-native renderer for the two
 * report types that are simple SQL aggregations (#0077 M11):
 *
 *   - team_ratings   — per-team average rating across main categories.
 *   - coach_activity — evaluations saved per coach in a window.
 *
 * Replaces the wp-admin target=_blank jump for these two. The legacy
 * report (Player Progress + Radar + Team Average radar) stays in
 * wp-admin since it leans on form-submit + Chart.js infrastructure
 * that's significant to port and primarily an admin deep-dive.
 *
 * Each report ships with a "Print / Save as PDF" button that calls
 * window.print() against a print stylesheet. PDF v1 — no Dompdf
 * dependency, no vendor footprint. Browser-native PDF output is good
 * enough for tabular reports and is one less moving part to maintain.
 */
final class FrontendReportDetailView extends FrontendViewBase {

    public static function render( string $type ): void {
        if ( ! current_user_can( 'tt_view_reports' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to reports.', 'talenttrack' ) . '</p>';
            return;
        }
        self::enqueueAssets();

        // F2 breadcrumbs replace the standalone back button.
        $reports_url = add_query_arg( [ 'tt_view' => 'reports' ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );
        $titles = [
            'team_ratings'   => __( 'Team rating averages', 'talenttrack' ),
            'coach_activity' => __( 'Coach activity', 'talenttrack' ),
        ];
        $title = $titles[ $type ] ?? __( 'Report', 'talenttrack' );

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::render( [
            [ 'label' => __( 'Dashboard', 'talenttrack' ), 'url' => \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() ],
            [ 'label' => __( 'Reports', 'talenttrack' ),   'url' => $reports_url ],
            [ 'label' => $title ],
        ] );
        echo '<h1 class="tt-fview-title" style="margin:6px 0 18px; font-size:22px; color:#1a1d21;">' . esc_html( $title ) . '</h1>';

        echo '<p style="margin:0 0 16px;">';
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-print-button" onclick="window.print()">'
            . esc_html__( 'Print / Save as PDF', 'talenttrack' )
            . '</button>';
        echo '</p>';

        echo '<div class="tt-print-area">';
        switch ( $type ) {
            case 'team_ratings':   self::renderTeamRatings();   break;
            case 'coach_activity': self::renderCoachActivity(); break;
            default:
                echo '<p><em>' . esc_html__( 'Unknown report.', 'talenttrack' ) . '</em></p>';
        }
        echo '</div>';

        // Print stylesheet — hide everything outside .tt-print-area.
        ?>
        <style>
            @media print {
                body * { visibility: hidden; }
                .tt-print-area, .tt-print-area * { visibility: visible; }
                .tt-print-area { position: absolute; left: 0; top: 0; width: 100%; padding: 16px; }
                .tt-print-button, .tt-breadcrumbs, .tt-back-link, .tt-dash-header, .tt-user-menu, .tt-tile-grid { display: none !important; }
                .tt-table th, .tt-table td { padding: 6px 8px; font-size: 11pt; }
            }
        </style>
        <?php
    }

    private static function renderTeamRatings(): void {
        global $wpdb; $p = $wpdb->prefix;
        $categories = QueryHelpers::get_categories();
        $teams      = QueryHelpers::get_teams();

        echo '<p style="color:#5b6e75; max-width:760px;">'
            . esc_html__( 'Average rating per team across main categories, computed from all evaluations of players currently assigned to each team. Archived rows are excluded.', 'talenttrack' )
            . '</p>';

        if ( empty( $teams ) ) {
            echo '<p><em>' . esc_html__( 'No teams configured.', 'talenttrack' ) . '</em></p>';
            return;
        }

        echo '<div style="overflow-x:auto;"><table class="tt-table" style="width:100%; background:#fff; border:1px solid #e5e7ea;"><thead><tr>';
        echo '<th>' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
        foreach ( $categories as $cat ) {
            echo '<th>' . esc_html( \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $cat->name ) ) . '</th>';
        }
        echo '<th>' . esc_html__( 'Evaluations', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $teams as $team ) {
            if ( isset( $team->archived_at ) && $team->archived_at !== null ) continue;
            $eval_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(e.id)
                 FROM {$p}tt_evaluations e
                 JOIN {$p}tt_players pl ON e.player_id = pl.id
                 WHERE pl.team_id = %d AND pl.archived_at IS NULL AND e.archived_at IS NULL",
                $team->id
            ) );
            echo '<tr><td><strong>' . esc_html( (string) $team->name ) . '</strong>';
            if ( ! empty( $team->age_group ) ) {
                echo ' <span style="color:#888;">(' . esc_html( (string) $team->age_group ) . ')</span>';
            }
            echo '</td>';
            foreach ( $categories as $cat ) {
                $avg = $wpdb->get_var( $wpdb->prepare(
                    "SELECT AVG(r.rating)
                     FROM {$p}tt_eval_ratings r
                     JOIN {$p}tt_evaluations e ON r.evaluation_id = e.id
                     JOIN {$p}tt_players pl ON e.player_id = pl.id
                     WHERE pl.team_id = %d
                       AND r.category_id = %d
                       AND pl.archived_at IS NULL
                       AND e.archived_at IS NULL",
                    $team->id, $cat->id
                ) );
                echo '<td style="font-variant-numeric:tabular-nums;">'
                    . ( $avg === null ? '—' : esc_html( (string) round( (float) $avg, 2 ) ) )
                    . '</td>';
            }
            echo '<td style="font-variant-numeric:tabular-nums; color:#666;">' . $eval_count . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function renderCoachActivity(): void {
        global $wpdb; $p = $wpdb->prefix;

        $days = isset( $_GET['days'] ) ? max( 1, min( 365, absint( $_GET['days'] ) ) ) : 30;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        echo '<p style="color:#5b6e75; max-width:760px;">';
        printf(
            /* translators: %d is number of days */
            esc_html__( 'Evaluations saved per coach in the last %d days. Archived rows excluded.', 'talenttrack' ),
            $days
        );
        echo '</p>';

        echo '<form method="get" style="margin:12px 0;">';
        echo '<input type="hidden" name="tt_view" value="reports" />';
        echo '<input type="hidden" name="type" value="coach_activity" />';
        echo '<label style="font-size:13px;">' . esc_html__( 'Window', 'talenttrack' ) . ': ';
        echo '<select name="days" onchange="this.form.submit()">';
        foreach ( [ 7, 30, 90, 180, 365 ] as $d ) {
            $sel = ( $days === $d ) ? ' selected' : '';
            echo '<option value="' . $d . '"' . $sel . '>'
                . sprintf( /* translators: %d is number of days */ esc_html__( 'Last %d days', 'talenttrack' ), $d )
                . '</option>';
        }
        echo '</select></label></form>';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.coach_id, COUNT(*) AS total_in_window, MAX(e.created_at) AS last_eval
             FROM {$p}tt_evaluations e
             WHERE e.created_at >= %s AND e.archived_at IS NULL
             GROUP BY e.coach_id
             ORDER BY total_in_window DESC, last_eval DESC",
            $cutoff
        ) );

        if ( empty( $rows ) ) {
            echo '<p><em>' . esc_html__( 'No evaluations saved in this window.', 'talenttrack' ) . '</em></p>';
            return;
        }

        echo '<table class="tt-table" style="max-width:800px; background:#fff; border:1px solid #e5e7ea;"><thead><tr>';
        echo '<th>' . esc_html__( 'Coach', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Evaluations', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Last evaluation', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $user = get_userdata( (int) $r->coach_id );
            $name = $user ? (string) $user->display_name : sprintf( '(user %d)', (int) $r->coach_id );
            echo '<tr><td>' . esc_html( $name ) . '</td>';
            echo '<td style="font-variant-numeric:tabular-nums;">' . (int) $r->total_in_window . '</td>';
            echo '<td>' . esc_html( (string) $r->last_eval ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
