<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;

/**
 * #4683 — `MatrixGate::canAnyScope()` answers once per request.
 *
 * Every `user_can( $uid, 'tt_…' )` goes through it, at about six queries
 * a call, so a list checking a capability per row paid that per row (the
 * report list: 87 queries for 2 reports, 225 for 200). The answer is kept
 * per user, entity and activity until something it rests on changes.
 */
final class MatrixGateAnyScopeMemoTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_repeated_check_runs_the_matrix_queries_once(): void {
        global $wpdb;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $before = $wpdb->num_queries;
        $first  = MatrixGate::canAnyScope( $coach, 'evaluations', MatrixGate::READ );
        $this->assertGreaterThan( 0, $wpdb->num_queries - $before, 'precondition: the first answer reads the database' );

        $before = $wpdb->num_queries;
        for ( $i = 0; $i < 10; $i++ ) {
            $this->assertSame( $first, MatrixGate::canAnyScope( $coach, 'evaluations', MatrixGate::READ ) );
        }
        $this->assertSame( 0, $wpdb->num_queries - $before, 'ten more checks, no query' );
    }

    public function test_repeated_capability_checks_cost_no_more_than_one(): void {
        global $wpdb;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        user_can( $coach, 'tt_view_evaluations' );
        $before = $wpdb->num_queries;
        for ( $i = 0; $i < 20; $i++ ) {
            user_can( $coach, 'tt_view_evaluations' );
        }
        $this->assertSame( 0, $wpdb->num_queries - $before, 'twenty more user_can() calls, no query' );
    }

    public function test_a_grant_made_in_the_request_is_seen_after_clear_cache(): void {
        global $wpdb;
        $player = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->assertFalse( MatrixGate::canAnyScope( $player, 'tournaments', MatrixGate::CREATE_DELETE ), 'precondition: a player plans no tournament' );

        $module = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT module_class FROM {$wpdb->prefix}tt_authorization_matrix WHERE entity = %s LIMIT 1",
            'tournaments'
        ) );
        // A raw write, as a test fixture or an import makes it: the cached
        // answer stands until clearCache().
        $wpdb->insert( "{$wpdb->prefix}tt_authorization_matrix", [
            'persona' => 'player', 'entity' => 'tournaments', 'activity' => MatrixGate::CREATE_DELETE,
            'scope_kind' => MatrixGate::SCOPE_GLOBAL, 'module_class' => $module, 'is_default' => 0,
        ] );
        $this->assertFalse( MatrixGate::canAnyScope( $player, 'tournaments', MatrixGate::CREATE_DELETE ), 'still the answer of this request' );
        MatrixRepository::clearCache();
        $this->assertTrue( MatrixGate::canAnyScope( $player, 'tournaments', MatrixGate::CREATE_DELETE ), 'the grant, after clearCache()' );
    }

    public function test_a_role_change_is_seen_at_once(): void {
        $user = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->assertFalse( MatrixGate::canAnyScope( $user, 'tournaments', MatrixGate::CREATE_DELETE ) );

        ( new \WP_User( $user ) )->set_role( 'tt_head_dev' );
        $this->assertTrue( MatrixGate::canAnyScope( $user, 'tournaments', MatrixGate::CREATE_DELETE ), 'set_user_role clears the answers' );
    }

    public function test_a_role_grant_flushes_through_the_existing_hook(): void {
        $user = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->assertFalse( MatrixGate::canAnyScope( $user, 'tournaments', MatrixGate::CREATE_DELETE ) );

        global $wpdb;
        $module = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT module_class FROM {$wpdb->prefix}tt_authorization_matrix WHERE entity = %s LIMIT 1",
            'tournaments'
        ) );
        ( new MatrixRepository() )->setRow( 'player', 'tournaments', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_GLOBAL, $module );
        do_action( 'tt_role_granted' );

        $this->assertTrue( MatrixGate::canAnyScope( $user, 'tournaments', MatrixGate::CREATE_DELETE ) );
    }
}
