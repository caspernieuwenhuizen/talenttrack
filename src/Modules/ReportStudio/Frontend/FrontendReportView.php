<?php
namespace TT\Modules\ReportStudio\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\UpgradePanel;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendReportView (#4591, epic #4564) — `?tt_view=report&id=N`: a
 * published Report Studio report, read only, for the people it is published
 * to. Reached from "Shared with me" on the Reports launcher.
 *
 * It shows the published version, never a draft. Whether the reader may
 * open it is `ReportAccess::canRead()` (a recipient holds one of its persona
 * keys and reads its subject); each block is checked again by the data
 * route, and a block the reader may not see says so instead of showing
 * figures. The page is a shell that `frontend-report-viewer.js` fills from
 * REST.
 */
final class FrontendReportView extends FrontendViewBase {

    public const SLUG = 'report';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_reports' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to reports.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! LicenseGate::allows( 'report_studio' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Report', 'talenttrack' ), [ self::reportsCrumb() ] );
            echo UpgradePanel::render( 'report_studio' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UpgradePanel returns escaped HTML
            return;
        }

        $id     = isset( $_GET['id'] ) ? absint( wp_unslash( (string) $_GET['id'] ) ) : 0;
        $report = $id > 0 ? ( new ReportsRepository() )->find( $id ) : null;
        if ( $report === null || $report['archived_at'] !== null ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Report not found', 'talenttrack' ), [ self::reportsCrumb() ] );
            echo '<p class="tt-notice">' . esc_html__( 'This report does not exist, or it was removed.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! ReportAccess::canRead( ReportReader::for( $user_id ), $report ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), [ self::reportsCrumb() ] );
            echo '<p class="tt-notice">' . esc_html__( 'This report is not shared with you.', 'talenttrack' ) . '</p>';
            return;
        }

        $name = $report['name'] !== '' ? $report['name'] : __( 'Untitled report', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard( $name, [ self::reportsCrumb() ] );
        if ( $report['published_version_id'] === null ) {
            echo '<p class="tt-notice">' . esc_html__( 'This report has not been published yet.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueue( $id );
        echo '<div class="tt-rv" data-tt-rv>';
        echo '<h1 class="tt-fview-title tt-rv-title">' . esc_html( $name ) . '</h1>';
        echo '<div class="tt-rs-context tt-rv-context">';
        echo '<div class="tt-field" data-tt-rv-subject hidden></div>';
        echo '<fieldset class="tt-rs-radios" data-tt-rv-periods hidden><legend>' . esc_html__( 'Period', 'talenttrack' ) . '</legend></fieldset>';
        echo '</div>';
        echo '<p class="tt-rs-notice" data-tt-rv-notice role="status" hidden></p>';
        echo '<ol class="tt-rs-canvas tt-rv-blocks" data-tt-rv-blocks aria-label="' . esc_attr__( 'Report blocks', 'talenttrack' ) . '"></ol>';
        echo '</div>';
    }

    private static function enqueue( int $id ): void {
        self::enqueueAssets();
        wp_enqueue_style( 'tt-report-studio', TT_PLUGIN_URL . 'assets/css/frontend-report-studio.css', [], TT_VERSION );
        wp_enqueue_script( 'tt-report-blocks', TT_PLUGIN_URL . 'assets/js/tt-report-blocks.js', [], TT_VERSION, true );
        wp_enqueue_script( 'tt-report-viewer', TT_PLUGIN_URL . 'assets/js/frontend-report-viewer.js', [ 'tt-report-blocks' ], TT_VERSION, true );

        $periods = [];
        foreach ( ReportFilters::periodLabels() as $key => $label ) {
            if ( $key !== '' ) $periods[] = [ 'key' => (string) $key, 'label' => $label ];
        }
        wp_localize_script( 'tt-report-viewer', 'TTReportViewer', [
            'id'      => $id,
            'rest'    => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'periods' => $periods,
            'columns' => FrontendReportStudioView::columns(),
            'i18n'    => [
                'loadError'    => __( 'The report could not be loaded. Reload the page to try again.', 'talenttrack' ),
                'loading'      => __( 'Loading…', 'talenttrack' ),
                /* translators: 1: first day, 2: last day of the period */
                'nothing'      => __( 'Nothing recorded between %1$s and %2$s.', 'talenttrack' ),
                'truncated'    => __( 'Only the first rows are shown.', 'talenttrack' ),
                'value'        => _x( 'Value', 'report studio column', 'talenttrack' ),
                /* translators: 1: first day, 2: last day of the period */
                'periodRange'  => __( '%1$s to %2$s', 'talenttrack' ),
                /* translators: %s: the change, for example "+4 pts" */
                'sincePrevious' => __( '%s against the period before', 'talenttrack' ),
                /* translators: %s: the academy's minimum share of the minutes, in percent */
                'target'       => __( 'Target: at least %s%% of the minutes available.', 'talenttrack' ),
                /* translators: %s: the squad's attendance, in percent */
                'squadAttendance' => __( 'Squad attendance: %s%%.', 'talenttrack' ),
                /* translators: 1: missed activities that flag a player, 2: late arrivals that flag a player */
                'flagThresholds'  => __( 'Flagged at %1$s missed activities or %2$s late arrivals.', 'talenttrack' ),
                /* translators: 1: first day, 2: last day of the period */
                'noneFlagged'     => __( 'No player was flagged on attendance between %1$s and %2$s.', 'talenttrack' ),
                /* translators: %s: the squad's average rating */
                'squadRating'   => __( 'Squad rating: %s.', 'talenttrack' ),
                /* translators: 1: the player's average rating, 2: number of evaluations */
                'overallRating' => __( 'Average over all ratings: %1$s, from %2$s evaluations.', 'talenttrack' ),
                /* translators: 1: played, 2: won, 3: drawn, 4: lost, 5: goals for, 6: goals against, 7: signed goal difference */
                'recordLine'   => __( 'Played %1$d · W %2$d D %3$d L %4$d · %5$d–%6$d (%7$s)', 'talenttrack' ),
                'block'        => _x( 'Block', 'report studio', 'talenttrack' ),
                'notAvailable' => __( 'Not available to you.', 'talenttrack' ),
                'empty'        => __( 'This report has no blocks yet.', 'talenttrack' ),
                'player'       => __( 'Player', 'talenttrack' ),
                'team'         => __( 'Team', 'talenttrack' ),
                'choose'       => __( 'Choose…', 'talenttrack' ),
            ],
        ] );
    }

    /** @return array{label:string,url:string} */
    private static function reportsCrumb(): array {
        return FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) );
    }
}
