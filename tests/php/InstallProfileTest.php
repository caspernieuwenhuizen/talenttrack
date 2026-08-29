<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\Container;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Shared\Modules\ProfileRegistry;
use TT\Shared\Modules\ProfileService;
use TT\Shared\Tiles\TileRegistry;

/**
 * #3035 — install profiles.
 *
 * Three things are worth pinning here and they are quite different from
 * each other: that a profile resolves at all, that applying one is a
 * write nobody asked for twice, and — the one this issue was most likely
 * to get wrong — that the Basics module set actually boots. Inter-module
 * dependencies are not enforced (`docs/modules.md` § Dependencies), so a
 * profile that switches off thirty modules at once is exactly the shape
 * that leaves a kept surface pointing at a module that is gone.
 */
final class InstallProfileTest extends WP_UnitTestCase {

    private const BASICS = 'basics';

    public function set_up(): void {
        parent::set_up();
        global $wpdb; $wpdb->hide_errors();
        $this->clearCaches();
    }

    public function tear_down(): void {
        $this->clearCaches();
        parent::tear_down();
    }

    /**
     * Both registries memoise state in private statics, and the
     * ConfigService memoises per key. The DB rolls back between tests;
     * none of those do.
     */
    private function clearCaches(): void {
        foreach ( [ ModuleRegistry::class, FeatureRegistry::class ] as $class ) {
            $ref = new \ReflectionClass( $class );
            foreach ( [ 'stateCache', 'devStateCache' ] as $prop ) {
                $p = $ref->getProperty( $prop );
                $p->setAccessible( true );
                $p->setValue( null, null );
            }
        }
        ProfileService::resetConfigCache();
    }

    // ------------------------------------------------------------------
    // The definitions resolve
    // ------------------------------------------------------------------

    public function test_both_shipped_profiles_are_present(): void {
        $all = ProfileRegistry::all();
        $this->assertArrayHasKey( self::BASICS, $all );
        $this->assertArrayHasKey( 'full', $all );
        $this->assertTrue( ProfileRegistry::exists( self::BASICS ) );
        $this->assertFalse( ProfileRegistry::exists( 'no-such-profile' ) );
        $this->assertNull( ProfileRegistry::get( 'no-such-profile' ) );
    }

