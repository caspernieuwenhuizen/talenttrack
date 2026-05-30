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
}
