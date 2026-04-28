<?php
namespace TT\Modules\Wizards\Team;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — Staff assignment.
 *
 * Four independent slots, each skippable: head coach, assistant
 * coach, team manager, physio. The review step turns these into
 * `tt_team_people` rows mapped to functional-role slugs.
 *
 * Why not Functional Roles directly: we want a simple mental model
 * for the wizard ("pick a head coach", "pick a physio"), not a
 * functional-role picker. The mapping happens at review time.
 */
final class StaffStep implements WizardStepInterface {

    public const SLOTS = [
        'head_coach'      => 'Head coach',
        'assistant_coach' => 'Assistant coach',
        'team_manager'    => 'Team manager',
        'physio'          => 'Physio',
    ];

    public function slug(): string { return 'staff'; }
    public function label(): string { return __( 'Staff', 'talenttrack' ); }

    public function render( array $state ): void {
        echo '<p>' . esc_html__( 'Assign staff to this team. Each slot is optional — you can fill it in later from the People page.', 'talenttrack' ) . '</p>';

        $candidates = get_users( [
            'role__in' => [ 'tt_coach', 'tt_head_dev', 'tt_club_admin', 'administrator' ],
            'fields'   => [ 'ID', 'display_name' ],
        ] );
        foreach ( self::SLOTS as $key => $label ) {
            $current = isset( $state[ 'staff_' . $key ] ) ? (int) $state[ 'staff_' . $key ] : 0;
            echo '<label><span>' . esc_html__( $label, 'talenttrack' ) . '</span><select name="staff_' . esc_attr( $key ) . '">';
            echo '<option value="0">' . esc_html__( '— none —', 'talenttrack' ) . '</option>';
            foreach ( $candidates as $u ) {
                echo '<option value="' . esc_attr( (string) $u->ID ) . '" ' . selected( $current, (int) $u->ID, false ) . '>' . esc_html( (string) $u->display_name ) . '</option>';
            }
            echo '</select></label>';
        }
    }

    public function validate( array $post, array $state ) {
        $patch = [];
        foreach ( array_keys( self::SLOTS ) as $key ) {
            $field = 'staff_' . $key;
            $patch[ $field ] = isset( $post[ $field ] ) ? absint( $post[ $field ] ) : 0;
        }
        return $patch;
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
