<?php
namespace TT\Modules\Pdp\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\RecordLink;

/**
 * PdpFormPrintAction (#4982, epic #4975) — "Print" on a signed conversation
 * form, for whoever may read it there. Composed into the trainer's form and
 * the family's read view; not a view of its own.
 *
 * Two links, one shown: under a finger the PDF (a phone has no print
 * dialog worth the name), under a mouse the print page. Both are the same
 * renderer, and both answer with the same refusal as the screen.
 */
final class PdpFormPrintAction {

    public static function render( int $conversation_id ): void {
        $label = __( 'Print', 'talenttrack' );
        echo '<p class="tt-pdp-printact">';
        echo '<a class="tt-btn tt-btn-secondary tt-pdp-printact__pdf" href="' . esc_url( self::pdfUrl( $conversation_id ) ) . '">' . esc_html( $label ) . '</a>';
        echo '<a class="tt-btn tt-btn-secondary tt-pdp-printact__page" target="_blank" rel="noopener" href="' . esc_url( self::printUrl( $conversation_id ) ) . '">' . esc_html( $label ) . '</a>';
        echo '</p>';
    }

    public static function printUrl( int $conversation_id ): string {
        return add_query_arg( [ 'tt_pdp_print' => 1, 'conversation_id' => $conversation_id ], RecordLink::dashboardUrl() );
    }

    public static function pdfUrl( int $conversation_id ): string {
        return add_query_arg( [
            'format'          => 'pdf',
            'conversation_id' => $conversation_id,
            '_wpnonce'        => wp_create_nonce( 'wp_rest' ),
        ], rest_url( 'talenttrack/v1/exports/pdp_pdf' ) );
    }
}
