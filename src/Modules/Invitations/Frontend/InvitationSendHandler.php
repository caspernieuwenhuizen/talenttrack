<?php
namespace TT\Modules\Invitations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Invitations\InvitationService;
use TT\Shared\Frontend\FlashMessages;

/**
 * Sends invitations whose delivery was held (#2964).
 *
 * Two actions, both cap-gated by `tt_send_invitation` — sending someone
 * their credentials is the second half of creating their invitation, so it
 * is the same permission rather than a new one:
 *
 *   admin-post.php?action=tt_invitation_send      — one invitation
 *   admin-post.php?action=tt_invitation_send_all  — every unsent one
 *
 * The bulk action reports per invitation rather than one aggregate result.
 * A send where three of twelve were skipped is not "sent" and is not
 * "failed", and the admin needs to know which three.
 */
class InvitationSendHandler {

    public static function handleOne(): void {
        self::guard( 'tt_invitation_send' );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( $id <= 0 ) wp_die( esc_html__( 'Bad request.', 'talenttrack' ), 400 );

        if ( ( new InvitationService() )->send( $id ) ) {
            FlashMessages::add( 'success', __( 'Invitation sent.', 'talenttrack' ) );
        } else {
            FlashMessages::add(
                'error',
                __( 'Could not send this invitation. It may already have been sent, accepted, expired or revoked.', 'talenttrack' )
            );
        }

        self::redirect();
    }

    public static function handleAll(): void {
        self::guard( 'tt_invitation_send_all' );

        $result  = ( new InvitationService() )->sendDeferred();
        $sent    = count( $result['sent'] );
        $skipped = count( $result['skipped'] );

        if ( $sent > 0 ) {
            FlashMessages::add(
                'success',
                sprintf(
                    /* translators: %d: number of invitations sent */
                    _n( '%d invitation sent.', '%d invitations sent.', $sent, 'talenttrack' ),
                    $sent
                )
            );
        }

        if ( $skipped > 0 ) {
            FlashMessages::add(
                'error',
                sprintf(
                    /* translators: %d: number of invitations that could not be sent */
                    _n(
                        '%d invitation could not be sent and was left alone.',
                        '%d invitations could not be sent and were left alone.',
                        $skipped,
                        'talenttrack'
                    ),
                    $skipped
                )
            );
        }

        if ( $sent === 0 && $skipped === 0 ) {
            FlashMessages::add( 'success', __( 'There was nothing waiting to be sent.', 'talenttrack' ) );
        }

        self::redirect();
    }

    private static function guard( string $nonce_action ): void {
        if ( ! is_user_logged_in() ) wp_die( esc_html__( 'Not logged in.', 'talenttrack' ), 403 );
        if ( ! current_user_can( 'tt_send_invitation' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ), 403 );
        }
        check_admin_referer( $nonce_action );
    }

    private static function redirect(): void {
        $redirect = isset( $_POST['_redirect'] )
            ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) )
            : home_url( '/' );

        wp_safe_redirect( $redirect );
        exit;
    }
}
