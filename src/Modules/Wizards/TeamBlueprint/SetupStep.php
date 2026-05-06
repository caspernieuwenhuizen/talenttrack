<?php
namespace TT\Modules\Wizards\TeamBlueprint;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — Setup. Pick a team (pre-filled from querystring when the
 * coach arrives via the Team Blueprint list), pick a formation
 * template, name the blueprint.
 */
final class SetupStep implements WizardStepInterface {

    public function slug(): string { return 'setup'; }
    public function label(): string { return __( 'Setup', 'talenttrack' ); }

    public function render( array $state ): void {
        global $wpdb; $p = $wpdb->prefix;

        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$p}tt_teams WHERE club_id = %d ORDER BY name ASC LIMIT 200",
            CurrentClub::id()
        ) );
        $templates = $wpdb->get_results(
            "SELECT id, name, formation_shape FROM {$p}tt_formation_templates
              WHERE archived_at IS NULL ORDER BY formation_shape ASC, name ASC"
        );

        $current_team     = (int) ( $state['team_id'] ?? ( isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0 ) );
        $current_template = (int) ( $state['formation_template_id'] ?? 0 );
        $current_name     = (string) ( $state['name'] ?? '' );

        echo '<label><span>' . esc_html__( 'Team', 'talenttrack' ) . ' *</span><select name="team_id" required>';
        echo '<option value="0">' . esc_html__( '— pick a team —', 'talenttrack' ) . '</option>';
        foreach ( (array) $teams as $t ) {
            echo '<option value="' . (int) $t->id . '" ' . selected( $current_team, (int) $t->id, false ) . '>'
                . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'Formation', 'talenttrack' ) . ' *</span><select name="formation_template_id" required>';
        echo '<option value="0">' . esc_html__( '— pick a formation —', 'talenttrack' ) . '</option>';
        foreach ( (array) $templates as $tpl ) {
            echo '<option value="' . (int) $tpl->id . '" ' . selected( $current_template, (int) $tpl->id, false ) . '>'
                . esc_html( (string) $tpl->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'Blueprint name', 'talenttrack' ) . ' *</span><input type="text" name="name" required maxlength="120" placeholder="' . esc_attr__( 'e.g. Cup final starting XI', 'talenttrack' ) . '" value="' . esc_attr( $current_name ) . '"></label>';
    }

    public function validate( array $post, array $state ) {
        $team_id     = isset( $post['team_id'] ) ? absint( $post['team_id'] ) : 0;
        $template_id = isset( $post['formation_template_id'] ) ? absint( $post['formation_template_id'] ) : 0;
        $name        = isset( $post['name'] ) ? trim( sanitize_text_field( wp_unslash( (string) $post['name'] ) ) ) : '';
        if ( $team_id <= 0 ) {
            return new \WP_Error( 'bad_team', __( 'Pick a team for this blueprint.', 'talenttrack' ) );
        }
        if ( $template_id <= 0 ) {
            return new \WP_Error( 'bad_template', __( 'Pick a formation for this blueprint.', 'talenttrack' ) );
        }
        if ( $name === '' ) {
            return new \WP_Error( 'bad_name', __( 'Give this blueprint a name.', 'talenttrack' ) );
        }
        return [
            'team_id'               => $team_id,
            'formation_template_id' => $template_id,
            'name'                  => $name,
        ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
}
