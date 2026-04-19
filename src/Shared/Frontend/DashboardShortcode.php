<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Auth\LoginForm;
use TT\Modules\Auth\LogoutHandler;

/**
 * DashboardShortcode — owns [talenttrack_dashboard].
 *
 * v2.5.1: replaces the bare Log-out button with a user-menu dropdown.
 * Click the name → menu with:
 *   - Edit profile (wp-admin/profile.php, allowed via FrontendAccessControl)
 *   - Log out (triggers wp_logout + home redirect)
 *
 * The dropdown uses a tiny inline script — no dependency on public.js,
 * no jQuery required, no risk of breaking existing AJAX handlers.
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
            'i18n'     => [
                'saving'               => __( 'Saving...', 'talenttrack' ),
                'saved'                => __( 'Saved.', 'talenttrack' ),
                'error_generic'        => __( 'Error.', 'talenttrack' ),
                'network_error'        => __( 'Network error.', 'talenttrack' ),
                'confirm_delete_goal'  => __( 'Delete this goal?', 'talenttrack' ),
                'save_evaluation'      => __( 'Save Evaluation', 'talenttrack' ),
                'save_session'         => __( 'Save Session', 'talenttrack' ),
                'add_goal'             => __( 'Add Goal', 'talenttrack' ),
                'save'                 => __( 'Save', 'talenttrack' ),
            ],
        ]);

        // Route guard — no partial render for logged-out users.
        if ( ! is_user_logged_in() ) {
            /** @var LoginForm $form */
            $form = Kernel::instance()->container()->get( 'auth.login_form' );
            $error = isset( $_GET['tt_login_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_login_error'] ) ) : '';

            $reset_notice = '';
            if ( isset( $_GET['checkemail'] ) && $_GET['checkemail'] === 'confirm' ) {
                $reset_notice = '<div class="tt-notice-inline">'
                    . esc_html__( 'Check your email for a password reset link.', 'talenttrack' )
                    . '</div>';
            }
            return $reset_notice . $form->render( $error );
        }

        // Authenticated dashboard.
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
        $user = wp_get_current_user();

        // Profile URL — WP's native profile edit page. FrontendAccessControl
        // whitelists profile.php so this works for all logged-in users.
        $profile_url = get_edit_profile_url( (int) $user->ID );
        $logout_url  = LogoutHandler::url();

        echo '<div class="tt-dash-header">';
        echo '<div class="tt-dash-brand">';
        if ( $logo ) echo '<img src="' . esc_url( $logo ) . '" class="tt-dash-logo" alt="" />';
        echo '<h2 class="tt-dash-title">' . esc_html( $name ) . '</h2>';
        echo '</div>';

        // User menu (dropdown)
        echo '<div class="tt-user-menu">';
        echo '<button type="button" class="tt-user-menu-trigger" aria-haspopup="true" aria-expanded="false">';
        echo '<span class="tt-user-menu-name">' . esc_html( $user->display_name ) . '</span>';
        echo '<span class="tt-user-menu-caret" aria-hidden="true">▾</span>';
        echo '</button>';
        echo '<div class="tt-user-menu-dropdown" role="menu">';
        echo '<a href="' . esc_url( $profile_url ) . '" class="tt-user-menu-item" role="menuitem">';
        echo esc_html__( 'Edit profile', 'talenttrack' );
        echo '</a>';
        echo '<a href="' . esc_url( $logout_url ) . '" class="tt-user-menu-item tt-user-menu-item-logout" role="menuitem">';
        echo esc_html__( 'Log out', 'talenttrack' );
        echo '</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Inline dropdown behaviour — no jQuery, no external dependency.
        ?>
        <script>
        (function(){
            var menu = document.currentScript.parentNode.querySelector('.tt-user-menu');
            if (!menu) return;
            var trigger = menu.querySelector('.tt-user-menu-trigger');
            var dropdown = menu.querySelector('.tt-user-menu-dropdown');
            if (!trigger || !dropdown) return;

            function closeMenu() {
                menu.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            }
            function openMenu() {
                menu.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
            }

            trigger.addEventListener('click', function(e){
                e.stopPropagation();
                if (menu.classList.contains('is-open')) { closeMenu(); } else { openMenu(); }
            });
            document.addEventListener('click', function(e){
                if (menu.classList.contains('is-open') && !menu.contains(e.target)) {
                    closeMenu();
                }
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && menu.classList.contains('is-open')) {
                    closeMenu();
                    trigger.focus();
                }
            });
        })();
        </script>
        <?php
    }
}
