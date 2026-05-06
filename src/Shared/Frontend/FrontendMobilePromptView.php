<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendMobilePromptView — the polite "open this on desktop" page
 * shown when a phone-class user-agent visits a `desktop_only` route
 * (#0084 Child 1).
 *
 * Rendered inline by `DashboardShortcode::render()` when:
 *   1. The visitor is a phone (`MobileDetector::isPhone()`).
 *   2. The requested view's classification is `desktop_only` per
 *      `MobileSurfaceRegistry`.
 *   3. The per-club `force_mobile_for_user_agents` setting is on.
 *   4. The user has NOT bypassed via `?force_mobile=1`.
 *
 * Surfaces two affordances:
 *   - **Email me the link** — submits a one-line email to the user with
 *     the deep link to the desktop-only page. Lets a coach on the train
 *     send themselves a reminder to come back at the office.
 *   - **Go to dashboard** — back to a sensible mobile-friendly landing.
 *
 * Audit-logs each show via `mobile_desktop_prompt_shown` so operators
 * can spot routes that get a lot of mobile traffic and review the
 * classification.
 *
 * Mobile-first by construction — a `desktop_only` surface that only
 * renders on phones isn't allowed to be cramped itself.
 */
class FrontendMobilePromptView extends FrontendViewBase {

    public static function render( int $user_id, string $blocked_view = '' ): void {
        $blocked_view = sanitize_key( $blocked_view );

        // Audit-log the show. The action key is on the same shape as the
        // existing `tt_audit_log` convention so the log viewer doesn't need
        // any per-event awareness. Logged once per render; downstream
        // reload-loops would re-log, which is intentional — high counts
        // on a single (route, user) pair is the signal operators want.
        if ( class_exists( '\\TT\\Infrastructure\\Audit\\AuditService' ) ) {
            ( new \TT\Infrastructure\Audit\AuditService() )->record(
                'mobile.desktop_prompt_shown',
                'view',
                0,
                [ 'view' => $blocked_view ]
            );
        }

        self::renderHeader( __( 'Open on desktop', 'talenttrack' ) );

        $current_url = self::currentUrl();
        $email_action = admin_url( 'admin-post.php' );
        $dashboard_url = WizardEntryPoint::dashboardBaseUrl();

        $tt_msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) $_GET['tt_msg'] ) : '';

        echo '<div style="max-width:480px; margin:0 auto; padding:16px; text-align:center;">';

        if ( $tt_msg === 'mobile_link_sent' ) {
            echo '<div class="tt-notice tt-notice-success" role="status" style="margin-bottom:16px; padding:12px 16px;">'
                . esc_html__( "We sent the link to your account email. Check your inbox.", 'talenttrack' )
                . '</div>';
        } elseif ( $tt_msg === 'mobile_link_failed' ) {
            echo '<div class="tt-notice tt-notice-error" role="alert" style="margin-bottom:16px; padding:12px 16px;">'
                . esc_html__( "We couldn't send the link. Try the dashboard link below.", 'talenttrack' )
                . '</div>';
        }

        echo '<h2 style="margin:24px 0 8px;">' . esc_html__( 'This page is designed for desktop.', 'talenttrack' ) . '</h2>';
        echo '<p style="color:#5b6e75; margin:0 0 24px;">'
            . esc_html__( 'Open it on a laptop or computer for the best experience.', 'talenttrack' )
            . '</p>';

        echo '<form method="post" action="' . esc_url( $email_action ) . '" style="margin-bottom:24px;">';
        wp_nonce_field( 'tt_mobile_email_link', 'tt_mobile_nonce' );
        echo '<input type="hidden" name="action" value="tt_mobile_email_link">';
        echo '<input type="hidden" name="target_url" value="' . esc_attr( $current_url ) . '">';
        echo '<button type="submit" class="tt-button tt-button-primary" style="width:100%; padding:14px; font-size:16px;">'
            . esc_html__( 'Email me the link', 'talenttrack' )
            . '</button>';
        echo '</form>';

        echo '<p style="margin:0 0 8px; color:#5b6e75;">' . esc_html__( 'Or use the dashboard:', 'talenttrack' ) . '</p>';
        echo '<a href="' . esc_url( $dashboard_url ) . '" class="tt-button" style="display:block; padding:14px; font-size:16px; text-decoration:none;">'
            . esc_html__( 'Go to dashboard', 'talenttrack' )
            . '</a>';

        echo '<p style="margin-top:32px; color:#999; font-size:13px;">';
        echo esc_html__( "Need this on your phone anyway?", 'talenttrack' ) . ' ';
        $force_url = add_query_arg( 'force_mobile', '1', $current_url );
        echo '<a href="' . esc_url( $force_url ) . '" style="color:#5b6e75;">'
            . esc_html__( 'Show it anyway', 'talenttrack' )
            . '</a>.';
        echo '</p>';

        echo '</div>';
    }

    /**
     * The full URL of the current request, used to populate the email
     * link and the "Show it anyway" escape hatch.
     */
    private static function currentUrl(): string {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) return home_url( '/' );
        $path = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
        $host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
        if ( $host === '' ) return home_url( $path );
        $scheme = is_ssl() ? 'https' : 'http';
        return $scheme . '://' . $host . $path;
    }
}
