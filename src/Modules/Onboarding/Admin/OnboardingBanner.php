<?php
namespace TT\Modules\Onboarding\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Onboarding\OnboardingState;

/**
 * OnboardingBanner — shown on the wp-admin TalentTrack dashboard until
 * the wizard is completed or explicitly dismissed.
 *
 * Renders a `notice notice-info` with a CTA button to the Welcome
 * page and a Skip-for-now link that flips `dismissed` so the menu
 * entry remains the only re-entry path.
 */
class OnboardingBanner {

    public static function init(): void {
        // The TT dashboard renders inside Shared\Admin\Menu::dashboard().
        // We hook on `admin_notices`, but only emit the banner when the
        // current page is the TT dashboard slug.
        add_action( 'admin_notices', [ self::class, 'maybeRender' ] );
    }

    public static function maybeRender(): void {
        if ( ! current_user_can( OnboardingPage::CAP ) ) return;
        if ( ! OnboardingState::shouldShowBanner() ) return;

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) return;
        // Render only on the TalentTrack parent dashboard.
        if ( strpos( (string) $screen->id, 'toplevel_page_talenttrack' ) === false ) {
            return;
        }

        $welcome_url  = admin_url( 'admin.php?page=' . OnboardingPage::SLUG );
        $dismiss_url  = OnboardingPage::actionUrl( 'tt_onboarding_dismiss' );
        ?>
        <div class="notice notice-info" style="border-left-color:#0b3d2e;">
            <p style="font-size:14px;">
                <strong><?php esc_html_e( 'Welcome to TalentTrack', 'talenttrack' ); ?></strong>
                — <?php esc_html_e( 'A short setup wizard creates your first team and admin profile so you can start tracking players.', 'talenttrack' ); ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url( $welcome_url ); ?>">
                    <?php esc_html_e( 'Start setup', 'talenttrack' ); ?>
                </a>
                <a class="button-link" href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:12px;">
                    <?php esc_html_e( 'Skip for now', 'talenttrack' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
