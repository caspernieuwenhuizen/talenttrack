<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;
use TT\Modules\Development\IdeaType;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_dev_idea_refine` — admin saves an
 * edit on a staged idea or moves status across the kanban (excluding
 * the promote action, which has its own handler with extra guards).
 */
class IdeaRefineHandler {

    public static function handle(): void {
        if ( ! is_user_logged_in() ) wp_die( 'Not logged in.', 403 );
        if ( ! current_user_can( 'tt_refine_idea' ) ) wp_die( 'Insufficient permissions.', 403 );

        check_admin_referer( 'tt_dev_idea_refine' );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( $id <= 0 ) wp_die( 'Bad request.', 400 );

        $repo = new IdeaRepository();
        $idea = $repo->find( $id );
        if ( ! $idea ) wp_die( 'Not found.', 404 );

        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) ) : home_url( '/' );

        $data = [];
        if ( isset( $_POST['title'] ) ) {
            $data['title'] = sanitize_text_field( wp_unslash( (string) $_POST['title'] ) );
        }
        if ( isset( $_POST['body'] ) ) {
            $data['body'] = sanitize_textarea_field( wp_unslash( (string) $_POST['body'] ) );
        }
        if ( isset( $_POST['slug'] ) ) {
            $data['slug'] = sanitize_title( (string) wp_unslash( $_POST['slug'] ) );
        }
        if ( isset( $_POST['type'] ) ) {
            $type = sanitize_key( (string) wp_unslash( $_POST['type'] ) );
            if ( IdeaType::isValid( $type ) ) $data['type'] = $type;
        }
        if ( array_key_exists( 'track_id', $_POST ) ) {
            $tid = absint( $_POST['track_id'] );
            $data['track_id'] = $tid > 0 ? $tid : null;
        }
        if ( array_key_exists( 'player_id', $_POST ) ) {
            $pid = absint( $_POST['player_id'] );
            $data['player_id'] = $pid > 0 ? $pid : null;
        }
        if ( array_key_exists( 'team_id', $_POST ) ) {
            $tmid = absint( $_POST['team_id'] );
            $data['team_id'] = $tmid > 0 ? $tmid : null;
        }

        $newStatus = isset( $_POST['status'] ) ? sanitize_key( (string) wp_unslash( $_POST['status'] ) ) : '';
        if ( $newStatus !== '' && in_array( $newStatus, [
            IdeaStatus::SUBMITTED,
            IdeaStatus::REFINING,
            IdeaStatus::READY_FOR_APPROVAL,
            IdeaStatus::IN_PROGRESS,
            IdeaStatus::DONE,
        ], true ) && $newStatus !== (string) $idea->status ) {
            $repo->transition( $id, $newStatus, $data );
        } elseif ( $data ) {
            $repo->update( $id, $data );
        }

        FlashMessages::add( 'success', __( 'Idea updated.', 'talenttrack' ) );
        wp_safe_redirect( $redirect );
        exit;
    }
}
