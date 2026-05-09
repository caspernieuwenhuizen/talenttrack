<?php
namespace TT\Modules\Prospects\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Widgets\OnboardingPipelineWidget;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendOnboardingPipelineView (#0081 child 3) — `?tt_view=onboarding-pipeline`.
 *
 * Thin wrapper that renders `OnboardingPipelineWidget` at XL size on a
 * single-column page. Three reasons to ship it:
 *   - Academy Admin reaches it via a navigation tile from their
 *     control-panel landing.
 *   - HoD focus mode (full-screen view, no team grid).
 *   - Scout deep-dive from their compact-on-landing widget.
 *
 * Gated on `tt_view_prospects`; the widget already filters its data by
 * the user's matrix grants (scout sees only their own prospects).
 */
class FrontendOnboardingPipelineView extends FrontendViewBase {

    public static function render( int $user_id ): void {
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_view_prospects' ) ) {
            self::renderHeader( __( 'Onboarding pipeline', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to the onboarding pipeline.', 'talenttrack' ) . '</p>';
            return;
        }
        self::enqueueAssets();
        self::enqueueProspectLogScript();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Onboarding pipeline', 'talenttrack' ) );
        self::renderHeader( __( 'Onboarding pipeline', 'talenttrack' ) );

        $widget = new OnboardingPipelineWidget();
        $slot   = new WidgetSlot( 'onboarding_pipeline', '', Size::XL );
        $ctx    = new RenderContext( $user_id, CurrentClub::id(), '', home_url( '/' ) );
        echo $widget->render( $slot, $ctx );

        // CTA: log a new prospect — POSTs to the REST endpoint via JS,
        // which dispatches the LogProspect chain and redirects the
        // scout into the resulting task form.
        ?>
        <p style="margin-top: 18px;">
            <button type="button" class="tt-btn tt-btn-primary"
                    data-tt-prospect-log
                    style="display:inline-block; min-height:48px;">
                <?php esc_html_e( '+ New prospect', 'talenttrack' ); ?>
            </button>
        </p>
        <?php
    }

    private static function enqueueProspectLogScript(): void {
        wp_enqueue_script(
            'tt-frontend-prospects-log',
            TT_PLUGIN_URL . 'assets/js/frontend-prospects-log.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-frontend-prospects-log',
            'TT_PROSPECT_LOG',
            [
                'rest_url' => esc_url_raw( rest_url( 'talenttrack/v1/prospects/log' ) ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
                'i18n'     => [
                    'error'   => __( 'Could not start the prospect-logging flow. Please try again.', 'talenttrack' ),
                    'network' => __( 'Network error. Please try again.', 'talenttrack' ),
                ],
            ]
        );
    }
}