    /**
     * `tools/check-module-toggles.php` asserts the same thing structurally.
     * This asserts it against the autoloader, which the gate cannot: a
     * class named correctly in the config and missing from disk resolves
     * in neither, but only this notices.
     */
    public function test_every_named_module_class_loads(): void {
        foreach ( ProfileRegistry::all() as $slug => $profile ) {
            foreach ( array_keys( $profile['modules'] ) as $class ) {
                $this->assertTrue(
                    class_exists( $class ),
                    "Profile {$slug} names module {$class}, which does not load."
                );
            }
            foreach ( array_keys( $profile['features'] ) as $key ) {
                $this->assertTrue(
                    FeatureRegistry::exists( $key ),
                    "Profile {$slug} overrides feature {$key}, which is not catalogued."
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // diff() is a pure read
    // ------------------------------------------------------------------

    public function test_diff_on_a_fresh_install_reports_changes_and_writes_nothing(): void {
        $before = $this->liveState();

        $diff = ProfileService::diff( self::BASICS );
        $this->assertNotEmpty( $diff, 'Basics differs from a default install, so the diff cannot be empty.' );

        foreach ( $diff as $row ) {
            $this->assertNotSame( $row['from'], $row['to'], 'A diff row that changes nothing is noise.' );
            $this->assertContains( $row['kind'], [ 'module', 'feature' ] );
        }

        $this->clearCaches();
        $this->assertSame( $before, $this->liveState(), 'diff() wrote to module or feature state.' );
    }

    public function test_diff_for_an_unknown_profile_is_empty(): void {
        $this->assertSame( [], ProfileService::diff( 'no-such-profile' ) );
        $this->assertSame( 0, ProfileService::divergence( 'no-such-profile' ) );
    }

    public function test_always_on_modules_never_appear_in_a_diff(): void {
        foreach ( array_keys( ProfileRegistry::all() ) as $slug ) {
            foreach ( ProfileService::diff( $slug ) as $row ) {
                if ( $row['kind'] !== 'module' ) continue;
                $this->assertFalse(
                    ModuleRegistry::isAlwaysOn( $row['key'] ),
                    "Profile {$slug} produced a diff row for always-on module {$row['key']}; the preview would promise a change that cannot happen."
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // apply()
    // ------------------------------------------------------------------

    public function test_apply_puts_the_install_on_the_profile_and_zeroes_divergence(): void {
        $this->assertNull( ProfileService::current() );

        $summary = ProfileService::apply( self::BASICS );
        $this->clearCaches();

        $this->assertSame( self::BASICS, $summary['profile'] );
        $this->assertNotEmpty( $summary['applied'] );
        $this->assertSame( self::BASICS, ProfileService::current() );
        $this->assertSame( 0, ProfileService::divergence( self::BASICS ) );
    }

    public function test_apply_honours_exclusions_and_names_what_it_skipped(): void {
        $diff = ProfileService::diff( self::BASICS );
        $applicable = array_values( array_filter(
            $diff,
            static fn( array $r ): bool => $r['skipped_reason'] === null
        ) );
        $this->assertNotEmpty( $applicable );

        $held = $applicable[0];
        $summary = ProfileService::apply( self::BASICS, [ $held['id'] ] );
        $this->clearCaches();

        $skipped_ids = array_column( $summary['skipped'], 'id' );
        $this->assertContains( $held['id'], $skipped_ids );

        $reasons = [];
        foreach ( $summary['skipped'] as $row ) {
            $reasons[ $row['id'] ] = $row['reason'];
        }
        $this->assertSame( 'excluded', $reasons[ $held['id'] ] );

        // The excluded row is the only thing left between the install and
        // the profile, so divergence is exactly one.
        $this->assertSame( 1, ProfileService::divergence( self::BASICS ) );
    }

    public function test_divergence_returns_to_zero_when_a_manual_toggle_is_undone(): void {
        ProfileService::apply( self::BASICS );
        $this->clearCaches();
        $this->assertSame( 0, ProfileService::divergence( self::BASICS ) );

        $profile = ProfileRegistry::get( self::BASICS );
        $this->assertNotNull( $profile );

        // Pick a module the profile switches off and turn it back on by
        // hand — the operator-divergence case.
        $manual = '';
        foreach ( $profile['modules'] as $class => $enabled ) {
            if ( $enabled || ModuleRegistry::isAlwaysOn( $class ) ) continue;
            $manual = $class;
            break;
        }
        $this->assertNotSame( '', $manual );

        ModuleRegistry::setEnabled( $manual, true );
        $this->clearCaches();
        $this->assertSame( 1, ProfileService::divergence( self::BASICS ) );

        ModuleRegistry::setEnabled( $manual, false );
        $this->clearCaches();
        $this->assertSame( 0, ProfileService::divergence( self::BASICS ) );
    }

    public function test_apply_with_every_row_excluded_writes_nothing(): void {
        $ids = array_column( ProfileService::diff( self::BASICS ), 'id' );
        $before = $this->liveState();

        $summary = ProfileService::apply( self::BASICS, $ids );
        $this->clearCaches();

        $this->assertSame( [], $summary['applied'] );
        $this->assertSame( $before, $this->liveState() );
    }

    // ------------------------------------------------------------------
    // The Basics set actually boots
    // ------------------------------------------------------------------

    public function test_basics_module_set_registers_and_boots_without_fatal(): void {
        ProfileService::apply( self::BASICS );
        $this->clearCaches();

        $declared = require TT_PLUGIN_DIR . 'config/modules.php';
        $registry = new ModuleRegistry( new Container() );
        $registry->load( array_keys( $declared ) );

        // WP_UnitTestCase backs up and restores the hook globals around
        // each test, so registering a second time here cannot leak.
        $registry->registerAll();
        $registry->bootAll();

        $this->assertTrue( ModuleRegistry::isEnabled( 'TT\\Modules\\Players\\PlayersModule' ) );
        $this->assertFalse( ModuleRegistry::isEnabled( 'TT\\Modules\\Training\\TrainingModule' ) );
    }

    /**
     * The dependency check the epic asked for. Every tile surface Basics
     * keeps is looked up in the frontend dispatcher, and the classes that
     * dispatcher hands the request to must not live in a module Basics
     * switched off. That is the failure inter-module dependencies being
     * unenforced would produce, and it is silent at runtime.
     */
    public function test_kept_surfaces_do_not_dispatch_into_a_disabled_module(): void {
        $profile = ProfileRegistry::get( self::BASICS );
        $this->assertNotNull( $profile );

        $tiles = TileRegistry::allRegistered();
        if ( $tiles === [] ) {
            $this->markTestSkipped( 'No tiles registered in this bootstrap, so there are no kept surfaces to walk.' );
        }

        $off_roots = $this->disabledNamespaceRoots( $profile['modules'] );
        $cases     = $this->dispatcherCaseBlocks();
        $findings  = [];

        foreach ( $tiles as $tile ) {
            $slug  = (string) ( $tile['view_slug'] ?? $tile['slug'] ?? '' );
            $owner = ltrim( (string) ( $tile['module_class'] ?? '' ), '\\' );
            if ( $slug === '' || ! isset( $cases[ $slug ] ) ) continue;

            // Not a kept surface: Basics switches the owning module, or
            // the feature the tile names, off.
            if ( $owner !== '' && ( $profile['modules'][ $owner ] ?? true ) === false ) continue;
            $feature = (string) ( $tile['feature'] ?? '' );
            if ( $feature !== '' && ( $profile['features'][ $feature ] ?? true ) === false ) continue;

            foreach ( $this->modulesReferencedIn( $cases[ $slug ] ) as $root ) {
                if ( ! isset( $off_roots[ $root ] ) ) continue;
                $findings[] = "?tt_view={$slug} dispatches into TT\\Modules\\{$root}, which Basics switches off.";
            }
        }

        $this->assertSame( [], array_values( array_unique( $findings ) ) );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Namespace roots (`TT\Modules\<Root>`) with no module left enabled
     * under the profile. A root shared by two modules — Players owns both
     * `PlayersModule` and `PlayerStatusModule` — only counts as off when
     * every module under it is off, because a class reference names the
     * namespace and not which module owns it.
     *
     * @param array<string,bool> $modules
     * @return array<string,bool>
     */
    private function disabledNamespaceRoots( array $modules ): array {
        $any_on = [];
        foreach ( $modules as $class => $enabled ) {
            if ( ! preg_match( '/^TT\\\\Modules\\\\([A-Za-z0-9_]+)\\\\/', $class, $m ) ) continue;
            $root = $m[1];
            $any_on[ $root ] = ( $any_on[ $root ] ?? false ) || $enabled;
        }
        return array_filter( $any_on, static fn( bool $on ): bool => ! $on );
    }

    /**
     * The frontend dispatcher's `case '<slug>':` blocks, keyed by slug.
     * Read out of the source rather than executed: running a dispatch
     * would need a logged-in persona per surface, and what is being
     * asserted is a static reference, not a rendered page.
     *
     * @return array<string,string>
     */
    private function dispatcherCaseBlocks(): array {
        $src = (string) file_get_contents(
            TT_PLUGIN_DIR . 'src/Shared/Frontend/DashboardShortcode.php'
        );

        $out = [];
        if ( ! preg_match_all( "/case\s+'([a-z0-9_-]+)'\s*:/", $src, $m, PREG_OFFSET_CAPTURE ) ) {
            return $out;
        }

        $count = count( $m[0] );
        for ( $i = 0; $i < $count; $i++ ) {
            $start = (int) $m[0][ $i ][1];
            $end   = $i + 1 < $count ? (int) $m[0][ $i + 1 ][1] : strlen( $src );
            $slug  = (string) $m[1][ $i ][0];
            // Consecutive `case` labels fall through to one body; append
            // rather than overwrite so a shared body is checked for each.
            $out[ $slug ] = ( $out[ $slug ] ?? '' ) . substr( $src, $start, $end - $start );
        }
        return $out;
    }

    /**
     * Namespace roots referenced by fully-qualified `TT\Modules\X\…`
     * class names inside a block.
     *
     * @return list<string>
     */
    private function modulesReferencedIn( string $block ): array {
        if ( ! preg_match_all( '/TT\\\\+Modules\\\\+([A-Za-z0-9_]+)\\\\+/', $block, $m ) ) return [];
        return array_values( array_unique( $m[1] ) );
    }

    /**
     * Live module + feature state, as a comparable snapshot.
     *
     * @return array<string,bool>
     */
    private function liveState(): array {
        $out = [];
        foreach ( ModuleRegistry::allWithState() as $row ) {
            $out[ 'module:' . $row['class'] ] = $row['enabled'];
        }
        foreach ( ProfileRegistry::all() as $profile ) {
            foreach ( array_keys( $profile['features'] ) as $key ) {
                $out[ 'feature:' . $key ] = FeatureRegistry::configuredState( $key );
            }
        }
        ksort( $out );
        return $out;
    }
}
