<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\DevelopmentFlags;
use TT\Core\ModuleRegistry;
use TT\Shared\Frontend\Components\DevelopmentPill;

/**
 * #2409 — the per-module "under development" flag, the module-level twin of
 * #2387's per-feature one. Cosmetic: it drives the informational pill on the
 * module's views and the badge on its dashboard tiles, and never changes
 * whether the module is enabled. These tests pin that independence, the
 * always-on exception, and the shared resolution rule.
 */
final class ModuleUnderDevelopmentTest extends WP_UnitTestCase {

    private const GOALS = 'TT\\Modules\\Goals\\GoalsModule';
    private const CORE  = 'TT\\Modules\\Auth\\AuthModule';

    public function set_up(): void {
        parent::set_up();
        global $wpdb; $wpdb->hide_errors();
        // ModuleRegistry caches state per-request in private statics; the DB
        // rolls back between tests but the statics don't, so clear them.
        $ref = new \ReflectionClass( ModuleRegistry::class );
        foreach ( [ 'stateCache', 'devStateCache' ] as $prop ) {
            $p = $ref->getProperty( $prop );
            $p->setAccessible( true );
            $p->setValue( null, null );
        }
    }

    public function test_flag_defaults_off(): void {
        $this->assertFalse( ModuleRegistry::isUnderDevelopment( self::GOALS ) );
    }

    public function test_marking_under_development_sets_the_flag(): void {
        ModuleRegistry::setUnderDevelopment( self::GOALS, true );

        $this->assertTrue( ModuleRegistry::isUnderDevelopment( self::GOALS ) );
    }

    public function test_marking_under_development_does_not_change_enabled(): void {
        ModuleRegistry::setEnabled( self::GOALS, false );
        $this->assertFalse( ModuleRegistry::isEnabled( self::GOALS ) );

        ModuleRegistry::setUnderDevelopment( self::GOALS, true );

        $this->assertTrue( ModuleRegistry::isUnderDevelopment( self::GOALS ) );
        $this->assertFalse(
            ModuleRegistry::isEnabled( self::GOALS ),
            'flagging a disabled module must never switch it on'
        );
    }

    public function test_toggling_enabled_preserves_the_dev_flag(): void {
        ModuleRegistry::setUnderDevelopment( self::GOALS, true );
        ModuleRegistry::setEnabled( self::GOALS, false );

        $this->assertTrue(
            ModuleRegistry::isUnderDevelopment( self::GOALS ),
            'toggling enabled must not clear the under-development flag'
        );
    }

    public function test_an_always_on_core_module_can_still_be_flagged(): void {
        ModuleRegistry::setUnderDevelopment( self::CORE, true );

        $this->assertTrue(
            ModuleRegistry::isUnderDevelopment( self::CORE ),
            'the flag gates nothing, so core modules are not exempt from it'
        );
        $this->assertTrue(
            ModuleRegistry::isEnabled( self::CORE ),
            'a core module stays enabled regardless'
        );
    }

    public function test_unflagging_clears_it(): void {
        ModuleRegistry::setUnderDevelopment( self::GOALS, true );
        ModuleRegistry::setUnderDevelopment( self::GOALS, false );

        $this->assertFalse( ModuleRegistry::isUnderDevelopment( self::GOALS ) );
    }

    public function test_all_with_state_exposes_the_flag(): void {
        ModuleRegistry::setUnderDevelopment( self::GOALS, true );

        $found = null;
        foreach ( ModuleRegistry::allWithState() as $m ) {
            if ( (string) $m['class'] === self::GOALS ) { $found = $m; break; }
        }

        $this->assertNotNull( $found, 'the module should appear in allWithState()' );
        $this->assertArrayHasKey( 'under_development', $found );
        $this->assertTrue( (bool) $found['under_development'] );
    }

    /**
     * A slug owned by no flagged feature and no flagged module resolves
     * false, and produces neither pill nor badge.
     */
    public function test_unflagged_slug_renders_nothing(): void {
        $this->assertFalse( DevelopmentFlags::forViewSlug( 'my-journey' ) );
        $this->assertSame( '', DevelopmentPill::htmlForViewSlug( 'my-journey' ) );
        $this->assertSame( '', DevelopmentPill::badgeForViewSlug( 'my-journey' ) );
    }

    public function test_empty_slug_is_never_under_development(): void {
        $this->assertFalse( DevelopmentFlags::forViewSlug( '' ) );
        $this->assertSame( '', DevelopmentPill::badgeForViewSlug( '' ) );
    }
}
