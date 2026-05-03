<?php
namespace TT\Modules\Wizards\Team;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

final class BasicsStep implements WizardStepInterface {

    public function slug(): string { return 'basics'; }
    public function label(): string { return __( 'Basics', 'talenttrack' ); }

    public function render( array $state ): void {
        global $wpdb;
        // tt_lookups uses HARD delete (handle_delete_lookup runs $wpdb->delete).
        // The previous query carried an `AND archived_at IS NULL` clause but
        // tt_lookups has no archived_at column — neither migration 0001 (the
        // table create) nor 0010 (the archive-support migration that added
        // the column to tt_players / tt_teams / etc.) provisioned it on
        // tt_lookups. The bad WHERE made the entire query fail with
        // "Unknown column 'archived_at'" so the dropdown rendered empty for
        // every install since the wizard shipped.
        $age_groups = $wpdb->get_results( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_lookups WHERE lookup_type = %s AND club_id = %d ORDER BY sort_order, name",
            'age_group', CurrentClub::id()
        ) );

        echo '<label><span>' . esc_html__( 'Team name', 'talenttrack' ) . ' *</span><input type="text" name="name" required value="' . esc_attr( (string) ( $state['name'] ?? '' ) ) . '"></label>';

        echo '<label><span>' . esc_html__( 'Age group', 'talenttrack' ) . '</span><select name="age_group">';
        echo '<option value="">' . esc_html__( '— pick an age group —', 'talenttrack' ) . '</option>';
        $current = (string) ( $state['age_group'] ?? '' );
        foreach ( $age_groups as $g ) {
            $name = (string) $g->name;
            echo '<option value="' . esc_attr( $name ) . '" ' . selected( $current, $name, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'Notes', 'talenttrack' ) . '</span><textarea name="notes" rows="3">' . esc_textarea( (string) ( $state['notes'] ?? '' ) ) . '</textarea></label>';
    }

    public function validate( array $post, array $state ) {
        $name = isset( $post['name'] ) ? sanitize_text_field( wp_unslash( (string) $post['name'] ) ) : '';
        if ( $name === '' ) return new \WP_Error( 'name_required', __( 'Team name is required.', 'talenttrack' ) );
        return [
            'name'      => $name,
            'age_group' => isset( $post['age_group'] ) ? sanitize_text_field( wp_unslash( (string) $post['age_group'] ) ) : '',
            'notes'     => isset( $post['notes'] )     ? sanitize_textarea_field( wp_unslash( (string) $post['notes'] ) )  : '',
        ];
    }

    public function nextStep( array $state ): ?string { return 'staff'; }
    public function submit( array $state ) { return null; }
}
