<?php
namespace TT\Modules\Authorization\Impersonation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ImpersonationAdminPost (#0071 child 5) — admin-post handlers for
 * starting and ending an impersonation session via plain GET/POST.
 *
 * Routes:
 *   admin-post.php?action=tt_impersonation_start
 *     POST { target_user_id, reason? } + nonce → ImpersonationService::start
 *   admin-post.php?action=tt_impersonation_end
 *     GET  &_tt_impersonation_nonce → ImpersonationService::end
 *
 * Both redirect to a sensible landing on success. The "Switch back"
 * button in the banner posts here.
 */
final class ImpersonationAdminPost {

    public static function init(): void {
        add_action( 'admin_post_tt_impersonation_start', [ self::class, 'start' ] );
        add_action( 'admin_post_tt_impersonation_end',   [ self::class, 'end' ] );
    }

    public static function start(): void {
        if ( ! current_user_can( 'tt_impersonate_users' ) ) {
            wp_die( esc_html__( 'You do not have permission to impersonate users.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'tt_impersonation_start', '_tt_impersonation_nonce' );

        $target = isset( $_POST['target_user_id'] ) ? absint( $_POST['target_user_id'] ) : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['reason'] ) ) : '';

        $err = ImpersonationService::start( get_current_user_id(), $target, $reason );
        if ( $err instanceof \WP_Error ) {
            wp_die( esc_html( (string) $err->get_error_message() ), '', [ 'response' => 403 ] );
        }

        $redirect = isset( $_POST['return_to'] ) ? wp_unslash( (string) $_POST['return_to'] ) : home_url( '/' );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function end(): void {
        check_admin_referer( 'tt_impersonation_end', '_tt_impersonation_nonce' );
        ImpersonationService::end( 'manual' );

        $redirect = isset( $_GET['return_to'] )
            ? wp_unslash( (string) $_GET['return_to'] )
            : ( wp_get_referer() ?: admin_url() );
        wp_safe_redirect( $redirect );
        exit;
    }
}
