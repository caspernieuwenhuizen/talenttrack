<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\AuthorizationModule;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * `tt_manage_players` belongs to the head of development, the club admin
 * and the administrator, with the matrix bridge on and with it off.
 *
 * It gates season rollover, player login accounts, custom-field
 * definitions and player deletion. #3232 took it off `tt_staff` (physio,
 * kit manager), but `CapabilityAliases` rebuilt it from that role's
 * `tt_view_players` + `tt_edit_players` whenever the matrix bridge was
 * not hooked. The alias row is gone; the roles meant to hold the cap
 * hold it outright.
 */
final class ManagePlayersCapabilityTest extends WP_UnitTestCase {

    private const BRIDGE = [ AuthorizationModule::class, 'filterUserHasCap' ];

    /** @var int|false */
    private $bridge_priority = false;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        $this->bridge_priority = has_filter( 'user_has_cap', self::BRIDGE );
    }

    public function tear_down(): void {
        // Leave the bridge exactly as the suite had it.
        remove_filter( 'user_has_cap', self::BRIDGE, 10 );
        if ( $this->bridge_priority !== false ) {
            add_filter( 'user_has_cap', self::BRIDGE, (int) $this->bridge_priority, 4 );
        }
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    private function matrixOff(): void {
        remove_filter( 'user_has_cap', self::BRIDGE, 10 );
        $this->assertFalse( has_filter( 'user_has_cap', self::BRIDGE ), 'the bridge is unhooked, as on a matrix-inactive install' );
    }

    private function matrixOn(): void {
        if ( has_filter( 'user_has_cap', self::BRIDGE ) === false ) {
            add_filter( 'user_has_cap', self::BRIDGE, 10, 4 );
        }
    }

    public function test_staff_holds_view_and_edit_players_so_the_case_is_real(): void {
        $staff = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $this->matrixOff();

        $this->assertTrue( user_can( $staff, 'tt_view_players' ) );
        $this->assertTrue( user_can( $staff, 'tt_edit_players' ) );
    }

    public function test_staff_does_not_hold_manage_players_with_the_matrix_off(): void {
        $staff = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $this->matrixOff();

        $this->assertFalse( user_can( $staff, 'tt_manage_players' ) );
    }

    public function test_staff_does_not_hold_manage_players_with_the_matrix_on(): void {
        $staff = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $this->matrixOn();

        $this->assertFalse( user_can( $staff, 'tt_manage_players' ) );
    }

    public function test_the_roles_meant_to_hold_it_still_do_with_the_matrix_off(): void {
        $this->matrixOff();
        foreach ( [ 'tt_head_dev', 'tt_club_admin', 'administrator' ] as $role ) {
            $uid = self::factory()->user->create( [ 'role' => $role ] );
            $this->assertTrue( user_can( $uid, 'tt_manage_players' ), $role );
        }
    }
}
