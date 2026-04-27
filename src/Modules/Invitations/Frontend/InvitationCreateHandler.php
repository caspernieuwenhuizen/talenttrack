<?php
namespace TT\Modules\Invitations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Invitations\InvitationKind;
use TT\Modules\Invitations\InvitationService;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_invitation_create`. Used by the
 * "Generate invite" button on the player edit form, the people edit
 * form, and the frontend roster row.
 */
class InvitationCreateHandler {

    public static function handle(): void {
        if ( ! is_user_logged_in() ) wp_die( 'Not logged in.', 403 );
        if ( ! current_user_can( 'tt_send_invitation' ) ) wp_die( 'Insufficient permissions.', 403 );

        check_admin_referer( 'tt_invitation_create' );

        $kind = isset( $_POST['kind'] ) ? sanitize_key( (string) wp_unslash( $_POST['kind'] ) ) : InvitationKind::PLAYER;
        if ( ! InvitationKind::isValid( $kind ) ) {
            wp_die( 'Invalid kind.', 400 );
        }

        $args = [
            'kind'                       => $kind,
            'target_player_id'           => isset( $_POST['target_player_id'] ) ? absint( $_POST['target_player_id'] ) : null,
            'target_person_id'           => isset( $_POST['target_person_id'] ) ? absint( $_POST['target_person_id'] ) : null,
            'target_team_id'             => isset( $_POST['target_team_id'] )   ? absint( $_POST['target_team_id'] )   : null,
            'target_functional_role_key' => isset( $_POST['target_functional_role_key'] ) ? sanitize_key( (string) wp_unslash( $_POST['target_functional_role_key'] ) ) : null,
            'prefill_first_name'         => isset( $_POST['prefill_first_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['prefill_first_name'] ) ) : null,
            'prefill_last_name'          => isset( $_POST['prefill_last_name'] )  ? sanitize_text_field( wp_unslash( (string) $_POST['prefill_last_name'] ) )  : null,
            'prefill_email'              => isset( $_POST['prefill_email'] )      ? sanitize_email( wp_unslash( (string) $_POST['prefill_email'] ) )           : null,
            'override_cap'               => ! empty( $_POST['override_cap'] ),
            'override_reason'            => isset( $_POST['override_reason'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['override_reason'] ) ) : null,
        ];

        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) ) : home_url( '/' );

        $service = new InvitationService();
        $result = $service->create( $args );

        if ( ! $result['ok'] ) {
            FlashMessages::add( 'error', (string) $result['error'] );
            if ( ! empty( $result['cap_exceeded'] ) ) {
                $redirect = add_query_arg( 'tt_invite_cap', '1', $redirect );
            }
            wp_safe_redirect( $redirect );
            exit;
        }

        FlashMessages::add( 'success', __( 'Invitation generated. Share it via WhatsApp or copy the link.', 'talenttrack' ) );
        // Tag the redirect with the new invitation id so the surface can
        // pop the share modal automatically.
        $redirect = add_query_arg( 'tt_invite_id', (string) $result['id'], $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }
}
