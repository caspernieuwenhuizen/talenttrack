<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Frontend\FrontendExploreView;
use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;

/**
 * #4845 — the explorer's way back is the breadcrumb.
 *
 * The view printed its own "← Back" link beside the KPI title: a third
 * navigation affordance next to the breadcrumb chain and the `tt_back` pill,
 * which CLAUDE.md §5a forbids. The chain's Analytics crumb is the way back.
 */
final class ExploreBackLinkTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );
    }

    public function tear_down(): void {
        $_GET = [];
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_explorer_prints_no_back_link_of_its_own(): void {
        $kpis = KpiRegistry::all();
        $this->assertNotEmpty( $kpis, 'fixture: no KPI registered' );
        $kpi = reset( $kpis );

        $admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );
        $_GET = [ 'tt_view' => 'explore', 'kpi' => $kpi->key ];

        ob_start();
        FrontendExploreView::render( $admin, true );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'tt-explore-head', $html, 'the explorer rendered for the KPI' );

        $this->assertSame( 1, preg_match( '#<nav class="tt-breadcrumbs"[^>]*>(.*?)</nav>#s', $html, $m ), 'the chain renders' );
        $this->assertMatchesRegularExpression( '#<a href="[^"]*tt_view=analytics[^"]*">Analytics</a>#', $m[1], 'the Analytics crumb leads back' );

        $outside = (string) preg_replace( '#<nav class="tt-breadcrumbs".*?</nav>#s', '', $html );
        $this->assertStringNotContainsString( '← Back', $outside );
        $this->assertStringNotContainsString( '&larr;', $outside );
    }
}
