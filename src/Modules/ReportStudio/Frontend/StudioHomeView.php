<?php
namespace TT\Modules\ReportStudio\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\License\LicenseGate;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportViewerSubjects;
use TT\Modules\ReportStudio\Domain\StudioHome;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * StudioHomeView (#4962, epic #4564) — a Report Studio dashboard as the
 * persona's home, hosted by `PersonaLandingRenderer` in place of the persona
 * template's grid.
 *
 * The landing is the first screen after every login, so it reads the
 * dashboard in one request: the subjects the reader may switch to are
 * resolved here, on the server, and `frontend-report-viewer.js` asks only
 * `GET /reports/{id}/document`, which reads every block for this reader. It
 * renders no navigation of its own; the landing is exempt from the view
 * navigation rule and stays so.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class StudioHomeView {

    /**
     * The dashboard this reader lands on as this persona, or null when the
     * persona's own template shows: no Studio home, the Studio not in the
     * plan, or a dashboard this reader is not a recipient of.
     *
     * @return Report|null
     */
    public static function resolve( int $user_id, string $persona ): ?array {
        if ( ! LicenseGate::allows( 'report_studio' ) ) return null;
        if ( ! class_exists( '\\TT\\Core\\FeatureRegistry' ) || ! \TT\Core\FeatureRegistry::isEnabled( 'report_studio' ) ) return null;
        $report = StudioHome::reportFor( $persona );
        if ( $report === null ) return null;
        return ReportAccess::isRecipient( ReportReader::for( $user_id ), $report ) ? $report : null;
    }

    /**
     * The dashboard's shell; the viewer script fills it.
     *
     * @param Report $report
     */
    public static function render( array $report, int $user_id ): void {
        $subjects = ReportViewerSubjects::for( ReportReader::for( $user_id ), $report );
        self::enqueue( $report, $subjects );

        $name = $report['name'] !== '' ? $report['name'] : __( 'Untitled report', 'talenttrack' );
        echo '<section class="tt-rv tt-rv--home" data-tt-rv aria-labelledby="tt-rv-home-title">';
        echo '<h2 id="tt-rv-home-title" class="tt-rv-title">' . esc_html( $name ) . '</h2>';
        echo '<div class="tt-rs-context tt-rv-context">';
        echo '<div class="tt-field" data-tt-rv-subject hidden></div>';
        echo '<fieldset class="tt-rs-radios" data-tt-rv-periods hidden><legend>' . esc_html__( 'Period', 'talenttrack' ) . '</legend></fieldset>';
        echo '</div>';
        echo '<p class="tt-rs-notice" data-tt-rv-notice role="status" hidden></p>';
        echo '<div class="tt-rv-document" data-tt-rv-document aria-live="polite"></div>';
        echo '</section>';
    }

    /**
     * @param Report $report
     * @param array{teams: list<array{id:int, name:string}>, players: list<array{id:int, name:string, team_id:int}>} $subjects
     */
    private static function enqueue( array $report, array $subjects ): void {
        wp_enqueue_style( 'tt-report-studio', TT_PLUGIN_URL . 'assets/css/frontend-report-studio.css', [], TT_VERSION );
        StudioDocumentView::enqueue();
        wp_enqueue_script( 'tt-report-viewer', TT_PLUGIN_URL . 'assets/js/frontend-report-viewer.js', [], TT_VERSION, true );

        $periods = [];
        foreach ( ReportFilters::periodLabels() as $key => $label ) {
            if ( $key !== '' ) $periods[] = [ 'key' => (string) $key, 'label' => $label ];
        }
        wp_localize_script( 'tt-report-viewer', 'TTReportViewer', [
            'id'       => $report['id'],
            'rest'     => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'periods'  => $periods,
            // Read here, so the landing sends one request: the document.
            'report'   => [ 'scope' => $report['scope'], 'period_mode' => $report['period_mode'], 'layout' => $report['layout'] ],
            'subjects' => $subjects,
            'pdf'      => '',
            'i18n'     => [
                'loadError' => __( 'The report could not be loaded. Reload the page to try again.', 'talenttrack' ),
                'loading'   => __( 'Loading…', 'talenttrack' ),
                'player'    => __( 'Player', 'talenttrack' ),
                'team'      => __( 'Team', 'talenttrack' ),
                'choose'    => __( 'Choose…', 'talenttrack' ),
            ],
        ] );
    }
}
