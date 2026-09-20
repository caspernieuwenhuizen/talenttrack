<?php
namespace TT\Modules\Invitations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Invitations\GuardianContact\GuardianContactRequest;
use TT\Shared\Frontend\FlashMessages;

/**
 * GuardianContactHandlers (#3794) — the two form posts of the
 * guardian-contact link.
 *
 *   tt_guardian_contact_request  staff ask a family (cap-gated)
 *   tt_guardian_contact_submit   the family answers (nopriv — the token
 *                                is the credential)
 *
 * Both are thin: they read the request, hand it to
 * {@see GuardianContactRequest}, and redirect. Nothing about who may be
 * asked, what may be written or when a link stops working is decided
 * here.
 */
final class GuardianContactHandlers {

    public static function request(): void {
        check_admin_referer( 'tt_guardian_contact_request' );

        if ( ! current_user_can( GuardianContactRequest::CAP ) ) {
            wp_die( esc_html__( 'You are not allowed to send contact requests.', 'talenttrack' ), 403 );
        }

        $player_id = isset( $_POST['player_id'] ) ? absint( wp_unslash( $_POST['player_id'] ) ) : 0;
        $email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
        $redirect  = isset( $_POST['_redirect'] )
            ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) )
            : home_url( '/' );

        $result = GuardianContactRequest::send( $player_id, $email );

        if ( $result['ok'] ) {
            FlashMessages::add( 'success', sprintf(
                /* translators: %s: the email address the request was sent to */
                __( 'Request sent to %s. The link works once and expires; the details land on the record as soon as the family answers.', 'talenttrack' ),
                $email
            ) );
        } else {
            FlashMessages::add( 'error', (string) $result['error'] );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    public static function submit(): void {
        check_admin_referer( 'tt_guardian_contact_submit' );

        $token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
        $request = GuardianContactRequest::resolve( $token );

        // One sentence for every refusal — see the view's docblock.
        if ( $request === null ) {
            FlashMessages::add( 'error', __( 'This link is no longer valid. Ask the academy to send you a new one.', 'talenttrack' ) );
            wp_safe_redirect( self::pageUrl( [] ) );
            exit;
        }

        $result = GuardianContactRequest::submit( $request, [
            'guardian_name'  => isset( $_POST['guardian_name'] ) ? wp_unslash( (string) $_POST['guardian_name'] ) : '',
            'guardian_email' => isset( $_POST['guardian_email'] ) ? wp_unslash( (string) $_POST['guardian_email'] ) : '',
            'guardian_phone' => isset( $_POST['guardian_phone'] ) ? wp_unslash( (string) $_POST['guardian_phone'] ) : '',
            'consent'        => ! empty( $_POST['consent'] ),
        ] );

        if ( ! $result['ok'] ) {
            FlashMessages::add( 'error', (string) $result['error'] );
            // Back to the form with the token: a typo must not cost the
            // family their link.
            wp_safe_redirect( self::pageUrl( [ 'token' => $token ] ) );
            exit;
        }

        // Thank-you page, without the token — it is spent, and a used
        // token in a browser history is one more copy of a credential.
        wp_safe_redirect( self::pageUrl( [ 'tt_gc' => 'done' ] ) );
        exit;
    }

    /**
     * @param array<string,string> $args
     */
    private static function pageUrl( array $args ): string {
        return GuardianContactRequest::pageUrl( $args );
    }
}
