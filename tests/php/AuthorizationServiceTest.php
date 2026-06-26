<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * Tier 1 (#1388) — authorization decisions, the historically buggy class
 * (#1143/#1105/#1106/#1147/#1159/#1189 — the same authz bug six times before
 * the audit-1 CI guard). Two layers:
 *
 *   - userCanOrMatrix(): the REST permission-callback cap helper. Contract +
 *     allow-on-cap / deny-without (the deny direction the audit emphasised).
 *   - MatrixRepository grant round-trip: a granted persona/entity/activity row
 *     reads back as allowed; an ungranted one does not. A broken grant
 *     write/lookup — the exact regression class — fails the build here.
 *
 * The full user→persona→MatrixGate::can scope round-trip and the REST smoke
 * suite (Tier 2) build on this harness.
 */
final class AuthorizationServiceTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        AuthorizationService::flushCache();
    }

    public function test_userCanOrMatrix_rejects_empty_inputs(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertFalse( AuthorizationService::userCanOrMatrix( $uid, '' ), 'empty cap denies' );
        $this->assertFalse( AuthorizationService::userCanOrMatrix( 0, 'tt_view_players' ), 'invalid user denies' );
    }

    public function test_userCanOrMatrix_allows_held_cap_and_denies_without(): void {
        // Under the booted kernel, AuthorizationModule::filterUserHasCap makes
        // LegacyCapMapper authoritative for every tt_* cap — a bare add_cap()
        // is recomputed against the matrix and overridden. The architectural
        // grant path for a held cap is the administrator bypass (an admin
        // unconditionally passes tt_* checks — see LegacyCapMapper::evaluate)
        // or a matrix row (covered by test_matrix_grant_round_trips). Assert
        // both directions: admin is permitted, a bare subscriber (no role-cap,
        // no matrix grant) is denied-by-default.
        $with    = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $without = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertTrue(
            AuthorizationService::userCanOrMatrix( $with, 'tt_view_players' ),
            'a user holding the cap is permitted'
        );
        $this->assertFalse(
            AuthorizationService::userCanOrMatrix( $without, 'tt_view_players' ),
            'a user without the cap or a matrix grant is denied (deny-by-default)'
        );
    }

    public function test_matrix_grant_round_trips(): void {
        if ( ! class_exists( MatrixRepository::class ) ) {
            $this->fail( 'MatrixRepository missing — authz schema not migrated in the test env.' );
        }

        $repo    = new MatrixRepository();
        $persona = 'tt_test_persona';

        $repo->setRow( $persona, 'players', 'view', 'global', '' );

        $this->assertTrue(
            $repo->lookup( $persona, 'players', 'view', 'global' ),
            'a granted (persona, entity, activity, scope) row must read back as allowed'
        );
        $this->assertFalse(
            $repo->lookup( $persona, 'players', 'change', 'global' ),
            'an activity that was not granted must not be allowed'
        );
        $this->assertFalse(
            $repo->lookup( 'tt_unseeded_persona', 'players', 'view', 'global' ),
            'a persona with no grant must not be allowed (no phantom access)'
        );
    }
}
