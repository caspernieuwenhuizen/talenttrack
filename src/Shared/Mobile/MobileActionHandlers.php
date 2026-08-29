<?php
namespace TT\Shared\Mobile;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsOutcomeSummary;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Templates\DesktopLinkTemplate;

/**
 * MobileActionHandlers — admin-post endpoints for the mobile prompt page
 * (#0084 Child 1).
 *
 * Single endpoint at the moment: `tt_mobile_email_link`. Sends a one-line
 * email with the desktop-only link to the user's account email. Used by
 * the prompt page's "Email me the link" button so a coach who's on the
 * train notices they need this view, sends themselves a reminder, opens
 * it back at the office.
 *
 * The email-me-link path validates the target URL is internal (host matches
 * `home_url()`) so the form can't be turned into an open-redirect / SSRF
 * vector by a crafted POST.
 */
final class MobileActionHandlers {

    public const ACTION_EMAIL_LINK   = 'tt_mobile_email_link';
    public const ACTION_SAVE_SETTING = 'tt_mobile_save_setting';

    public static function init(): void {
        add_action( 'admin_post_' . self::ACTION_EMAIL_LINK,   [ self::class, 'handleEmailLink' ] );
        add_action( 'admin_post_' . self::ACTION_SAVE_SETTING, [ self::class, 'handleSaveSetting' ] );
        // Logged-out users hit `admin_post_nopriv_*` — the gate is
        // already inside the handler (current user must be logged in
        // for the email path to make sense), but registering the
        // nopriv variant lets us redirect them gracefully.
        add_action( 'admin_post_nopriv_' . self::ACTION_EMAIL_LINK, [ self::class, 'handleEmailLink' ] );
    }

    /**
     * Save the per-club `force_mobile_for_user_agents` setting. Operator-only.
     */
    public static function handleSaveSetting(): void {
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( self::ACTION_SAVE_SETTING, 'tt_mobile_nonce' );

        $enabled = ! empty( $_POST['enabled'] );
        ( new MobileSettings() )->setMobileGateEnabled( $enabled );

        $return_to = isset( $_POST['return_to'] )
            ? esc_url_raw( wp_unslash( (string) $_POST['return_to'] ) )
            : home_url( '/' );
        $safe = wp_validate_redirect( $return_to, home_url( '/' ) );
        wp_safe_redirect( add_query_arg( 'tt_msg', 'mobile_setting_saved', $safe ) );
        exit;
    }

    public static function handleEmailLink(): void {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }
        check_admin_referer( 'tt_mobile_email_link', 'tt_mobile_nonce' );

        $target_url = isset( $_POST['target_url'] )
            ? esc_url_raw( wp_unslash( (string) $_POST['target_url'] ) )
            : '';
        // Defence in depth: only same-host URLs. `wp_validate_redirect`
        // collapses every off-site URL to the empty fallback, which we
        // treat as failure rather than substituting in.
        $safe_url = wp_validate_redirect( $target_url, '' );
        if ( $safe_url === '' ) {
            self::redirectBack( $target_url, 'mobile_link_failed' );
        }

        $user = wp_get_current_user();
        $to   = (string) ( \TT\Infrastructure\Identity\ContactResolver::emailForUser( (int) $user->ID ) ?? '' );
        if ( $to === '' || ! is_email( $to ) ) {
            self::redirectBack( $safe_url, 'mobile_link_failed' );
        }

        // #2604 — through Comms so the send is audited like every other.
        // The message type is operational: the user asked for this link
        // seconds ago and is waiting on it, so neither an old opt-out nor a
        // quiet-hours window may hold it back.
        $results = CommsDispatcher::dispatchSync(
            DesktopLinkTemplate::KEY,
            [
                'site_name' => (string) get_bloginfo( 'name' ),
                'link'      => $safe_url,
            ],
            [ Recipient::self(
                (int) $user->ID,
                $to,
                '',
                (string) get_user_meta( (int) $user->ID, 'locale', true )
            ) ],
            [ 'message_type' => MessageType::DESKTOP_LINK ]
        );

        $sent = CommsOutcomeSummary::sentCount( $results ) > 0;

        self::redirectBack( $safe_url, $sent ? 'mobile_link_sent' : 'mobile_link_failed' );
    }

    /**
     * Redirects back to the prompt page (carrying the original blocked
     * URL so the user can decide their next step) with a one-shot
     * `tt_msg` flag. Exits.
     */
    private static function redirectBack( string $target_url, string $msg ): void {
        $base = $target_url !== '' ? $target_url : home_url( '/' );
        $url  = add_query_arg( 'tt_msg', $msg, $base );
        wp_safe_redirect( $url );
        exit;
    }
}
