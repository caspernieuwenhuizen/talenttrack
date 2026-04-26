<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\TrackRepository;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_dev_track_delete`. Detaches any
 * tagged ideas from the track first (TrackRepository::delete handles
 * the detach), then drops the row.
 */
class TrackDeleteHandler {

    public static function handle(): void {
        if ( ! is_user_logged_in() ) wp_die( 'Not logged in.', 403 );
        if ( ! current_user_can( 'tt_refine_idea' ) ) wp_die( 'Insufficient permissions.', 403 );

        check_admin_referer( 'tt_dev_track_delete' );

        $id       = isset( $_POST['id'] )        ? absint( $_POST['id'] )                                       : 0;
        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) )    : home_url( '/' );

        if ( $id <= 0 ) wp_die( 'Bad request.', 400 );

        $repo = new TrackRepository();
        $repo->delete( $id );

        FlashMessages::add( 'success', __( 'Track deleted.', 'talenttrack' ) );
        wp_safe_redirect( $redirect );
        exit;
    }
}
