<?php
namespace TT\Tests\Php;

use TT\Core\ModuleRegistry;
use TT\Modules\CustomCss\CustomCssEnqueue;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Frontend\TokenRoot;
use TT\Shared\Frontend\Tt404Handler;
use WP_UnitTestCase;

/**
 * #4772 — the design tokens do not depend on the custom CSS module or on
 * the theme calling `body_class()`.
 *
 * `tokens.css` declares every token on `.tt-root`. That class used to come
 * only from the custom CSS module's `body_class` filter. The shell now wraps
 * its own output in `.tt-root`, directly around `.tt-dashboard`, and the
 * body class is added from core.
 */
final class TokenRootTest extends WP_UnitTestCase {

    private const CUSTOM_CSS = 'TT\\Modules\\CustomCss\\CustomCssModule';

    /** @var array<string, mixed> */
    private $get = [];

    public function setUp(): void {
        parent::setUp();
        $this->get = $_GET;
    }

    public function tearDown(): void {
        $_GET = $this->get;
        ModuleRegistry::setEnabled( self::CUSTOM_CSS, true );
        parent::tearDown();
    }

    public function test_the_body_class_is_added_by_core_not_by_the_custom_css_module(): void {
        $this->assertNotFalse( has_filter( 'body_class', [ TokenRoot::class, 'addBodyClass' ] ) );
        $this->assertNotFalse( has_filter( 'admin_body_class', [ TokenRoot::class, 'addAdminBodyClass' ] ) );
        $this->assertFalse( method_exists( CustomCssEnqueue::class, 'addBodyClass' ) );

        $this->assertSame( [ 'home', 'tt-root' ], TokenRoot::addBodyClass( [ 'home' ] ) );
        $this->assertSame( [ 'tt-root' ], TokenRoot::addBodyClass( [ 'tt-root' ] ), 'added once' );
        $this->assertSame( 'wp-admin tt-root', TokenRoot::addAdminBodyClass( 'wp-admin' ) );
    }

    /**
     * The shell's own wrapper, with the custom CSS module switched off and
     * no `body_class` filter at all (a theme that never calls it).
     */
    public function test_the_dashboard_sits_inside_a_tt_root_wrapper_with_the_module_off(): void {
        ModuleRegistry::setEnabled( self::CUSTOM_CSS, false );
        remove_all_filters( 'body_class' );

        $_GET = [ 'tt_view' => 'accept-invite' ];
        $html = DashboardShortcode::render();

        $this->assertStringStartsWith( '<div class="tt-root"><div class="tt-dashboard">', $html );
        $this->assertStringEndsWith( '</div></div>', $html );
        $this->assertTrue( $this->dashboardHasTtRootAncestor( $html ) );
    }

    public function test_the_login_form_sits_inside_the_wrapper_too(): void {
        wp_set_current_user( 0 );
        $_GET = [];

        $html = DashboardShortcode::render();

        $this->assertStringStartsWith( '<div class="tt-root">', $html );
    }

    /**
     * The standalone 404 used to carry the class on `.tt-dashboard` itself,
     * where a `.tt-root .tt-dashboard` rule could not match it.
     */
    public function test_the_standalone_404_uses_the_same_wrapper(): void {
        ob_start();
        Tt404Handler::renderContent();
        $html = (string) ob_get_clean();

        $this->assertStringStartsWith( '<div class="tt-root"><div class="tt-404 tt-dashboard">', $html );
        $this->assertTrue( $this->dashboardHasTtRootAncestor( $html ) );
    }

    private function dashboardHasTtRootAncestor( string $html ): bool {
        $doc = new \DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>' );
        libxml_clear_errors();

        $xpath = new \DOMXPath( $doc );
        $found = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tt-dashboard ')]"
            . "[ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' tt-root ')]]"
        );

        return $found !== false && $found->length > 0;
    }
}
