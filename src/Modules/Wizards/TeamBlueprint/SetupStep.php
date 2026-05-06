<?php
namespace TT\Modules\Wizards\TeamBlueprint;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\TeamDevelopment\Repositories\TeamBlueprintsRepository;
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
        $current_flavour  = (string) ( $state['flavour'] ?? TeamBlueprintsRepository::FLAVOUR_MATCH_DAY );

        echo '<label><span>' . esc_html__( 'Team', 'talenttrack' ) . ' *</span><select name="team_id" required>';
        echo '<option value="0">' . esc_html__( '— pick a team —', 'talenttrack' ) . '</option>';
        foreach ( (array) $teams as $t ) {
            echo '<option value="' . (int) $t->id . '" ' . selected( $current_team, (int) $t->id, false ) . '>'
                . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<fieldset class="tt-bp-flavour-fieldset" style="margin: 8px 0;"><legend>' . esc_html__( 'Type *', 'talenttrack' ) . '</legend>';
        echo '<label style="display:block; margin-bottom:6px;"><input type="radio" name="flavour" value="' . esc_attr( TeamBlueprintsRepository::FLAVOUR_MATCH_DAY ) . '" '
            . checked( $current_flavour, TeamBlueprintsRepository::FLAVOUR_MATCH_DAY, false ) . '> '
            . '<strong>' . esc_html__( 'Match-day lineup', 'talenttrack' ) . '</strong> — '
            . esc_html__( 'one starting XI for an upcoming match. Single player per slot.', 'talenttrack' )
            . '</label>';
        echo '<label style="display:block;"><input type="radio" name="flavour" value="' . esc_attr( TeamBlueprintsRepository::FLAVOUR_SQUAD_PLAN ) . '" '
            . checked( $current_flavour, TeamBlueprintsRepository::FLAVOUR_SQUAD_PLAN, false ) . '> '
            . '<strong>' . esc_html__( 'Squad plan', 'talenttrack' ) . '</strong> — '
            . esc_html__( 'planning towards next season or trial decisions. Three tiers per slot (primary / secondary / tertiary) and trial overlay.', 'talenttrack' )
            . '</label>';
        echo '</fieldset>';

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
        $flavour     = isset( $post['flavour'] ) ? sanitize_key( (string) $post['flavour'] ) : TeamBlueprintsRepository::FLAVOUR_MATCH_DAY;
        if ( ! in_array( $flavour, [ TeamBlueprintsRepository::FLAVOUR_MATCH_DAY, TeamBlueprintsRepository::FLAVOUR_SQUAD_PLAN ], true ) ) {
            $flavour = TeamBlueprintsRepository::FLAVOUR_MATCH_DAY;
        }
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
            'flavour'               => $flavour,
        ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
}
