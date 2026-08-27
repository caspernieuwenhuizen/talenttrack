<?php
namespace TT\Modules\Import\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Import\ImportUndoService;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_import_undo` (#2959).
 *
 * Gated on `manage_options`, matching the import surface itself.
 */
class ImportUndoHandler {

    public static function handle(): void {
        if ( ! is_user_logged_in() ) wp_die( esc_html__( 'Not logged in.', 'talenttrack' ), 403 );
        if ( ! current_user_can( FrontendImportHistoryView::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ), 403 );
        }

        check_admin_referer( 'tt_import_undo' );

        $batch_key = isset( $_POST['batch_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['batch_key'] ) ) : '';
        if ( $batch_key === '' ) wp_die( esc_html__( 'Bad request.', 'talenttrack' ), 400 );

        $result = ( new ImportUndoService() )->undo( $batch_key );

        if ( ! $result['ok'] ) {
            FlashMessages::add( 'error', $result['error'] );
        } else {
            $described = ImportUndoService::describeCounts( $result['deleted'] );
            FlashMessages::add(
                'success',
                $described !== ''
                    ? sprintf(
                        /* translators: %s: a list like "3 teams, 24 players" */
                        __( 'Import undone. Removed %s.', 'talenttrack' ),
                        $described
                    )
                    // Undoing twice is a no-op rather than an error — the
                    // rows were already gone.
                    : __( 'That import had already been undone. Nothing was removed.', 'talenttrack' )
            );
        }

        $redirect = isset( $_POST['_redirect'] )
            ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) )
            : home_url( '/' );

        wp_safe_redirect( $redirect );
        exit;
    }
}
