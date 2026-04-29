<?php
namespace TT\Modules\Wizards\Activity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — Pick activity type + status.
 *
 * Type drives the conditional fields:
 *   - `game` → subtype (Friendly / League / Cup) on next step
 *   - `other` → free-text label on next step
 * Status defaults to `planned`. The user-facing dropdown skips rows
 * flagged `meta.hidden_from_form = 1` (the `draft` value).
 */
final class TypeStatusStep implements WizardStepInterface {

    public function slug(): string { return 'type'; }
    public function label(): string { return __( 'Type', 'talenttrack' ); }

    public function render( array $state ): void {
        $type_rows   = QueryHelpers::get_lookups( 'activity_type' );
        $status_rows = QueryHelpers::get_lookups( 'activity_status' );

        $current_type   = (string) ( $state['activity_type_key'] ?? 'training' );
        $current_status = (string) ( $state['activity_status_key'] ?? 'planned' );

        echo '<label><span>' . esc_html__( 'Activity type', 'talenttrack' ) . ' *</span><select name="activity_type_key" required>';
        foreach ( $type_rows as $row ) {
            $name  = (string) $row->name;
            $label = LookupTranslator::name( $row );
            echo '<option value="' . esc_attr( $name ) . '" ' . selected( $current_type, $name, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'Status', 'talenttrack' ) . '</span><select name="activity_status_key">';
        foreach ( $status_rows as $row ) {
            $name = (string) $row->name;
            // Hide internal-only statuses (e.g. `draft`) unless the
            // wizard already has that value in its state.
            $meta   = is_string( $row->meta ?? null ) ? json_decode( (string) $row->meta, true ) : null;
            $hidden = is_array( $meta ) && ! empty( $meta['hidden_from_form'] );
            if ( $hidden && $current_status !== $name ) continue;
            $label = LookupTranslator::name( $row );
            echo '<option value="' . esc_attr( $name ) . '" ' . selected( $current_status, $name, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        echo '<p style="color:#5b6e75;font-size:13px;margin:8px 0 0;">' . esc_html__( 'Status defaults to "Planned". Flip to "Completed" once the activity has happened — that\'s when the attendance editor unlocks on the activity page.', 'talenttrack' ) . '</p>';
    }

    public function validate( array $post, array $state ) {
        $type   = isset( $post['activity_type_key'] )   ? sanitize_text_field( wp_unslash( (string) $post['activity_type_key'] ) )   : 'training';
        $status = isset( $post['activity_status_key'] ) ? sanitize_text_field( wp_unslash( (string) $post['activity_status_key'] ) ) : 'planned';

        $valid_types    = QueryHelpers::get_lookup_names( 'activity_type' );
        $valid_statuses = QueryHelpers::get_lookup_names( 'activity_status' );
        if ( ! in_array( $type,   $valid_types,    true ) ) $type   = 'training';
        if ( ! in_array( $status, $valid_statuses, true ) ) $status = 'planned';

        return [
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
        ];
    }

    public function nextStep( array $state ): ?string { return 'details'; }
    public function submit( array $state ) { return null; }
}
