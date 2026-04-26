<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\TrackRepository;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_dev_track_save` — create or
 * update a development track row. Cap-gated by `tt_refine_idea`
 * (admin / head-dev / club-admin).
 */
class TrackSaveHandler {

    public static function handle(): void {
        if ( ! is_user_logged_in() ) wp_die( 'Not logged in.', 403 );
        if ( ! current_user_can( 'tt_refine_idea' ) ) wp_die( 'Insufficient permissions.', 403 );

        check_admin_referer( 'tt_dev_track_save' );

        $id          = isset( $_POST['id'] )          ? absint( $_POST['id'] )                                                        : 0;
        $name        = isset( $_POST['name'] )        ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) )                  : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['description'] ) )       : '';
        $redirect    = isset( $_POST['_redirect'] )   ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) )                     : home_url( '/' );

        if ( $name === '' ) {
            FlashMessages::add( 'error', __( 'Track name is required.', 'talenttrack' ) );
            wp_safe_redirect( $redirect );
            exit;
        }

        $repo = new TrackRepository();
        if ( $id > 0 ) {
            $repo->update( $id, $name, $description );
            FlashMessages::add( 'success', __( 'Track updated.', 'talenttrack' ) );
        } else {
            $repo->create( $name, $description );
            FlashMessages::add( 'success', __( 'Track created.', 'talenttrack' ) );
        }

        wp_safe_redirect( $redirect );
        exit;
    }
}
