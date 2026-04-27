<?php
namespace TT\Modules\Invitations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Invitations\InvitationsRepository;
use TT\Modules\Invitations\InvitationService;
use TT\Modules\Invitations\InvitationToken;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_invitation_accept` (and the
 * `nopriv` variant — recipient may not be logged in).
 *
 * Reads the token + recipient input, calls InvitationService::accept,
 * sets the auth cookie on success, redirects to the dashboard with a
 * welcome flash.
 */
class InvitationAcceptHandler {

    public static function handle(): void {
        check_admin_referer( 'tt_invitation_accept' );

        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
        if ( ! InvitationToken::isValidShape( $token ) ) {
            wp_die( 'Invalid token.', 400 );
        }

        $repo = new InvitationsRepository();
        $repo->sweepExpired();
        $row = $repo->findByToken( $token );
        if ( ! $row ) {
            wp_die( esc_html__( 'Invitation not found or already consumed.', 'talenttrack' ), 404 );
        }

        $payload = [
            'recovery_email'      => isset( $_POST['recovery_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['recovery_email'] ) ) : '',
            'password'            => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '',
            'jersey_number'       => isset( $_POST['jersey_number'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['jersey_number'] ) ) : '',
            'relationship_label'  => isset( $_POST['relationship_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['relationship_label'] ) ) : '',
            'notify_on_progress'  => ! empty( $_POST['notify_on_progress'] ),
        ];

        $base_redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) ) : home_url( '/' );

        $service = new InvitationService();

        // Silent-link path: visitor logged in + email matches.
        if ( is_user_logged_in() ) {
            $current = wp_get_current_user();
            if ( $current && $row->prefill_email && strcasecmp( (string) $current->user_email, (string) $row->prefill_email ) === 0 ) {
                $result = $service->silentLink( $row, (int) $current->ID );
                if ( $result['ok'] ) {
                    FlashMessages::add( 'success', __( 'Invitation accepted. Welcome aboard.', 'talenttrack' ) );
                    wp_safe_redirect( $base_redirect );
                    exit;
                }
                FlashMessages::add( 'error', (string) $result['error'] );
                wp_safe_redirect( add_query_arg( [ 'tt_view' => 'accept-invite', 'token' => $token ], $base_redirect ) );
                exit;
            }
        }

        $result = $service->accept( $row, $payload );

        if ( ! $result['ok'] ) {
            FlashMessages::add( 'error', (string) $result['error'] );
            wp_safe_redirect( add_query_arg( [ 'tt_view' => 'accept-invite', 'token' => $token ], $base_redirect ) );
            exit;
        }

        wp_set_auth_cookie( (int) $result['user_id'], true );
        FlashMessages::add( 'success', __( 'Welcome aboard. Your account is set up.', 'talenttrack' ) );
        wp_safe_redirect( $base_redirect );
        exit;
    }
}
