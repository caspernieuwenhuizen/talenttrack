<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Shared\Frontend\FrontendInstallProfileView;
use TT\Shared\Modules\ProfileService;

/**
 * #3037 — the preview screen's one piece of decision-making.
 *
 * Everything else on the screen is composition. The exclusion derivation
 * is not: it decides what a submitted form actually writes, and getting
 * it wrong the obvious way — trusting a submitted exclude list — means a
 * POST that simply omits the field applies every change the operator
 * unticked.
 */
final class InstallProfilePreviewTest extends WP_UnitTestCase {

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

    /** Nothing ticked means nothing applied — not everything applied. */
    public function test_an_empty_submission_excludes_every_row(): void {
        $ids = array_column( ProfileService::diff( self::BASICS ), 'id' );
        $this->assertNotEmpty( $ids );

        $this->assertSame(
            $ids,
            FrontendInstallProfileView::exclusionsFrom( self::BASICS, [] )
        );
    }

    /** Everything ticked means nothing held back. */
    public function test_a_full_submission_excludes_nothing(): void {
        $ids = array_column( ProfileService::diff( self::BASICS ), 'id' );

        $this->assertSame(
            [],
            FrontendInstallProfileView::exclusionsFrom( self::BASICS, $ids )
        );
    }

    /** One unticked row is one exclusion, and only that one. */
    public function test_one_unticked_row_is_the_only_exclusion(): void {
        $ids = array_column( ProfileService::diff( self::BASICS ), 'id' );
        $this->assertGreaterThan( 1, count( $ids ) );

        $held   = array_shift( $ids );
        $result = FrontendInstallProfileView::exclusionsFrom( self::BASICS, $ids );

        $this->assertSame( [ $held ], $result );
    }

    /**
     * A ticked id that is not in the diff cannot conjure a change. The
     * derivation walks the diff, so an invented id is simply absent.
     */
    public function test_an_unknown_ticked_id_changes_nothing(): void {
        $ids = array_column( ProfileService::diff( self::BASICS ), 'id' );

        $this->assertSame(
            FrontendInstallProfileView::exclusionsFrom( self::BASICS, $ids ),
            FrontendInstallProfileView::exclusionsFrom(
                self::BASICS,
                array_merge( $ids, [ 'module:TT\\Modules\\Nope\\NopeModule' ] )
            )
        );
    }

    public function test_an_unknown_profile_yields_no_exclusions(): void {
        $this->assertSame(
            [],
            FrontendInstallProfileView::exclusionsFrom( 'no-such-profile', [] )
        );
    }
}
