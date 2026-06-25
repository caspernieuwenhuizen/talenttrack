<?php
namespace TT\Modules\Pdp\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * PdpSelfReviewForm (#1852) — the inbox surface for the self-review
 * nudge. There's nothing to fill in here: the reflection lives on the
 * player's My PDP page (where the editor + agenda already are), so the
 * task simply routes the player there. Completion happens when the
 * player saves their reflection (PdpSelfReviewTasks), not on this form.
 */
class PdpSelfReviewForm implements FormInterface {

    public function render( array $task ): string {
        $base = remove_query_arg( [ 'tt_view', 'id', 'task_id', 'player_id' ] );
        $url  = add_query_arg( 'tt_view', 'my-pdp', $base ?: home_url( '/' ) );

        $out  = '<p class="tt-pdp-selfreview-task">'
            . esc_html__( 'Add a short self-reflection before your development talk. It helps your coach, and it is optional, never required.', 'talenttrack' )
            . '</p>';
        $out .= '<p><a class="tt-btn tt-btn-primary" href="' . esc_url( $url ) . '">'
            . esc_html__( 'Open my development plan', 'talenttrack' )
            . '</a></p>';
        return $out;
    }

    public function validate( array $raw, array $task ): array {
        return [];
    }

    public function serializeResponse( array $raw, array $task ): array {
        return [];
    }
}
