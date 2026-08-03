<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\CoreSurfaceRegistration;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\CrossViewLinkRegistry;

/**
 * #2304 — CrossViewLinkRegistry / CrossViewLink unit coverage.
 *
 * Static authorization singletons (AuthorizationService / MatrixGate) are
 * awkward to mock, so the delegation forms are exercised through registered
 * CLOSURES the test fully controls (a closure that asserts it received the
 * expected args and returns a canned bool). The decision plumbing —
 * override precedence, unregistered fallback, user-id guard, isRegistered —
 * is tested directly. A registration-completeness assertion confirms every
 * slug the spec requires is wired up after CoreSurfaceRegistration::register().
 *
 * Hermetic: the registry is cleared in set_up / tear_down so no test leaks a
 * gate into another.
 */
final class CrossViewLinkRegistryTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        CrossViewLinkRegistry::clear();
    }

    public function tear_down(): void {
        CrossViewLinkRegistry::clear();
        // Re-seed the real gates so a later test in the same process that
        // relies on CoreSurfaceRegistration state isn't left with an empty
        // registry.
        CoreSurfaceRegistration::register();
        parent::tear_down();
    }

    // ── registration + isRegistered ────────────────────────────────────

    public function test_is_registered_reflects_register_and_clear(): void {
        $this->assertFalse( CrossViewLinkRegistry::isRegistered( 'demo-slug' ) );
        CrossViewLinkRegistry::register( 'demo-slug', static fn( int $u, array $c ): bool => true );
        $this->assertTrue( CrossViewLinkRegistry::isRegistered( 'demo-slug' ) );
        CrossViewLinkRegistry::clear();
        $this->assertFalse( CrossViewLinkRegistry::isRegistered( 'demo-slug' ) );
    }

    public function test_empty_slug_is_not_registered(): void {
        CrossViewLinkRegistry::register( '', static fn(): bool => true );
        $this->assertFalse( CrossViewLinkRegistry::isRegistered( '' ) );
    }

    // ── gate-form evaluation ───────────────────────────────────────────

    public function test_closure_gate_receives_uid_and_ctx(): void {
        $seen = [];
        CrossViewLinkRegistry::register( 'demo', static function ( int $uid, array $ctx ) use ( &$seen ): bool {
            $seen = [ 'uid' => $uid, 'ctx' => $ctx ];
            return $ctx['ok'] ?? false;
        } );

        $this->assertTrue( CrossViewLinkRegistry::allows( 'demo', 42, [ 'ok' => true ] ) );
        $this->assertSame( 42, $seen['uid'] );
        $this->assertSame( [ 'ok' => true ], $seen['ctx'] );

        $this->assertFalse( CrossViewLinkRegistry::allows( 'demo', 42, [ 'ok' => false ] ) );
    }

    public function test_registered_gate_denies_anonymous_user(): void {
        // Even a permissive closure is short-circuited to deny when uid <= 0.
        $called = false;
        CrossViewLinkRegistry::register( 'demo', static function () use ( &$called ): bool {
            $called = true;
            return true;
        } );
        $this->assertFalse( CrossViewLinkRegistry::allows( 'demo', 0 ) );
        $this->assertFalse( $called, 'gate must not be evaluated for uid <= 0' );
    }

    public function test_string_gate_shape_delegates_to_authorization_service(): void {
        // We can't stub the static, but we can prove the string branch is
        // taken (not the array/closure branch) by observing it returns a
        // bool for a known-denied cap against a low-privilege subscriber.
        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        CrossViewLinkRegistry::register( 'demo', 'tt_view_plan' );
        // A subscriber holds no tt_* caps and no matrix scope → denied.
        $this->assertFalse( CrossViewLinkRegistry::allows( 'demo', (int) $uid ) );
    }

    public function test_array_gate_shape_delegates_to_matrix_gate(): void {
        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        CrossViewLinkRegistry::register( 'demo', [ 'measurements', 'change' ] );
        // Subscriber has no matrix scope on measurements → denied.
        $this->assertFalse( CrossViewLinkRegistry::allows( 'demo', (int) $uid ) );
    }

    public function test_malformed_array_gate_denies(): void {
        CrossViewLinkRegistry::register( 'demo', [ 'only-one-element' ] );
        $this->assertFalse( CrossViewLinkRegistry::allows( 'demo', 42 ) );
    }

    // ── unregistered fallback ──────────────────────────────────────────

    public function test_unregistered_slug_with_no_tile_entity_is_permissive(): void {
        // A slug no tile declares has no entity → fallback returns true.
        $this->assertTrue(
            CrossViewLinkRegistry::allows( 'totally-unknown-slug-xyz', 42 )
        );
    }

    // ── CrossViewLink decision helper ──────────────────────────────────

    public function test_explicit_gate_override_wins_over_registry(): void {
        // Registry says deny (closure returns false); the explicit override
        // says allow. allows() must honour the override.
        CrossViewLinkRegistry::register( 'demo', static fn(): bool => false );
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( (int) $uid );

        $this->assertTrue(
            CrossViewLink::allows( 'demo', [ 'gate' => static fn(): bool => true ] )
        );
        // Without the override, the registry's deny applies.
        $this->assertFalse( CrossViewLink::allows( 'demo' ) );
    }

    public function test_override_gate_passes_ctx_and_uid(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( (int) $uid );

        $seen = [];
        $ok = CrossViewLink::allows( 'demo', [
            'ctx'  => [ 'player_id' => 7 ],
            'gate' => static function ( int $u, array $ctx ) use ( &$seen ): bool {
                $seen = [ 'uid' => $u, 'ctx' => $ctx ];
                return true;
            },
        ] );
        $this->assertTrue( $ok );
        $this->assertSame( (int) $uid, $seen['uid'] );
        $this->assertSame( [ 'player_id' => 7 ], $seen['ctx'] );
    }

    public function test_render_echoes_only_when_allowed(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( (int) $uid );

        CrossViewLinkRegistry::register( 'yes', static fn(): bool => true );
        CrossViewLinkRegistry::register( 'no', static fn(): bool => false );

        ob_start();
        CrossViewLink::render( 'yes', static function (): void { echo 'SHOWN'; } );
        $shown = ob_get_clean();
        $this->assertSame( 'SHOWN', $shown );

        ob_start();
        CrossViewLink::render( 'no', static function (): void { echo 'HIDDEN'; } );
        $hidden = ob_get_clean();
        $this->assertSame( '', $hidden );
    }

    // ── registration completeness ──────────────────────────────────────

    public function test_core_surface_registers_every_expected_gate(): void {
        CrossViewLinkRegistry::clear();
        CoreSurfaceRegistration::register();

        $expected = [
            'team-planner',
            'methodology',
            'team-chemistry',
            'team-blueprints',
            'measurement-tests',
            'measurements-entry',
            'measurements-coverage',
            'player-attributes',
        ];
        foreach ( $expected as $slug ) {
            $this->assertTrue(
                CrossViewLinkRegistry::isRegistered( $slug ),
                "expected cross-view gate for '{$slug}' to be registered"
            );
        }
    }
}
