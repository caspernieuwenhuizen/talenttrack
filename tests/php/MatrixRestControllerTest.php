<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixEditService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\Rest\MatrixRestController;
use TT\Shared\Frontend\FrontendMatrixView;

/**
 * #2654 — `/authorization/matrix` over REST.
 *
 * Authorization gets the attention, because this is the endpoint that
 * decides what everybody else in the academy can see. Three questions:
 * can the wrong person call it, can the right person escalate through it,
 * and does a partial payload wipe what it did not mention.
 */
final class MatrixRestControllerTest extends WP_UnitTestCase {

    private const ROUTE     = '/talenttrack/v1/authorization/matrix';
    private const ENTITY    = 'players';
    private const PROTECTED = 'authorization_matrix';
    private const PERSONA   = 'scout';
    private const AUTH_MODULE = 'TT\\Modules\\Authorization\\AuthorizationModule';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        FrontendMatrixView::ensureCapabilities();

        // Enough rows that the repository knows these tuples exist,
        // whether or not this install ever ran the matrix seed.
        $repo = new MatrixRepository();
        $repo->setRow( self::PERSONA, 'teams', 'read', 'global', 'TT\\Modules\\Teams\\TeamsModule' );
        $repo->setRow( 'head_coach', self::ENTITY, 'read', 'global', 'TT\\Modules\\Players\\PlayersModule' );
        $repo->setRow( 'head_coach', self::PROTECTED, 'read', 'global', self::AUTH_MODULE );
        // With the bridge active the capability resolves through the matrix
        // rather than the role, so the club admin needs the grant the
        // shipped seed gives them. See MatrixEditServiceTest for the why.
        $repo->setRow( 'academy_admin', self::PROTECTED, 'read', 'global', self::AUTH_MODULE );
        $repo->setRow( 'academy_admin', self::PROTECTED, 'change', 'global', self::AUTH_MODULE );
        $this->clearWorkingRows();

        // Routes must be registered on their own action, or WordPress
        // raises an incorrect-usage notice that fails the test.
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        $this->clearWorkingRows();

        $repo = new MatrixRepository();
        $repo->removeRow( self::PERSONA, 'teams', 'read', 'global' );
        $repo->removeRow( 'head_coach', self::ENTITY, 'read', 'global' );
        $repo->removeRow( 'head_coach', self::PROTECTED, 'read', 'global' );
        $repo->removeRow( 'academy_admin', self::PROTECTED, 'read', 'global' );
        $repo->removeRow( 'academy_admin', self::PROTECTED, 'change', 'global' );

        MatrixRepository::clearCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function clearWorkingRows(): void {
        $repo = new MatrixRepository();
        foreach ( [ self::ENTITY, self::PROTECTED ] as $entity ) {
            foreach ( MatrixEditService::ACTIVITIES as $activity ) {
                foreach ( MatrixEditService::SCOPE_KINDS as $scope ) {
                    $repo->removeRow( self::PERSONA, $entity, $activity, $scope );
                }
            }
        }
        MatrixRepository::clearCache();
    }

    private function asRole( string $role ): int {
        $uid = self::factory()->user->create( [ 'role' => $role ] );
        wp_set_current_user( $uid );

        return $uid;
    }

    /**
     * A club admin: holds the capability, not `manage_options`. Granted on
     * the user rather than left to the role — how the role acquires it is
     * production wiring with its own test in MatrixEditServiceTest, and a
     * 403 here should mean the guardrail refused, not that a role definition
     * did not survive a test-database rollback.
     */
    private function asClubAdmin(): int {
        $uid  = $this->asRole( 'tt_club_admin' );
        $user = new \WP_User( $uid );
        $user->add_cap( 'tt_manage_authorization' );

        $this->assertFalse( user_can( $uid, 'manage_options' ), 'precondition: a club admin is not an administrator' );

        return $uid;
    }

    /** @return \WP_REST_Response */
    private function call( string $method, string $route, array $body = [] ) {
        $request = new WP_REST_Request( $method, $route );
        if ( $body !== [] ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }

        return rest_get_server()->dispatch( $request );
    }

    // ---- who may call it ---------------------------------------------------

    public function test_a_coach_is_refused(): void {
        $this->asRole( 'tt_coach' );

        $this->assertSame( 403, $this->call( 'GET', self::ROUTE )->get_status() );
        $this->assertSame(
            403,
            $this->call( 'PUT', self::ROUTE, [ 'scopes' => [ self::PERSONA . '|' . self::ENTITY => 'global' ] ] )->get_status()
        );
    }

    public function test_a_club_admin_may_read_the_grid(): void {
        $this->asClubAdmin();

        $response = $this->call( 'GET', self::ROUTE );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $payload = $data['data'] ?? $data;

        $this->assertArrayHasKey( 'personas', $payload );
        $this->assertArrayHasKey( 'entities', $payload );
        $this->assertArrayHasKey( 'grid', $payload );
        $this->assertFalse( $payload['editable']['unrestricted'] );
        $this->assertContains( 'authorization_matrix', $payload['editable']['protected_entities'] );
    }

    // ---- the guardrail, over the wire --------------------------------------

    /**
     * The screen's disabled checkbox is not the control — this is the call
     * that proves it, because REST never saw the markup.
     */
    public function test_a_club_admin_cannot_escalate_through_rest(): void {
        $this->asClubAdmin();

        $response = $this->call( 'PUT', self::ROUTE, [
            'cells'  => [ self::PERSONA . '|' . self::PROTECTED . '|change' => true ],
            'scopes' => [ self::PERSONA . '|' . self::PROTECTED             => 'global' ],
        ] );

        $this->assertSame( 200, $response->get_status() );
        $data    = $response->get_data();
        $payload = $data['data'] ?? $data;

        $this->assertSame( 1, $payload['rejected'] );
        $this->assertSame( 0, $payload['grants'] );
        $this->assertFalse(
            ( new MatrixRepository() )->lookup( self::PERSONA, self::PROTECTED, 'change', 'global' ),
            'REST wrote a cell the guardrail was supposed to refuse'
        );
    }

    public function test_a_club_admin_can_change_an_ordinary_cell_through_rest(): void {
        $this->asClubAdmin();

        $response = $this->call( 'PUT', self::ROUTE, [
            'cells'  => [ self::PERSONA . '|' . self::ENTITY . '|change' => true ],
            'scopes' => [ self::PERSONA . '|' . self::ENTITY             => 'global' ],
        ] );

        $data    = $response->get_data();
        $payload = $data['data'] ?? $data;

        $this->assertSame( 1, $payload['grants'] );
        $this->assertTrue(
            ( new MatrixRepository() )->lookup( self::PERSONA, self::ENTITY, 'change', 'global' )
        );
    }

    /**
     * `scopes` is the coverage declaration, so an empty one is refused
     * rather than read as "revoke everything".
     */
    public function test_a_put_without_scopes_is_refused(): void {
        $this->asRole( 'administrator' );

        $response = $this->call( 'PUT', self::ROUTE, [ 'cells' => [] ] );

        // Either the arg schema rejects the missing required param, or the
        // callback does — both are a refusal, and neither writes anything.
        $this->assertGreaterThanOrEqual( 400, $response->get_status() );
    }

    // ---- reset stays with the administrator --------------------------------

    public function test_reset_is_refused_to_a_club_admin(): void {
        $this->asClubAdmin();

        $this->assertSame(
            403,
            $this->call( 'POST', self::ROUTE . '/reset' )->get_status(),
            'a reset discards edits that were never the club admin\'s to discard'
        );
    }
}
