<?php
namespace TT\Modules\Invitations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Invitations\InvitationKind;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_invitation_message_save` — saves
 * one of the six default WhatsApp/share-message templates. Validates
 * the template contains `{url}` so the share button can't generate a
 * message without an acceptance URL.
 */
class MessageSaveHandler {

    public static function handle(): void {
        if ( ! is_user_logged_in() ) wp_die( 'Not logged in.', 403 );
        if ( ! current_user_can( 'tt_manage_invite_messages' ) ) wp_die( 'Insufficient permissions.', 403 );

        check_admin_referer( 'tt_invitation_message_save' );

        $kind   = isset( $_POST['kind'] )   ? sanitize_key( (string) wp_unslash( $_POST['kind'] ) ) : '';
        $locale = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['locale'] ) ) : '';
        $body   = isset( $_POST['body'] )   ? trim( wp_unslash( (string) $_POST['body'] ) ) : '';
        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) ) : home_url( '/' );

        if ( ! InvitationKind::isValid( $kind ) || ! preg_match( '/^[a-z]{2}_[A-Z]{2}$/', $locale ) || $body === '' ) {
            FlashMessages::add( 'error', __( 'Bad input.', 'talenttrack' ) );
            wp_safe_redirect( $redirect );
            exit;
        }

        if ( strpos( $body, '{url}' ) === false ) {
            FlashMessages::add( 'error', __( 'The message must include the {url} placeholder so the recipient can open the invitation.', 'talenttrack' ) );
            wp_safe_redirect( $redirect );
            exit;
        }

        $config = new ConfigService();
        $config->set( "invite_message_{$kind}_{$locale}", $body );

        FlashMessages::add( 'success', __( 'Invitation message saved.', 'talenttrack' ) );
        wp_safe_redirect( $redirect );
        exit;
    }
}
