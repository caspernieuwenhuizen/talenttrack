<?php
namespace TT\Modules\MatchAnalysis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\MatchAnalysis\Print\MatchAnalysisPrintRouter;
use TT\Modules\MatchAnalysis\Rest\MatchAnalysisRestController;
use TT\Modules\MatchAnalysis\Wizards\MatchAnalysisWizard;
use TT\Shared\Wizards\WizardRegistry;

/**
 * MatchAnalysisModule (#2704) — the post-match review surface.
 *
 * Match prep captures the plan and match execution captures what happened;
 * this is the third side of the same match — what the coach made of it.
 * Structured per methodology team function, with a per-player half that
 * lands on each player's timeline as a `match_observed` event.
 *
 * Ships a per-activity form at `?tt_view=match-analysis&activity_id=N`, a
 * five-step wizard for writing the first draft, a staff-only HMAC share
 * link, and a chrome-free print route. Persists into the three tables
 * introduced by migration 0229.
 *
 * Permissions: the existing `tt_edit_activities` cap (head coach + HoD +
 * club admin + admin) for writing, `tt_view_activities` for reading. No new
 * capability — an academy that lets someone run a match lets them write it
 * up.
 *
 * Switchable: an academy that reviews its matches on a whiteboard turns
 * this off and loses nothing else.
 */
class MatchAnalysisModule implements ModuleInterface {

    public function getName(): string { return 'match-analysis'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        MatchAnalysisRestController::init();

        // Standalone print route, same isolation as match prep's: hook
        // before the theme shell renders so no chrome reaches paper.
        MatchAnalysisPrintRouter::init();

        if ( class_exists( WizardRegistry::class ) ) {
            WizardRegistry::register( new MatchAnalysisWizard() );
        }

        // A share URL names children and says how they played. Search
        // engines must not index it, and the header has to go out before
        // the page renders — the view itself runs inside the_content,
        // long after wp_head has closed.
        add_action( 'template_redirect', [ __CLASS__, 'guardShareRequestIndexing' ], 5 );
    }

    public static function guardShareRequestIndexing(): void {
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        if ( $view !== \TT\Modules\MatchAnalysis\Services\MatchAnalysisShareLink::VIEW_SLUG ) return;

        header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
        header( 'Referrer-Policy: no-referrer', true );

        add_action( 'wp_head', [ \TT\Modules\MatchAnalysis\Frontend\FrontendMatchAnalysisView::class, 'noindexMeta' ], 1 );
    }
}
