<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\Config\ConfigService;
use TT\Shared\Modules\ProfileService;

/**
 * #3039 — release-time drift.
 *
 * The one genuinely hard part of this child is telling "the operator
 * turned this off" apart from "the profile turned this on". Divergence
 * is computed live and cannot answer it; the confirmation watermark can,
 * and this suite covers **both** directions, because getting one right
 * and the other wrong is the bug this child would have if it had one.
 */
final class InstallProfileDriftTest extends WP_UnitTestCase {

    private const BASICS   = 'basics';
    private const TRAINING = 'TT\\Modules\\Training\\TrainingModule';
    private const ROW      = 'module:TT\\Modules\\Training\\TrainingModule';

    public function set_up(): void {
        parent::set_up();
        global $wpdb; $wpdb->hide_errors();
        $this->clearCaches();
    }

    public function tear_down(): void {
        $this->clearCaches();
        parent::tear_down();
    }

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

    /**
     * Rewrite the watermark by hand. A release changing what a profile
     * includes cannot be simulated from a test — `config/profiles.php` is
     * shipped code — but the watermark is exactly the record of "what the
     * profile intended when the operator last looked", so writing a
     * different intent into it is the same situation from `pending()`'s
     * point of view.
     *
     * @param array<string,bool> $rows
     */
    private function setWatermark( string $profile, array $rows ): void {
        $json = wp_json_encode( [ 'profile' => $profile, 'rows' => $rows ] );
        ( new ConfigService() )->set( ProfileService::SEEN_KEY, is_string( $json ) ? $json : '' );
        ProfileService::resetConfigCache();
    }

    /** @return array<string,bool> */
    private function watermarkRows(): array {
        $raw = ( new ConfigService() )->get( ProfileService::SEEN_KEY, '' );
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];
        $out = [];
        foreach ( (array) ( $decoded['rows'] ?? [] ) as $id => $to ) {
            $out[ (string) $id ] = (bool) $to;
        }
        return $out;
    }

    /** Applies Basics and leaves the install matching it exactly. */
    private function onBasics(): void {
        ProfileService::apply( self::BASICS );
        $this->clearCaches();
    }

    // ------------------------------------------------------------------
    // Nothing to say
    // ------------------------------------------------------------------

    public function test_an_install_on_no_profile_sees_nothing(): void {
        $this->assertNull( ProfileService::current() );
        $this->assertSame( [], ProfileService::pending() );
    }

    public function test_a_clean_apply_leaves_nothing_pending(): void {
        $this->onBasics();

        $this->assertSame( self::BASICS, ProfileService::current() );
        $this->assertSame( 0, ProfileService::divergence( self::BASICS ) );
        $this->assertSame( [], ProfileService::pending() );
    }

    public function test_a_watermark_for_another_profile_is_not_interpreted(): void {
        $this->onBasics();
        ModuleRegistry::setEnabled( self::TRAINING, true );
        $this->setWatermark( 'full', [] );
        $this->clearCaches();

        // There IS divergence, but the watermark does not describe this
        // shape, so nothing can be classified and nothing is raised.
        $this->assertGreaterThan( 0, ProfileService::divergence( self::BASICS ) );
        $this->assertSame( [], ProfileService::pending() );
    }

    // ------------------------------------------------------------------
    // Direction one: the operator moved a switch
    // ------------------------------------------------------------------

    public function test_an_operator_toggle_is_divergence_and_not_a_profile_change(): void {
        $this->onBasics();

        ModuleRegistry::setEnabled( self::TRAINING, true );
        $this->clearCaches();

        $this->assertSame( 1, ProfileService::divergence( self::BASICS ) );
        $this->assertSame(
            [],
            ProfileService::pending(),
            'A row the operator has already seen and moved themselves is their divergence, not the profile changing.'
        );
    }

    // ------------------------------------------------------------------
    // Direction two: the profile definition moved
    // ------------------------------------------------------------------

    public function test_a_changed_profile_intent_is_reported_as_pending(): void {
        $this->onBasics();

        ModuleRegistry::setEnabled( self::TRAINING, true );
        // As if the profile had said "on" when the operator last looked,
        // and today says "off".
        $this->setWatermark( self::BASICS, [ self::ROW => true ] );
        $this->clearCaches();

        $ids = array_column( ProfileService::pending(), 'id' );
        $this->assertContains( self::ROW, $ids );
    }

    public function test_a_row_absent_from_the_watermark_is_pending(): void {
        $this->onBasics();

        ModuleRegistry::setEnabled( self::TRAINING, true );
        // A module that did not exist when the operator last looked.
        $this->setWatermark( self::BASICS, [] );
        $this->clearCaches();

        $ids = array_column( ProfileService::pending(), 'id' );
        $this->assertContains( self::ROW, $ids );
    }

    // ------------------------------------------------------------------
    // Dismissing
    // ------------------------------------------------------------------

    public function test_dismissing_stops_the_notice_without_applying_anything(): void {
        $this->onBasics();

        ModuleRegistry::setEnabled( self::TRAINING, true );
        $this->setWatermark( self::BASICS, [] );
        $this->clearCaches();
        $this->assertNotSame( [], ProfileService::pending() );

        ProfileService::dismiss( [ self::ROW ] );
        $this->clearCaches();

        $this->assertSame( [], ProfileService::pending(), 'A dismissed row must not nag on every page load.' );
        $this->assertTrue(
            ModuleRegistry::isEnabled( self::TRAINING ),
            'Dismissing is not applying — the module stays exactly as the operator left it.'
        );
        // The divergence is still real and still counted; only the notice
        // is gone.
        $this->assertSame( 1, ProfileService::divergence( self::BASICS ) );
    }

    public function test_a_dismissed_row_joins_the_watermark_with_the_current_intent(): void {
        $this->onBasics();

        ModuleRegistry::setEnabled( self::TRAINING, true );
        $this->setWatermark( self::BASICS, [] );
        $this->clearCaches();

        ProfileService::dismiss( [ self::ROW ] );
        $this->clearCaches();

        $rows = $this->watermarkRows();
        $this->assertArrayHasKey( self::ROW, $rows );
        // Basics wants Training off; that is the intent now on record, so
        // a later release changing its mind raises the row again.
        $this->assertFalse( $rows[ self::ROW ] );
    }

    public function test_dismissing_on_an_install_with_no_profile_does_nothing(): void {
        ProfileService::dismiss( [ self::ROW ] );
        $this->clearCaches();

        $this->assertSame( '', ( new ConfigService() )->get( ProfileService::SEEN_KEY, '' ) );
    }

    // ------------------------------------------------------------------
    // No cron
    // ------------------------------------------------------------------

    /**
     * Detection is a comparison on a page an admin is already looking at.
     * A scheduled event would be a second place for this to be decided,
     * and a release happens with nobody present to see the result.
     */
    public function test_the_profile_surfaces_register_no_scheduled_event(): void {
        $files = [
            'src/Shared/Modules/ProfileService.php',
            'src/Shared/Modules/ProfileRegistry.php',
            'src/Shared/Frontend/FrontendInstallProfileView.php',
        ];
        foreach ( $files as $rel ) {
            $src = (string) file_get_contents( TT_PLUGIN_DIR . $rel );
            foreach ( [ 'wp_schedule_event', 'wp_schedule_single_event', 'wp_cron' ] as $needle ) {
                $this->assertStringNotContainsString(
                    $needle,
                    $src,
                    "{$rel} registers scheduled work; drift detection is a page-load comparison."
                );
            }
        }
    }
}
