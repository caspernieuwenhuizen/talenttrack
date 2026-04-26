<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_dev_idea_reject` — the lead-dev
 * "Reject with note" path. Stores the note and transitions to
 * `rejected`. AuthorNotifier sends the email.
 */
class IdeaRejectHandler {

    public static function handle(): void {
        if ( ! is_user_logged_in() ) wp_die( 'Not logged in.', 403 );
        if ( ! current_user_can( 'tt_promote_idea' ) ) wp_die( 'Insufficient permissions.', 403 );

        check_admin_referer( 'tt_dev_idea_reject' );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( $id <= 0 ) wp_die( 'Bad request.', 400 );

        $note     = isset( $_POST['rejection_note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['rejection_note'] ) ) : '';
        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) ) : home_url( '/' );

        $repo = new IdeaRepository();
        $repo->transition( $id, IdeaStatus::REJECTED, [ 'rejection_note' => $note ] );

        FlashMessages::add( 'success', __( 'Idea rejected. Author has been notified.', 'talenttrack' ) );
        wp_safe_redirect( $redirect );
        exit;
    }
}
