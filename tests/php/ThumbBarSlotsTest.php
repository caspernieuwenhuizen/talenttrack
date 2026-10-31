<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\FrontendAppBottomBar;
use TT\Shared\Tiles\TileRegistry;

/**
 * #2810 — the per-persona thumb-bar slots.
 *
 * The load-bearing test is the first one. Every configured slot has to be
 * a surface a phone can actually open, because a `desktop_only` slug a
 * thumb-tap away is the prompt page a thumb-tap away — a bar that looks
 * like navigation and is a wall.
 *
 * That rule is easy to break silently: reclassifying a surface to
 * `desktop_only` in `config/mobile_surfaces.php` is a one-line edit in a
 * different file, made for a good reason, with nothing today connecting it
 * to the thumb bar. This is that connection.
 */
final class ThumbBarSlotsTest extends WP_UnitTestCase {

    /** @var list<array<string,mixed>> */
    private $tiles_were = [];

    /**
     * #4428 — the admin tests passed on an empty registry, where there was
     * nothing to put in a bar whatever the persona. Fill it here.
     */
    public function set_up(): void {
        parent::set_up();
        $this->tiles_were = TileRegistry::allRegistered();
        TileRegistry::clear();
        \TT\Shared\CoreSurfaceRegistration::register();
    }

    public function tear_down(): void {
        TileRegistry::clear();
        foreach ( $this->tiles_were as $tile ) {
            TileRegistry::register( $tile );
        }
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @return array<string, list<string>> */
    private function defaultSlots(): array {
        $ref  = new \ReflectionClass( FrontendAppBottomBar::class );
        /** @var array<string, list<string>> $slots */
        $slots = $ref->getConstant( 'DEFAULT_SLOTS' );
        return $slots;
    }

    /** @return array<string, string> slug => class */
    private function mobileClasses(): array {
        $map = require dirname( __DIR__, 2 ) . '/config/mobile_surfaces.php';

        $out = [];
        foreach ( (array) $map as $slug => $entry ) {
            $out[ (string) $slug ] = is_array( $entry ) ? (string) ( $entry[0] ?? '' ) : (string) $entry;
        }
        return $out;
    }

    public function test_no_configured_slot_is_a_desktop_only_surface(): void {
        $classes = $this->mobileClasses();
        $bad     = [];

        foreach ( $this->defaultSlots() as $persona => $slugs ) {
            foreach ( $slugs as $slug ) {
                $class = $classes[ $slug ] ?? null;

                if ( $class === null ) {
                    $bad[] = "{$persona}: {$slug} is not in config/mobile_surfaces.php";
                    continue;
                }
                if ( $class === 'desktop_only' ) {
                    $bad[] = "{$persona}: {$slug} is desktop_only — a thumb-tap to the prompt page";
                }
            }
        }

        $this->assertSame( [], $bad, implode( "\n", $bad ) );
    }

    public function test_every_persona_declares_exactly_four_slots(): void {
        foreach ( $this->defaultSlots() as $persona => $slugs ) {
            $this->assertCount( 4, $slugs, "{$persona} does not declare four slots" );
            $this->assertSame(
                array_values( array_unique( $slugs ) ),
                array_values( $slugs ),
                "{$persona} repeats a slot"
            );
        }
    }

    public function test_the_academy_admin_gets_no_bar(): void {
        $this->assertNotEmpty( TileRegistry::allRegistered(), 'an empty tile registry proves nothing about the persona rule' );
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

        // The bar excludes setup surfaces by design, and setup is this
        // persona's entire dashboard — so any bar rendered for them is
        // either misleading or a different thing wearing the same chrome.
        $this->assertSame( [], FrontendAppBottomBar::slots( $admin ) );
    }

    public function test_the_academy_admin_renders_no_markup_at_all(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

        ob_start();
        FrontendAppBottomBar::render( $admin, '', home_url( '/' ) );
        $html = (string) ob_get_clean();

        // Not hidden in CSS: hidden markup still ships and still holds a
        // place in the keyboard tab order.
        $this->assertSame( '', trim( $html ) );
    }

    public function test_a_real_admin_without_a_switcher_choice_gets_no_bar(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        delete_user_meta( $admin, 'tt_active_persona' );
        wp_set_current_user( $admin );

        $this->assertNotEmpty(
            \TT\Shared\Frontend\Components\FrontendAppNav::groups( $admin ),
            'the admin must see tiles, or there is nothing a bar could have shown'
        );
        $this->assertNull( \TT\Modules\Authorization\PersonaResolver::activePersona( $admin ) );
        $this->assertSame( 'academy_admin', \TT\Modules\Authorization\PersonaResolver::landingPersona( $admin ) );
        $this->assertSame( [], FrontendAppBottomBar::slots( $admin ) );
    }

    public function test_head_coach_slot_three_is_teams(): void {
        // The decision recorded on #2810: the bar serves the daily routine,
        // and evaluations are periodic. If this flips back, it should be a
        // decision rather than a drive-by edit.
        $slots = $this->defaultSlots();

        $this->assertSame( 'teams', $slots['head_coach'][2] ?? null );
    }

    public function test_readonly_observer_has_no_shipped_default(): void {
        // Absent on purpose — it has no numbered persona actions to trace
        // to, so it falls through to the derived default rather than to a
        // guess. Asserted so that adding one is a deliberate act.
        $this->assertArrayNotHasKey( 'readonly_observer', $this->defaultSlots() );
    }
}
