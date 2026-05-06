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
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Onboarding pipeline', 'talenttrack' ) );
        self::renderHeader( __( 'Onboarding pipeline', 'talenttrack' ) );

        $widget = new OnboardingPipelineWidget();
        $slot   = new WidgetSlot( 'onboarding_pipeline', '', Size::XL );
        $ctx    = new RenderContext( $user_id, CurrentClub::id(), '', home_url( '/' ) );
        echo $widget->render( $slot, $ctx );

        // CTA: log a new prospect — fires the chain via REST.
        ?>
        <p style="margin-top: 18px;">
            <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( rest_url( 'talenttrack/v1/prospects/log' ) ); ?>"
               data-tt-prospect-log
               style="display:inline-block;">
                <?php esc_html_e( '+ New prospect', 'talenttrack' ); ?>
            </a>
        </p>
        <?php
    }
}
