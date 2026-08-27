<?php
namespace TT\Modules\MatchPrep;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Export\ExporterRegistry;
use TT\Modules\MatchPrep\Export\MatchPrepPdfExporter;
use TT\Modules\MatchPrep\Print\MatchPrepPrintRouter;
use TT\Modules\MatchPrep\Rest\MatchPrepRestController;
use TT\Modules\MatchPrep\Wizards\MatchPrepWizard;
use TT\Shared\Wizards\WizardRegistry;

/**
 * MatchPrepModule (#838) — head coach match preparation surface.
 *
 * Ships a wizard pre-step (AvailabilityStep) plus a per-activity form
 * at `?tt_view=match-prep&activity_id=N` and a landscape A4 PDF export.
 * Persists into four tables introduced by migration 0118.
 *
 * Permissions: existing `tt_edit_activities` cap (head coach + HoD +
 * club admin + admin). No new cap.
 */
class MatchPrepModule implements ModuleInterface {

    public function getName(): string { return 'match-prep'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        MatchPrepRestController::init();

        // #2892 — a shared prep names minors and says who is expected to
        // start. Keep it out of search indexes and out of referrer headers,
        // the same way the match-analysis share does. Priority 5 so the
        // headers are set before anything renders.
        add_action( 'template_redirect', [ __CLASS__, 'guardShareRequestIndexing' ], 5 );

        // #1031 — standalone print route so the WP admin bar + theme
        // chrome stay off the printed sheet.
        MatchPrepPrintRouter::init();

        // Wizard registration — pre-form availability step.
        if ( class_exists( WizardRegistry::class ) ) {
            WizardRegistry::register( new MatchPrepWizard() );
        }

        // PDF export registration.
        if ( class_exists( ExporterRegistry::class ) ) {
            ExporterRegistry::register( new MatchPrepPdfExporter() );
        }
    }

    /**
     * #2892 — noindex + no-referrer on the share route only.
     *
     * Headers rather than robots.txt: the URL carries a token, so it must
     * not reach a crawler's index and must not leak through a referrer
     * header if the reader clicks a link on the page.
     */
    public static function guardShareRequestIndexing(): void {
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        if ( $view !== \TT\Modules\MatchPrep\Services\MatchPrepShareLink::VIEW_SLUG ) return;

        header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
        header( 'Referrer-Policy: no-referrer', true );

        add_action( 'wp_head', [ \TT\Modules\MatchPrep\Frontend\FrontendMatchPrepShareView::class, 'noindexMeta' ], 1 );
    }
}
