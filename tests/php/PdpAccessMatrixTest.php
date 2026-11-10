<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\AuthorizationModule;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Pdp\PdpAccess;

/**
 * #4988 — academy-wide PDP access follows `pdp_file` at global scope in the
 * authorization matrix, and verdict authority follows `pdp_verdict` change
 * at global scope, not two persona names. A seat an academy grants the row
 * gets the same answer as the head of development; the head of development
 * and the academy admin keep their access with the `user_has_cap` bridge
 * on and off; a coach is still no verdict authority.
 */
final class PdpAccessMatrixTest extends WP_UnitTestCase {

    private const BRIDGE = [ AuthorizationModule::class, 'filterUserHasCap' ];

    private int $player = 0;

    /** @var int|false */
    private $bridge_priority = false;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        $this->bridge_priority = has_filter( 'user_has_cap', self::BRIDGE );

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'JO17-2' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $club, 'team_id' => $team, 'first_name' => 'Sem', 'last_name' => 'Kok', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        remove_filter( 'user_has_cap', self::BRIDGE, 10 );
        if ( $this->bridge_priority !== false ) {
            add_filter( 'user_has_cap', self::BRIDGE, (int) $this->bridge_priority, 4 );
        }
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_a_seat_granted_global_pdp_read_reads_every_file(): void {
        // A team manager holds no team here, and the seed gives the seat
        // `pdp_file` read on its own teams only.
        $manager = (int) self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );
        $this->assertFalse( PdpAccess::hasGlobalPdpAccess( $manager, MatrixGate::READ ), 'precondition: no global read' );
        $this->assertFalse( PdpAccess::canSeeFile( $manager, $this->player ), 'precondition: the file of a player on another team is closed' );

        ( new MatrixRepository() )->setRow( 'team_manager', 'pdp_file', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL, '' );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->assertTrue( PdpAccess::hasGlobalPdpAccess( $manager, MatrixGate::READ ), 'the grant is global read' );
        $this->assertTrue( PdpAccess::canSeeFile( $manager, $this->player ), 'with it, the seat reads the file, as the head of development does' );
        $this->assertFalse( PdpAccess::canEditFile( $manager, $this->player ), 'read is not change' );
    }

    public function test_head_of_development_and_academy_admin_keep_their_access_with_the_bridge_on_and_off(): void {
        $hod   = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $admin = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );

        foreach ( [ 'bridge as installed' => false, 'matrix dormant' => true ] as $label => $dormant ) {
            if ( $dormant ) remove_filter( 'user_has_cap', self::BRIDGE, 10 );
            AuthorizationService::flushCache();
            foreach ( [ 'head of development' => $hod, 'academy admin' => $admin ] as $who => $uid ) {
                $this->assertTrue( PdpAccess::canSeeFile( $uid, $this->player ), "{$label}: {$who} reads the file" );
                $this->assertTrue( PdpAccess::canEditFile( $uid, $this->player ), "{$label}: {$who} edits the file" );
                $this->assertNull( PdpAccess::coverageScopePlayerIds( $uid ), "{$label}: {$who} counts every player" );
                $this->assertTrue( PdpAccess::isGlobalVerdictAuthority( $uid ), "{$label}: {$who} signs as head of academy" );
            }
        }
    }

    public function test_a_coach_is_no_global_verdict_authority(): void {
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->assertFalse( PdpAccess::isGlobalVerdictAuthority( $coach ) );
        $this->assertFalse( PdpAccess::hasGlobalPdpAccess( $coach, MatrixGate::READ ) );
    }

    public function test_no_persona_name_decides_pdp_access(): void {
        $code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Modules/Pdp/PdpAccess.php' );
        foreach ( [ 'PersonaResolver', "'head_of_development'", "'academy_admin'" ] as $name ) {
            $this->assertStringNotContainsString( $name, $code, "PdpAccess decides by capability and matrix, not by {$name}" );
        }
    }
}
