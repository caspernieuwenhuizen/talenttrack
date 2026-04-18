<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Auth\LoginForm;

/**
 * DashboardShortcode — owns [talenttrack_dashboard].
 *
 * Dispatches to either the login form (logged out) or the appropriate
 * role-based dashboard view (logged in).
 */
class DashboardShortcode {

    public static function register(): void {
        add_shortcode( 'talenttrack_dashboard', [ __CLASS__, 'render' ] );
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public static function render( $atts = [] ): string {
        wp_enqueue_style( 'tt-public', TT_PLUGIN_URL . 'assets/css/public.css', [], TT_VERSION );
        wp_enqueue_script( 'tt-public', TT_PLUGIN_URL . 'assets/js/public.js', [ 'jquery' ], TT_VERSION, true );
        wp_localize_script( 'tt-public', 'TT', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'tt_frontend' ),
        ]);

        // Logged out → login form
        if ( ! is_user_logged_in() ) {
            /** @var LoginForm $form */
            $form = Kernel::instance()->container()->get( 'auth.login_form' );
            $error = isset( $_GET['tt_login_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_login_error'] ) ) : '';
            return $form->render( $error );
        }

        ob_start();
        echo '<div class="tt-dashboard">';
        self::renderHeader();

        $user_id  = get_current_user_id();
        $is_admin = current_user_can( 'tt_manage_settings' );
        $is_coach = current_user_can( 'tt_evaluate_players' );
        $player   = QueryHelpers::get_player_for_user( $user_id );

        if ( $player && ! $is_coach && ! $is_admin ) {
            ( new PlayerDashboardView() )->render( $player );
        } elseif ( $is_coach || $is_admin ) {
            ( new CoachDashboardView() )->render( $user_id, $is_admin );
        } else {
            echo '<p class="tt-notice">' . esc_html__( 'No player profile is linked to your account. Please contact your administrator.', 'talenttrack' ) . '</p>';
        }

        echo '</div>';

        /** @var string $output */
        $output = ob_get_clean() ?: '';
        /** @var string $filtered */
        $filtered = apply_filters( 'tt_dashboard_data', $output, $user_id );
        return $filtered;
    }

    private static function renderHeader(): void {
        $logo = QueryHelpers::get_config( 'logo_url', '' );
        $name = QueryHelpers::get_config( 'academy_name', 'TalentTrack' );
        echo '<div class="tt-dash-header">';
        if ( $logo ) echo '<img src="' . esc_url( $logo ) . '" class="tt-dash-logo" alt="" />';
        echo '<h2 class="tt-dash-title">' . esc_html( $name ) . '</h2>';
        echo '</div>';
    }
}
