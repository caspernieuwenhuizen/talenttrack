<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — Pick evaluation type.
 *
 * On submit, the wizard hands off to the existing full evaluation
 * form, with the player + type pre-filled via query string. The
 * evaluation form already handles the heavy lifting (categories,
 * sub-ratings, attachments) — this wizard just pre-narrows the
 * choice so the user lands at the right form first time.
 */
final class TypeStep implements WizardStepInterface {

    public function slug(): string { return 'type'; }
    public function label(): string { return __( 'Type', 'talenttrack' ); }

    public function render( array $state ): void {
        global $wpdb;
        $types = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}tt_lookups
             WHERE lookup_type = %s AND archived_at IS NULL AND club_id = %d
             ORDER BY sort_order, name",
            'eval_type', CurrentClub::id()
        ) );
        $current = (int) ( $state['eval_type_id'] ?? 0 );

        echo '<p>' . esc_html__( 'Pick the evaluation type. The form on the next page will match.', 'talenttrack' ) . '</p>';
        echo '<label><span>' . esc_html__( 'Evaluation type', 'talenttrack' ) . '</span><select name="eval_type_id" required>';
        echo '<option value="">' . esc_html__( '— pick a type —', 'talenttrack' ) . '</option>';
        foreach ( $types as $t ) {
            echo '<option value="' . esc_attr( (string) $t->id ) . '" ' . selected( $current, (int) $t->id, false ) . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'Evaluation date', 'talenttrack' ) . '</span><input type="date" name="eval_date" value="' . esc_attr( (string) ( $state['eval_date'] ?? gmdate( 'Y-m-d' ) ) ) . '" required></label>';
    }

    public function validate( array $post, array $state ) {
        $type = isset( $post['eval_type_id'] ) ? absint( $post['eval_type_id'] ) : 0;
        if ( $type <= 0 ) return new \WP_Error( 'no_type', __( 'Please pick an evaluation type.', 'talenttrack' ) );
        $date = isset( $post['eval_date'] ) ? sanitize_text_field( wp_unslash( (string) $post['eval_date'] ) ) : gmdate( 'Y-m-d' );
        return [ 'eval_type_id' => $type, 'eval_date' => $date ];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $url = add_query_arg( [
            'tt_view'      => 'evaluations',
            'action'       => 'new',
            'player_id'    => (int) ( $state['player_id'] ?? 0 ),
            'eval_type_id' => (int) ( $state['eval_type_id'] ?? 0 ),
            'eval_date'    => (string) ( $state['eval_date'] ?? gmdate( 'Y-m-d' ) ),
        ], home_url( '/' ) );
        return [ 'redirect_url' => $url ];
    }
}
