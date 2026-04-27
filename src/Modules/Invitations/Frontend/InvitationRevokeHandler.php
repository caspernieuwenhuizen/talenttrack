<?php
namespace TT\Modules\Invitations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Invitations\InvitationService;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_invitation_revoke`. Cap-gated by
 * `tt_revoke_invitation` (admin / head_dev / club_admin).
 */
class InvitationRevokeHandler {

    public static function handle(): void {
        if ( ! is_user_logged_in() ) wp_die( 'Not logged in.', 403 );
        if ( ! current_user_can( 'tt_revoke_invitation' ) ) wp_die( 'Insufficient permissions.', 403 );

        check_admin_referer( 'tt_invitation_revoke' );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( $id <= 0 ) wp_die( 'Bad request.', 400 );

        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) ) : home_url( '/' );

        $service = new InvitationService();
        if ( $service->revoke( $id ) ) {
            FlashMessages::add( 'success', __( 'Invitation revoked.', 'talenttrack' ) );
        } else {
            FlashMessages::add( 'error', __( 'Could not revoke this invitation. It may already be accepted, expired, or revoked.', 'talenttrack' ) );
        }

        wp_safe_redirect( $redirect );
        exit;
    }
}
