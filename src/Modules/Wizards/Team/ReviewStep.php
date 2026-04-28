<?php
namespace TT\Modules\Wizards\Team;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\FunctionalRolesRepository;
use TT\Shared\Wizards\WizardStepInterface;

final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        echo '<p>' . esc_html__( 'Check the team details before creating it.', 'talenttrack' ) . '</p>';
        echo '<dl class="tt-wizard-review">';
        echo '<dt>' . esc_html__( 'Team name', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) ( $state['name'] ?? '' ) ) . '</dd>';
        echo '<dt>' . esc_html__( 'Age group', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) ( $state['age_group'] ?? '—' ) ) . '</dd>';
        foreach ( StaffStep::SLOTS as $key => $label ) {
            $uid = (int) ( $state[ 'staff_' . $key ] ?? 0 );
            $name = '—';
            if ( $uid > 0 ) {
                $u = get_userdata( $uid );
                if ( $u ) $name = (string) $u->display_name;
            }
            echo '<dt>' . esc_html__( $label, 'talenttrack' ) . '</dt><dd>' . esc_html( $name ) . '</dd>';
        }
        echo '</dl>';
    }

    public function validate( array $post, array $state ) { return []; }
    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        global $wpdb;
        $name = (string) ( $state['name'] ?? '' );
        if ( $name === '' ) return new \WP_Error( 'name_required', __( 'Team name is required.', 'talenttrack' ) );

        $head_coach_id = (int) ( $state['staff_head_coach'] ?? 0 );

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'name'           => $name,
            'age_group'      => (string) ( $state['age_group'] ?? '' ),
            'head_coach_id'  => $head_coach_id,
            'notes'          => (string) ( $state['notes'] ?? '' ),
        ] );
        if ( ! $ok ) return new \WP_Error( 'db_error', __( 'Could not create the team.', 'talenttrack' ) );
        $team_id = (int) $wpdb->insert_id;

        // Map staff slots to tt_team_people via functional roles.
        $repo = new FunctionalRolesRepository();
        $slot_to_role_key = [
            'head_coach'      => 'head_coach',
            'assistant_coach' => 'assistant_coach',
            'team_manager'    => 'team_manager',
            'physio'          => 'physio',
        ];
        foreach ( $slot_to_role_key as $slot => $role_key ) {
            $uid = (int) ( $state[ 'staff_' . $slot ] ?? 0 );
            if ( $uid <= 0 ) continue;
            $role = $repo->findRoleByKey( $role_key );
            if ( ! $role ) continue;
            // Resolve the person id from the wp user id, or insert a minimal People row.
            $person_id = self::resolvePersonId( $uid );
            if ( $person_id <= 0 ) continue;
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tt_team_people
                  WHERE team_id = %d AND person_id = %d AND functional_role_id = %d LIMIT 1",
                $team_id, $person_id, (int) $role->id
            ) );
            if ( $exists ) continue;
            $wpdb->insert( $wpdb->prefix . 'tt_team_people', [
                'team_id'             => $team_id,
                'person_id'           => $person_id,
                'functional_role_id'  => (int) $role->id,
            ] );
        }

        return [ 'redirect_url' => add_query_arg( [ 'tt_view' => 'teams', 'id' => $team_id ], home_url( '/' ) ) ];
    }

    /**
     * Find or create a tt_people row for a wp user id. Returns the
     * person id, or 0 if neither lookup nor creation works.
     */
    private static function resolvePersonId( int $wp_user_id ): int {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d LIMIT 1",
            $wp_user_id
        ) );
        if ( $existing > 0 ) return $existing;

        $user = get_userdata( $wp_user_id );
        if ( ! $user ) return 0;
        $first = (string) $user->first_name ?: (string) $user->display_name;
        $last  = (string) $user->last_name ?: '';
        $ok = $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => (string) $user->user_email,
            'wp_user_id' => $wp_user_id,
            'role_type'  => 'staff',
        ] );
        return $ok ? (int) $wpdb->insert_id : 0;
    }
}
