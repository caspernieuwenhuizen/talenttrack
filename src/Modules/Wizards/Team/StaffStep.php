<?php
namespace TT\Modules\Wizards\Team;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\StaffPickerComponent;
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

    private static function slotLabel( string $key ): string {
        switch ( $key ) {
            case 'head_coach':      return __( 'Head coach', 'talenttrack' );
            case 'assistant_coach': return __( 'Assistant coach', 'talenttrack' );
            case 'team_manager':    return __( 'Team manager', 'talenttrack' );
            case 'physio':          return __( 'Physio', 'talenttrack' );
        }
        return $key;
    }

    public function render( array $state ): void {
        echo '<p>' . esc_html__( 'Assign staff to this team. Each slot is optional — you can fill it in later from the People page.', 'talenttrack' ) . '</p>';

        foreach ( array_keys( self::SLOTS ) as $key ) {
            $current = isset( $state[ 'staff_' . $key ] ) ? (int) $state[ 'staff_' . $key ] : 0;
            echo StaffPickerComponent::render( [
                'name'        => 'staff_' . $key,
                'label'       => self::slotLabel( $key ),
                'required'    => false,
                'selected'    => $current,
                'placeholder' => __( 'Type a name to search…', 'talenttrack' ),
            ] );
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
