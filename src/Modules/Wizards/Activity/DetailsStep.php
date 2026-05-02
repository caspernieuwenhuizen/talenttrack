<?php
namespace TT\Modules\Wizards\Activity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — Date, title, location, notes.
 *
 * Conditional rows light up based on the type picked in step 2:
 *   - `game`  → game_subtype select (Friendly / League / Cup / …)
 *   - `other` → free-text label (required so the activity has a name)
 *
 * The flat-form path uses the same conditional-show JS; here we render
 * server-side because we already know the type from wizard state.
 */
final class DetailsStep implements WizardStepInterface {

    public function slug(): string { return 'details'; }
    public function label(): string { return __( 'Details', 'talenttrack' ); }

    public function render( array $state ): void {
        $type     = (string) ( $state['activity_type_key'] ?? 'training' );
        $title    = (string) ( $state['title'] ?? '' );
        $date     = (string) ( $state['session_date'] ?? current_time( 'Y-m-d' ) );
        $location = (string) ( $state['location'] ?? '' );
        $notes    = (string) ( $state['notes'] ?? '' );
        $subtype  = (string) ( $state['game_subtype_key'] ?? '' );
        $other    = (string) ( $state['other_label'] ?? '' );

        echo '<label><span>' . esc_html__( 'Date', 'talenttrack' ) . ' *</span><input type="date" name="session_date" required value="' . esc_attr( $date ) . '" /></label>';

        echo '<label><span>' . esc_html__( 'Title', 'talenttrack' ) . ' *</span><input type="text" name="title" required maxlength="200" value="' . esc_attr( $title ) . '" /></label>';

        if ( $type === 'game' ) {
            $subtype_rows = QueryHelpers::get_lookups( 'game_subtype' );
            echo '<label><span>' . esc_html__( 'Game subtype', 'talenttrack' ) . '</span><select name="game_subtype_key">';
            echo '<option value="">' . esc_html__( '— Choose —', 'talenttrack' ) . '</option>';
            foreach ( $subtype_rows as $row ) {
                $name = (string) ( $row->name ?? '' );
                if ( $name === '' ) continue;
                $label = \TT\Infrastructure\Query\LookupTranslator::name( $row );
                echo '<option value="' . esc_attr( $name ) . '" ' . selected( $subtype, $name, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select></label>';
        }

        if ( $type === 'other' ) {
            echo '<label><span>' . esc_html__( 'Other label', 'talenttrack' ) . ' *</span><input type="text" name="other_label" required maxlength="120" value="' . esc_attr( $other ) . '" placeholder="' . esc_attr__( 'e.g. Team-building day', 'talenttrack' ) . '" /></label>';
        }

        echo '<label><span>' . esc_html__( 'Location', 'talenttrack' ) . '</span><input type="text" name="location" maxlength="200" value="' . esc_attr( $location ) . '" /></label>';

        echo '<label><span>' . esc_html__( 'Notes', 'talenttrack' ) . '</span><textarea name="notes" rows="3">' . esc_textarea( $notes ) . '</textarea></label>';
    }

    public function validate( array $post, array $state ) {
        $type  = (string) ( $state['activity_type_key'] ?? 'training' );
        $title = isset( $post['title'] )        ? sanitize_text_field( wp_unslash( (string) $post['title'] ) )        : '';
        $date  = isset( $post['session_date'] ) ? sanitize_text_field( wp_unslash( (string) $post['session_date'] ) ) : '';

        if ( $title === '' ) return new \WP_Error( 'no_title', __( 'Please give the activity a title.', 'talenttrack' ) );
        if ( $date === '' || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return new \WP_Error( 'bad_date', __( 'Please pick a valid date.', 'talenttrack' ) );
        }

        $location = isset( $post['location'] ) ? sanitize_text_field( wp_unslash( (string) $post['location'] ) ) : '';
        $notes    = isset( $post['notes'] )    ? sanitize_textarea_field( wp_unslash( (string) $post['notes'] ) ) : '';

        $out = [
            'title'        => $title,
            'session_date' => $date,
            'location'     => $location,
            'notes'        => $notes,
        ];

        if ( $type === 'game' ) {
            $sub      = isset( $post['game_subtype_key'] ) ? sanitize_text_field( wp_unslash( (string) $post['game_subtype_key'] ) ) : '';
            $valid    = QueryHelpers::get_lookup_names( 'game_subtype' );
            $out['game_subtype_key'] = ( $sub !== '' && in_array( $sub, $valid, true ) ) ? $sub : null;
            $out['other_label']      = null;
        } elseif ( $type === 'other' ) {
            $other = isset( $post['other_label'] ) ? sanitize_text_field( wp_unslash( (string) $post['other_label'] ) ) : '';
            if ( $other === '' ) return new \WP_Error( 'no_other_label', __( 'Please describe what kind of activity this is.', 'talenttrack' ) );
            $out['other_label']      = $other;
            $out['game_subtype_key'] = null;
        } else {
            $out['game_subtype_key'] = null;
            $out['other_label']      = null;
        }

        return $out;
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
