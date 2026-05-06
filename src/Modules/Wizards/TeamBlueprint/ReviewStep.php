<?php
namespace TT\Modules\Wizards\TeamBlueprint;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\Repositories\TeamBlueprintsRepository;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — Review and create. The blueprint lands in `draft` status
 * with an empty assignment map. Coach picks players via drag-drop on
 * the editor, which is where the user is redirected on submit.
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        echo '<p>' . esc_html__( 'Check the details before creating the blueprint. You will land on the editor next to drag players onto slots.', 'talenttrack' ) . '</p>';
        $flavour = (string) ( $state['flavour'] ?? TeamBlueprintsRepository::FLAVOUR_MATCH_DAY );
        $flavour_label = $flavour === TeamBlueprintsRepository::FLAVOUR_SQUAD_PLAN
            ? __( 'Squad plan (3 tiers per slot, trial overlay)', 'talenttrack' )
            : __( 'Match-day lineup (single starting XI)', 'talenttrack' );
        echo '<dl class="tt-wizard-review">';
        $rows = [
            __( 'Team',      'talenttrack' ) => self::teamName( (int) ( $state['team_id'] ?? 0 ) ),
            __( 'Type',      'talenttrack' ) => $flavour_label,
            __( 'Formation', 'talenttrack' ) => self::templateName( (int) ( $state['formation_template_id'] ?? 0 ) ),
            __( 'Name',      'talenttrack' ) => (string) ( $state['name'] ?? '' ),
        ];
        foreach ( $rows as $k => $v ) {
            echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( (string) $v ) . '</dd>';
        }
        echo '</dl>';
    }

    public function validate( array $post, array $state ) { return []; }
    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        if ( ! current_user_can( 'tt_manage_team_chemistry' ) ) {
            return new \WP_Error( 'denied', __( 'You do not have permission to create blueprints.', 'talenttrack' ) );
        }
        $team_id     = (int) ( $state['team_id']               ?? 0 );
        $template_id = (int) ( $state['formation_template_id'] ?? 0 );
        $name        = trim( (string) ( $state['name']         ?? '' ) );
        $flavour     = (string) ( $state['flavour'] ?? TeamBlueprintsRepository::FLAVOUR_MATCH_DAY );
        if ( $team_id <= 0 || $template_id <= 0 || $name === '' ) {
            return new \WP_Error( 'missing', __( 'Setup is incomplete. Go back to fill in all fields.', 'talenttrack' ) );
        }
        $id = ( new TeamBlueprintsRepository() )->create( $team_id, $name, $template_id, get_current_user_id(), $flavour );
        if ( $id <= 0 ) {
            return new \WP_Error( 'db_error', __( 'Could not create the blueprint.', 'talenttrack' ) );
        }
        return [ 'redirect_url' => add_query_arg(
            [ 'tt_view' => 'team-blueprints', 'id' => $id ],
            WizardEntryPoint::dashboardBaseUrl()
        ) ];
    }

    private static function teamName( int $id ): string {
        if ( $id <= 0 ) return '—';
        global $wpdb;
        return (string) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d", $id
        ) ) ?: ( '#' . $id ) );
    }

    private static function templateName( int $id ): string {
        if ( $id <= 0 ) return '—';
        global $wpdb;
        return (string) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_formation_templates WHERE id = %d", $id
        ) ) ?: ( '#' . $id ) );
    }
}
