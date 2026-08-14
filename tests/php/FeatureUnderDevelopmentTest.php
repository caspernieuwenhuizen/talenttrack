<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Shared\Frontend\Components\DevelopmentPill;

/**
 * #2387 — the per-feature "under development" flag. Cosmetic: it drives an
 * informational pill on the feature's views and never changes whether the
 * feature is enabled. These tests pin that independence and the view-slug
 * lookup the dispatcher uses.
 */
final class FeatureUnderDevelopmentTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        global $wpdb; $wpdb->hide_errors();
        // FeatureRegistry caches state per-request in private statics; the
        // DB rolls back between tests but the statics don't, so clear them.
        $ref = new \ReflectionClass( FeatureRegistry::class );
        foreach ( [ 'stateCache', 'devStateCache' ] as $prop ) {
            $p = $ref->getProperty( $prop );
            $p->setAccessible( true );
            $p->setValue( null, null );
        }
    }

    public function test_flag_defaults_off(): void {
        $this->assertFalse( FeatureRegistry::isUnderDevelopment( 'player_journey' ) );
        $this->assertFalse( FeatureRegistry::underDevelopmentForViewSlug( 'my-journey' ) );
        $this->assertSame( '', DevelopmentPill::htmlForViewSlug( 'my-journey' ) );
    }

    public function test_marking_under_development_sets_flag_and_slug_lookup(): void {
        FeatureRegistry::setUnderDevelopment( 'player_journey', true );

        $this->assertTrue( FeatureRegistry::isUnderDevelopment( 'player_journey' ) );
        $this->assertTrue( FeatureRegistry::underDevelopmentForViewSlug( 'my-journey' ) );
        $this->assertStringContainsString( 'tt-dev-pill', DevelopmentPill::htmlForViewSlug( 'my-journey' ) );
    }

    public function test_marking_under_development_does_not_enable_a_default_off_feature(): void {
        // cohort_transitions is default_enabled=false with its module on.
        $this->assertFalse( FeatureRegistry::isEnabled( 'cohort_transitions' ) );

        FeatureRegistry::setUnderDevelopment( 'cohort_transitions', true );

        $this->assertTrue( FeatureRegistry::isUnderDevelopment( 'cohort_transitions' ) );
        $this->assertFalse(
            FeatureRegistry::isEnabled( 'cohort_transitions' ),
            'marking under development must never enable a default-off feature'
        );
    }

    public function test_toggling_enabled_preserves_the_dev_flag(): void {
        FeatureRegistry::setUnderDevelopment( 'player_journey', true );
        FeatureRegistry::setEnabled( 'player_journey', false );

        $this->assertTrue(
            FeatureRegistry::isUnderDevelopment( 'player_journey' ),
            'toggling enabled must not clear the under-development flag'
        );
        $this->assertFalse( FeatureRegistry::isEnabled( 'player_journey' ) );
    }

    public function test_unknown_feature_is_never_under_development(): void {
        $this->assertFalse( FeatureRegistry::isUnderDevelopment( 'nope_not_a_feature' ) );
    }
}
