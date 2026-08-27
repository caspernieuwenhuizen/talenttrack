<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\FeatureStatusService;
use TT\Core\ModuleRegistry;
use TT\Shared\Modules\ModuleMetadata;

/**
 * #2878 — the capability catalog behind `?tt_view=features`.
 *
 * The catalog is a filtered read model, and every one of its filters is a
 * product decision rather than an implementation detail: which capabilities
 * an academy is offered, and which are quietly withheld because they are
 * still being built. These tests pin each rule, and pin that the older
 * `overview()` audit shape did not inherit any of them.
 */
final class FeatureCatalogTest extends WP_UnitTestCase {

    private const GOALS    = 'TT\\Modules\\Goals\\GoalsModule';
    private const ALWAYS_ON = 'TT\\Modules\\Auth\\AuthModule';
    private const ADVANCED  = 'TT\\Modules\\DataBrowser\\DataBrowserModule';

    public function set_up(): void {
        parent::set_up();
        global $wpdb; $wpdb->hide_errors();
        $this->clearCaches();
    }

    /**
     * Both registries cache state in private statics. The DB rolls back
     * between tests; the statics do not.
     */
    private function clearCaches(): void {
        $module_ref = new \ReflectionClass( ModuleRegistry::class );
        foreach ( [ 'stateCache', 'devStateCache' ] as $prop ) {
            $p = $module_ref->getProperty( $prop );
            $p->setAccessible( true );
            $p->setValue( null, null );
        }
        $feature_ref = new \ReflectionClass( FeatureRegistry::class );
        foreach ( [ 'stateCache', 'devStateCache' ] as $prop ) {
            $p = $feature_ref->getProperty( $prop );
            $p->setAccessible( true );
            $p->setValue( null, null );
        }
    }

    /** Every module entry in the catalog, across categories and both bands. */
    private function entries(): array {
        $out = [];
        foreach ( FeatureStatusService::catalog() as $group ) {
            foreach ( [ 'in_use', 'available' ] as $band ) {
                foreach ( $group[ $band ] as $entry ) {
                    $entry['_band']     = $band;
                    $entry['_category'] = $group['category'];
                    $out[] = $entry;
                }
            }
        }
        return $out;
    }

    private function labels(): array {
        return array_column( $this->entries(), 'label' );
    }

    private function findEntry( string $label ): ?array {
        foreach ( $this->entries() as $entry ) {
            if ( $entry['label'] === $label ) return $entry;
        }
        return null;
    }

    public function test_catalog_uses_written_labels_not_class_names(): void {
        ModuleRegistry::setEnabled( self::GOALS, true );
        $this->clearCaches();

        $entry = $this->findEntry( ModuleMetadata::for( self::GOALS )['label'] );

        $this->assertNotNull( $entry, 'Goals should appear in the catalog' );
        $this->assertNotSame( '', $entry['description'], 'a catalog card must carry its written description' );
        $this->assertNotSame( '', $entry['icon'], 'a catalog card must carry its icon' );
    }

    public function test_an_enabled_module_lands_in_use_and_a_disabled_one_in_available(): void {
        ModuleRegistry::setEnabled( self::GOALS, true );
        $this->clearCaches();
        $label = ModuleMetadata::for( self::GOALS )['label'];
        $this->assertSame( 'in_use', $this->findEntry( $label )['_band'] );

        ModuleRegistry::setEnabled( self::GOALS, false );
        $this->clearCaches();
        $this->assertSame( 'available', $this->findEntry( $label )['_band'] );
    }

    public function test_always_on_core_is_never_offered_as_a_capability(): void {
        $label = ModuleMetadata::for( self::ALWAYS_ON )['label'];

        $this->assertNotContains( $label, $this->labels() );
    }

    public function test_the_advanced_category_is_never_offered(): void {
        $label = ModuleMetadata::for( self::ADVANCED )['label'];

        $this->assertNotContains( $label, $this->labels() );
        foreach ( $this->entries() as $entry ) {
            $this->assertNotSame( ModuleMetadata::CAT_ADVANCED, $entry['_category'] );
        }
    }

    public function test_a_module_that_is_off_and_under_development_is_not_advertised(): void {
        ModuleRegistry::setEnabled( self::GOALS, false );
        ModuleRegistry::setUnderDevelopment( self::GOALS, true );
        $this->clearCaches();

        $this->assertNotContains(
            ModuleMetadata::for( self::GOALS )['label'],
            $this->labels(),
            'a half-built capability nobody is using must not be offered'
        );
    }

    public function test_a_module_that_is_on_and_under_development_stays_visible(): void {
        ModuleRegistry::setEnabled( self::GOALS, true );
        ModuleRegistry::setUnderDevelopment( self::GOALS, true );
        $this->clearCaches();

        $entry = $this->findEntry( ModuleMetadata::for( self::GOALS )['label'] );

        $this->assertNotNull( $entry, 'a live surface must stay listed even while flagged' );
        $this->assertSame( 'in_use', $entry['_band'] );
        $this->assertTrue( $entry['under_development'], 'so the card can carry its pill' );
    }

    public function test_a_feature_that_is_off_and_under_development_is_dropped_from_its_card(): void {
        $feature = $this->firstFeatureKey();
        if ( $feature === null ) {
            $this->markTestSkipped( 'no catalog module owns a sub-feature on this install' );
        }
        [ $module_label, $key, $label ] = $feature;

        FeatureRegistry::setEnabled( $key, false );
        FeatureRegistry::setUnderDevelopment( $key, true );
        $this->clearCaches();

        $entry = $this->findEntry( $module_label );
        $this->assertNotNull( $entry );
        $this->assertNotContains( $label, array_column( $entry['features'], 'label' ) );
    }

    public function test_overview_keeps_everything_the_catalog_filters_out(): void {
        ModuleRegistry::setEnabled( self::GOALS, false );
        ModuleRegistry::setUnderDevelopment( self::GOALS, true );
        $this->clearCaches();

        $overview_labels = array_column( FeatureStatusService::overview(), 'label' );

        // overview() derives its label from the class name, so match on that.
        $this->assertContains( 'Goals', $overview_labels, 'the audit shape must stay unfiltered' );
    }

    /**
     * A module owning at least one sub-feature *and* at least one tile of
     * its own. The tile matters: a module whose only content is the
     * feature under test drops out of the catalog entirely once that
     * feature is hidden — correct behaviour, but it makes the card
     * impossible to inspect, so this test needs a card that survives.
     *
     * @return array{0:string,1:string,2:string}|null module label, feature key, feature label
     */
    private function firstFeatureKey(): ?array {
        foreach ( $this->entries() as $entry ) {
            if ( empty( $entry['features'] ) || empty( $entry['provides'] ) ) continue;
            $feature = $entry['features'][0];
            return [ $entry['label'], (string) $feature['key'], (string) $feature['label'] ];
        }
        return null;
    }
}
