<?php
namespace TT\Modules\Wizards\Goal;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — link a methodology entity. Polymorphic by `link_type`:
 * principle / football_action / position / value (or skip = no link).
 *
 * Picking a type changes the candidate list for the second select.
 * The framework re-renders on each step submission so we don't need
 * client-side JS for the cascade — the user picks a type, hits Next,
 * the step re-renders showing the candidates of that type, the user
 * picks one and hits Next again.
 */
final class LinkStep implements WizardStepInterface {

    public function slug(): string { return 'link'; }
    public function label(): string { return __( 'Methodology link', 'talenttrack' ); }

    public function render( array $state ): void {
        $type = (string) ( $state['link_type'] ?? '' );
        echo '<p>' . esc_html__( 'Optionally link this goal to a methodology entity. Skip to leave the goal unlinked.', 'talenttrack' ) . '</p>';

        echo '<label><span>' . esc_html__( 'Link type', 'talenttrack' ) . '</span><select name="link_type" onchange="this.form.querySelector(\'[name=tt_wizard_action][value=next]\').click()">';
        echo '<option value="">' . esc_html__( '— no link —', 'talenttrack' ) . '</option>';
        foreach ( self::types() as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $type, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        if ( $type === '' ) return;

        $candidates = self::candidates( $type );
        $current_id = (int) ( $state['link_id'] ?? 0 );
        echo '<label><span>' . esc_html__( 'Pick the entity to link', 'talenttrack' ) . '</span><select name="link_id">';
        echo '<option value="0">' . esc_html__( '— pick one —', 'talenttrack' ) . '</option>';
        foreach ( $candidates as $row ) {
            echo '<option value="' . esc_attr( (string) $row['id'] ) . '" ' . selected( $current_id, (int) $row['id'], false ) . '>' . esc_html( (string) $row['label'] ) . '</option>';
        }
        echo '</select></label>';
    }

    public function validate( array $post, array $state ) {
        $type = isset( $post['link_type'] ) ? sanitize_key( (string) $post['link_type'] ) : '';
        $id   = isset( $post['link_id'] ) ? absint( $post['link_id'] ) : 0;
        if ( ! in_array( $type, array_keys( self::types() ), true ) ) $type = '';
        return [ 'link_type' => $type, 'link_id' => $id ];
    }

    public function nextStep( array $state ): ?string { return 'details'; }
    public function submit( array $state ) { return null; }

    /** @return array<string,string> */
    private static function types(): array {
        return [
            'principle'       => __( 'Principle', 'talenttrack' ),
            'football_action' => __( 'Football action', 'talenttrack' ),
            'position'        => __( 'Position', 'talenttrack' ),
            'value'           => __( 'Value', 'talenttrack' ),
        ];
    }

    /**
     * @return array<int,array{id:int,label:string}>
     */
    private static function candidates( string $type ): array {
        global $wpdb;
        $rows = [];
        switch ( $type ) {
            case 'principle':
                $rows = $wpdb->get_results(
                    "SELECT id, CONCAT(code, ' — ', name) AS label FROM {$wpdb->prefix}tt_principles
                     WHERE archived_at IS NULL ORDER BY code"
                );
                break;
            case 'football_action':
                $rows = $wpdb->get_results(
                    "SELECT id, name AS label FROM {$wpdb->prefix}tt_football_actions
                     WHERE archived_at IS NULL ORDER BY sort_order, name"
                );
                break;
            case 'position':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, name AS label FROM {$wpdb->prefix}tt_lookups
                     WHERE lookup_type = %s AND archived_at IS NULL ORDER BY sort_order, name",
                    'position'
                ) );
                break;
            case 'value':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, name AS label FROM {$wpdb->prefix}tt_lookups
                     WHERE lookup_type = %s AND archived_at IS NULL ORDER BY sort_order, name",
                    'club_value'
                ) );
                break;
        }
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [ 'id' => (int) $r->id, 'label' => (string) $r->label ];
        }
        return $out;
    }
}
