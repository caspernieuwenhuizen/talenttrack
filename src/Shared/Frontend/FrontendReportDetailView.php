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

    /**
     * Enqueue the 2026 report-detail stylesheet (#1760). Depends on the
     * app-chrome sheet so it inherits the brand + neutral tokens and the
     * shared .tt-report-card / .tt-table primitives, plus the print
     * rules previously emitted inline.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-report-detail',
            TT_PLUGIN_URL . 'assets/css/frontend-report-detail.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

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
        echo '<header class="tt-rep-page-head"><h1>' . esc_html( $title ) . '</h1></header>';

        echo '<p class="tt-rep-detail-actions">';
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-print-button" onclick="window.print()">'
            . esc_html__( 'Print / Save as PDF', 'talenttrack' )
            . '</button>';
        echo '</p>';

        // Print stylesheet ships in frontend-report-detail.css (#1760) —
        // it hides everything outside .tt-print-area at @media print.
        echo '<div class="tt-print-area">';
        switch ( $type ) {
            case 'team_ratings':   self::renderTeamRatings();   break;
            case 'coach_activity': self::renderCoachActivity(); break;
            default:
                echo '<p><em>' . esc_html__( 'Unknown report.', 'talenttrack' ) . '</em></p>';
        }
        echo '</div>';
    }

    private static function renderTeamRatings(): void {
        global $wpdb; $p = $wpdb->prefix;
        $categories = QueryHelpers::get_categories();
        $teams      = QueryHelpers::get_teams();

        echo '<p class="tt-rep-detail-intro">'
            . esc_html__( 'Average rating per team across main categories, computed from all evaluations of players currently assigned to each team. Archived rows are excluded.', 'talenttrack' )
            . '</p>';

        if ( empty( $teams ) ) {
            echo '<p><em>' . esc_html__( 'No teams configured.', 'talenttrack' ) . '</em></p>';
            return;
        }

        echo '<div class="tt-report-card"><div class="tt-table-wrap"><table class="tt-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
        foreach ( $categories as $cat ) {
            echo '<th>' . esc_html( \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $cat->name ) ) . '</th>';
        }
        echo '<th>' . esc_html__( 'Evaluations', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        // #1359 — two grouped queries replace the teams × (1 + categories)
        // per-cell loop (10 teams × 6 categories used to fire 70 queries).
        $eval_counts = [];
        foreach ( (array) $wpdb->get_results(
            "SELECT pl.team_id, COUNT(e.id) AS n
               FROM {$p}tt_evaluations e
               JOIN {$p}tt_players pl ON e.player_id = pl.id
              WHERE pl.archived_at IS NULL AND e.archived_at IS NULL
              GROUP BY pl.team_id"
        ) as $crow ) {
            $eval_counts[ (int) $crow->team_id ] = (int) $crow->n;
        }
        $cat_avgs = [];
        foreach ( (array) $wpdb->get_results(
            "SELECT pl.team_id, r.category_id, AVG(r.rating) AS avg_rating
               FROM {$p}tt_eval_ratings r
               JOIN {$p}tt_evaluations e ON r.evaluation_id = e.id
               JOIN {$p}tt_players pl ON e.player_id = pl.id
              WHERE pl.archived_at IS NULL AND e.archived_at IS NULL
              GROUP BY pl.team_id, r.category_id"
        ) as $arow ) {
            $cat_avgs[ (int) $arow->team_id ][ (int) $arow->category_id ] = (float) $arow->avg_rating;
        }

        foreach ( $teams as $team ) {
            if ( isset( $team->archived_at ) && $team->archived_at !== null ) continue;
            $eval_count = $eval_counts[ (int) $team->id ] ?? 0;
            echo '<tr><td><strong>' . esc_html( (string) $team->name ) . '</strong>';
            if ( ! empty( $team->age_group ) ) {
                echo ' <span class="tt-rep-detail-muted">(' . esc_html( \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'age_group', (string) $team->age_group ) ) . ')</span>';
            }
            echo '</td>';
            foreach ( $categories as $cat ) {
                $avg = $cat_avgs[ (int) $team->id ][ (int) $cat->id ] ?? null;
                echo '<td class="num">'
                    . ( $avg === null ? '—' : esc_html( (string) round( (float) $avg, 2 ) ) )
                    . '</td>';
            }
            echo '<td class="num tt-rep-detail-muted">' . $eval_count . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function renderCoachActivity(): void {
        global $wpdb; $p = $wpdb->prefix;

        $days = isset( $_GET['days'] ) ? max( 1, min( 365, absint( $_GET['days'] ) ) ) : 30;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        echo '<p class="tt-rep-detail-intro">';
        printf(
            /* translators: %d is number of days */
            esc_html__( 'Evaluations saved per coach in the last %d days. Archived rows excluded.', 'talenttrack' ),
            $days
        );
        echo '</p>';

        echo '<form method="get" class="tt-rep-detail-filter">';
        echo '<input type="hidden" name="tt_view" value="reports" />';
        echo '<input type="hidden" name="type" value="coach_activity" />';
        echo '<label>' . esc_html__( 'Window', 'talenttrack' ) . ': ';
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

        echo '<div class="tt-report-card tt-report-card--narrow"><div class="tt-table-wrap">';
        echo '<table class="tt-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Coach', 'talenttrack' ) . '</th>';
        echo '<th class="num">' . esc_html__( 'Evaluations', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Last evaluation', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $user = get_userdata( (int) $r->coach_id );
            $name = $user ? (string) $user->display_name : sprintf( '(user %d)', (int) $r->coach_id );
            echo '<tr><td>' . esc_html( $name ) . '</td>';
            echo '<td class="num">' . (int) $r->total_in_window . '</td>';
            echo '<td>' . esc_html( (string) $r->last_eval ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div></div>';
    }
}
