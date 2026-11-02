<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Wizards\Team\StaffStep;

/**
 * #4761 — the new-team wizard's staff step accepts only an account its
 * staff picker offers. A forged player or parent id is refused; an offered
 * coach and an empty slot pass.
 */
final class TeamWizardStaffStepValidateTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
    }

    public function test_an_offered_coach_and_empty_slots_pass(): void {
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $result = ( new StaffStep() )->validate( [ 'staff_head_coach' => (string) $coach ], [] );

        $this->assertIsArray( $result );
        $this->assertSame( $coach, $result['staff_head_coach'] );
        $this->assertSame( 0, $result['staff_physio'] );
    }

    /** @dataProvider nonStaffRoles */
    public function test_a_forged_non_staff_id_is_refused( string $role ): void {
        $account = self::factory()->user->create( [ 'role' => $role ] );

        $result = ( new StaffStep() )->validate( [ 'staff_assistant_coach' => (string) $account ], [] );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'bad_staff_assistant_coach', $result->get_error_code() );
        $this->assertStringContainsString( 'Choose a staff member from the list.', $result->get_error_message() );
    }

    public function test_an_unknown_account_id_is_refused(): void {
        $result = ( new StaffStep() )->validate( [ 'staff_physio' => '999999' ], [] );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /** @return array<string, array{string}> */
    public function nonStaffRoles(): array {
        return [ 'player' => [ 'tt_player' ], 'parent' => [ 'tt_parent' ] ];
    }
}
