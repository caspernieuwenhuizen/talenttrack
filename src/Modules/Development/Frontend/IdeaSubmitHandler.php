<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;
use TT\Modules\Development\IdeaType;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_dev_idea_submit`. Used by the
 * "Submit an idea" tile form on the frontend. Player + parent roles
 * never see the form (no `tt_submit_idea` cap) so this is a defensive
 * cap re-check.
 */
class IdeaSubmitHandler {

    public static function handle(): void {
        if ( ! is_user_logged_in() ) wp_die( 'Not logged in.', 403 );
        if ( ! current_user_can( 'tt_submit_idea' ) ) wp_die( 'Insufficient permissions.', 403 );

        check_admin_referer( 'tt_dev_idea_submit' );

        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';
        $body  = isset( $_POST['body'] )  ? sanitize_textarea_field( wp_unslash( (string) $_POST['body'] ) )  : '';
        $type  = isset( $_POST['type'] )  ? sanitize_key( (string) wp_unslash( $_POST['type'] ) ) : IdeaType::NEEDS_TRIAGE;
        $playerId = isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0;
        $teamId   = isset( $_POST['team_id'] )   ? absint( $_POST['team_id'] )   : 0;
        $trackId  = isset( $_POST['track_id'] )  ? absint( $_POST['track_id'] )  : 0;
        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) ) : home_url( '/' );

        if ( $title === '' ) {
            FlashMessages::add( 'error', __( 'Title is required.', 'talenttrack' ) );
            wp_safe_redirect( $redirect );
            exit;
        }
        if ( ! IdeaType::isValid( $type ) ) {
            $type = IdeaType::NEEDS_TRIAGE;
        }

        $repo = new IdeaRepository();
        $repo->insert( [
            'title'          => $title,
            'body'           => $body,
            'type'           => $type,
            'status'         => IdeaStatus::SUBMITTED,
            'author_user_id' => get_current_user_id(),
            'player_id'      => $playerId > 0 ? $playerId : null,
            'team_id'        => $teamId   > 0 ? $teamId   : null,
            'track_id'       => $trackId  > 0 ? $trackId  : null,
        ] );

        FlashMessages::add( 'success', __( 'Idea submitted. Thanks — it will appear in the staging queue for review.', 'talenttrack' ) );
        wp_safe_redirect( $redirect );
        exit;
    }
}
