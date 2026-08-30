<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\ModuleRegistry;
use TT\Shared\CoreSurfaceRegistration;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\CrossViewLinkRegistry;
use TT\Shared\Tiles\TileRegistry;

/**
 * #3254 — a switched-off module's surfaces stop being offered.
 *
 * Two halves failed for the same reason and both are covered here.
 *
 * **Ownership.** `TileRegistry::isViewSlugDisabled()` resolved a slug's
 * owning module from registered tiles. A disabled module never runs
 * `register()` or `boot()`, so the tile that would prove ownership does
 * not exist in exactly the state the gate was written to catch: the gate
 * returned false and the route dispatched normally. Ownership now lives
 * on `CoreSurfaceRegistration`, which `Kernel::register()` runs whether
 * or not any module boots.
 *
 * **The affordance.** `CrossViewLink::allows()` only ever asked the
 * capability gate. `LegacyCapMapper` lets a WP `administrator` pass every
 * `tt_*` cap unconditionally — a deliberate override that stays — so the
 * "Execute training" button vanished for a coach and stayed put for the
 * operator who had just switched Training off. Every assertion below runs
 * **as an administrator** for that reason; as a coach they would pass
 * against the old code too, and prove nothing.
 */
final class DisabledModuleSurfaceTest extends WP_UnitTestCase {

    private const TRAINING = 'TT\\Modules\\Training\\TrainingModule';
    private const VCT      = 'TT\\Modules\\Vct\\VctModule';

    /** Training's five dispatcher slugs — the set reported in #3254. */
    private const TRAINING_SLUGS = [
        'training-plans',
        'training-plan',
        'training-run',
        'training-photo',
        'training-coverage',
    ];

    private int $admin;

    public function set_up(): void {
        parent::set_up();
        CoreSurfaceRegistration::register();

        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        // Module state persists in a table; leaving one off would break
        // every test that runs after this file.
        ModuleRegistry::setEnabled( self::TRAINING, true );
        ModuleRegistry::setEnabled( self::VCT, true );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── ownership survives the module being off ────────────────────────

    /**
     * The bug, stated directly: with the module off, the gate whose whole
     * job is to catch that must say so — for every one of its slugs, not
     * only the one that happens to have a tile.
     */
    public function test_every_training_slug_reports_disabled_when_the_module_is_off(): void {
        ModuleRegistry::setEnabled( self::TRAINING, false );

        foreach ( self::TRAINING_SLUGS as $slug ) {
            $this->assertTrue(
                TileRegistry::isViewSlugDisabled( $slug ),
                "?tt_view={$slug} must report its module disabled — otherwise the dispatcher routes to a view whose module never booted."
            );
        }
    }

    /** And says nothing when the module is on. */
    public function test_no_training_slug_reports_disabled_when_the_module_is_on(): void {
        ModuleRegistry::setEnabled( self::TRAINING, true );

        foreach ( self::TRAINING_SLUGS as $slug ) {
            $this->assertFalse(
                TileRegistry::isViewSlugDisabled( $slug ),
                "?tt_view={$slug} must dispatch normally while its module is on."
            );
        }
    }

    /** Not Training-shaped — a second module behaves the same way. */
    public function test_the_fix_is_not_training_shaped(): void {
        ModuleRegistry::setEnabled( self::VCT, false );

        foreach ( [ 'vct-library', 'vct-session', 'vct-config' ] as $slug ) {
            $this->assertTrue( TileRegistry::isViewSlugDisabled( $slug ) );
        }
    }

    /** A slug no module owns is never gated by this mechanism. */
    public function test_an_unowned_slug_is_never_reported_disabled(): void {
        ModuleRegistry::setEnabled( self::TRAINING, false );

        $this->assertFalse( TileRegistry::isViewSlugDisabled( 'accept-invite' ) );
        $this->assertFalse( TileRegistry::isViewSlugDisabled( 'audit-log' ) );
    }

    // ── the affordance, as an administrator ────────────────────────────

    /**
     * The case the capability layer cannot catch: an administrator passes
     * every `tt_*` cap, so only a module-state check can hide the CTA.
     */
    public function test_cross_view_link_refuses_a_disabled_module_for_an_administrator(): void {
        $this->assertTrue(
            CrossViewLink::allows( 'training-run' ),
            'Precondition: with Training on, an administrator may reach the sideline view.'
        );

        ModuleRegistry::setEnabled( self::TRAINING, false );

        $this->assertFalse(
            CrossViewLink::allows( 'training-run' ),
            'With Training off, the "Execute training" affordance must not render — for an administrator too.'
        );
    }

    /** `render()` follows `allows()`, so the markup disappears with it. */
    public function test_cross_view_link_renders_nothing_for_a_disabled_module(): void {
        ModuleRegistry::setEnabled( self::TRAINING, false );

        ob_start();
        CrossViewLink::render( 'training-run', static function (): void {
            echo '<a href="?tt_view=training-run">Execute training</a>';
        } );
        $html = (string) ob_get_clean();

        $this->assertSame( '', trim( $html ) );
    }

    /**
     * A caller passing its own `gate` must not be able to route around the
     * module check — that override branch skips the registry entirely,
     * which is why the check sits ahead of it.
     */
    public function test_an_explicit_gate_override_cannot_skip_the_module_check(): void {
        ModuleRegistry::setEnabled( self::TRAINING, false );

        $this->assertFalse(
            CrossViewLink::allows( 'training-run', [ 'gate' => static fn(): bool => true ] ),
            'An always-true gate override must still lose to a switched-off module.'
        );
    }

    /** The check is a predicate of its own, and answers for any slug. */
    public function test_surface_switched_off_is_answerable_directly(): void {
        ModuleRegistry::setEnabled( self::TRAINING, false );

        $this->assertTrue( CrossViewLinkRegistry::surfaceSwitchedOff( 'training-run' ) );
        $this->assertFalse( CrossViewLinkRegistry::surfaceSwitchedOff( 'players' ) );
        $this->assertFalse( CrossViewLinkRegistry::surfaceSwitchedOff( '' ) );
    }
}
