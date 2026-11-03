<?php
namespace TT\Tests\Php;

use TT\Core\Kernel;
use TT\Shared\Frontend\BrandStyles;
use WP_UnitTestCase;

/**
 * #4797 — Branding colours win on the default design; one set of defaults.
 *
 * Branding used to print on `:root`, and `frontend-admin.css` redeclared a
 * second set of defaults on `.tt-dashboard`, so inside the dashboard only
 * the accent and the focus ring ever followed it. Now `tokens.css` holds
 * the only defaults, and Branding is attached to `tokens.css` on
 * `.tt-root`, printed after the defaults, for the colours the academy
 * actually chose.
 */
final class BrandingDefaultDesignTest extends WP_UnitTestCase {

    private const KEYS = [
        'primary_color', 'secondary_color', 'color_accent', 'color_danger',
        'color_warning', 'color_success', 'color_info', 'color_focus',
        'font_display', 'font_body',
    ];

    /** @var array<string, string> */
    private $saved = [];

    private function cfg(): \TT\Infrastructure\Config\ConfigService {
        return Kernel::instance()->container()->get( 'config' );
    }

    public function setUp(): void {
        parent::setUp();
        foreach ( self::KEYS as $key ) {
            $this->saved[ $key ] = $this->cfg()->get( $key, '' );
            $this->cfg()->set( $key, '' );
        }
    }

    public function tearDown(): void {
        foreach ( $this->saved as $key => $value ) {
            $this->cfg()->set( $key, $value );
        }
        parent::tearDown();
    }

    private function css( string $file ): string {
        return (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/' . $file );
    }

    public function test_a_colour_counts_as_chosen_only_when_it_says_something(): void {
        $this->assertFalse( BrandStyles::isChosen( 'color_danger', '' ) );
        $this->assertFalse( BrandStyles::isChosen( 'color_danger', '#B32D2E' ), 'the form default' );
        $this->assertFalse( BrandStyles::isChosen( 'primary_color', '#0b3d2e' ), 'the form default' );
        $this->assertFalse( BrandStyles::isChosen( 'color_info', '#000000' ), 'stored black for a status colour' );
        $this->assertFalse( BrandStyles::isChosen( 'color_success', '#000000' ) );

        $this->assertTrue( BrandStyles::isChosen( 'color_danger', '#e91e63' ) );
        $this->assertTrue( BrandStyles::isChosen( 'primary_color', '#7a1fa2' ) );
        $this->assertTrue( BrandStyles::isChosen( 'color_accent', '#000000' ), 'black is a choice outside the status colours' );
    }

    /**
     * The accent has no default apart from the form's, so its `#1e88e5` is
     * what a saved install renders today: it counts as chosen and reaches
     * the rule. The focus ring follows the primary when nothing was chosen
     * (#4804), so its form default counts as unset (#4829).
     */
    public function test_the_accent_keeps_its_form_default_and_the_focus_ring_does_not(): void {
        $this->assertTrue( BrandStyles::isChosen( 'color_accent', '#1e88e5' ) );
        $this->assertFalse( BrandStyles::isChosen( 'color_focus', '#1e88e5' ) );

        $this->cfg()->set( 'color_accent', '#1e88e5' );
        $this->cfg()->set( 'color_focus', '#1E88E5' );
        $css = BrandStyles::css();

        $this->assertStringContainsString( '--tt-accent:#1e88e5', $css );
        $this->assertStringNotContainsString( '--tt-focus-ring', $css );
    }

    public function test_only_chosen_colours_reach_the_rule(): void {
        $this->cfg()->set( 'primary_color', '#7a1fa2' );
        $this->cfg()->set( 'color_info', '#0277bd' );
        $this->cfg()->set( 'color_danger', '#000000' );
        $this->cfg()->set( 'color_success', '#00a32a' );

        $css = BrandStyles::css();

        $this->assertStringStartsWith( '.tt-root{', $css );
        $this->assertStringContainsString( '--tt-primary:#7a1fa2', $css );
        $this->assertStringContainsString( '--tt-primary-rgb:122,31,162', $css );
        $this->assertStringContainsString( '--tt-info:#0277bd', $css );
        $this->assertStringNotContainsString( '--tt-danger', $css, 'stored black falls back to tokens.css' );
        $this->assertStringNotContainsString( '--tt-success', $css, 'the form default falls back to tokens.css' );
        $this->assertStringNotContainsString( '--tt-secondary', $css, 'nothing stored' );
    }

    public function test_nothing_chosen_means_no_rule(): void {
        $this->cfg()->set( 'color_danger', '#000000' );
        $this->cfg()->set( 'primary_color', '#0B3D2E' );

        $this->assertSame( '', BrandStyles::css() );
    }

    public function test_the_rule_is_attached_to_the_tokens_stylesheet(): void {
        $this->cfg()->set( 'secondary_color', '#ff6f00' );

        BrandStyles::attachVars();

        $after = wp_styles()->get_data( 'tt-tokens', 'after' );
        $this->assertIsArray( $after );
        $this->assertStringContainsString( '--tt-secondary:#ff6f00', implode( "\n", $after ) );
    }

    public function test_frontend_admin_declares_no_token(): void {
        $this->assertDoesNotMatchRegularExpression( '/^\s*--tt-[a-z0-9-]+\s*:/m', $this->css( 'frontend-admin.css' ) );
    }

    /**
     * The one set of defaults, with the values the decision named: the
     * status colours from tokens.css, every other conflicting token at the
     * value the dashboard showed, and the tokens that only frontend-admin.css
     * declared moved in.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function defaults(): array {
        return [
            'danger'       => [ '--tt-danger', '#d8453b' ],
            'warning'      => [ '--tt-warning', '#e8902b' ],
            'success'      => [ '--tt-success', '#2f9e5e' ],
            'info'         => [ '--tt-info', '#2d6fb3' ],
            'line'         => [ '--tt-line', '#e3e1d8' ],
            'bg-soft'      => [ '--tt-bg-soft', '#faf8f3' ],
            'sp-5'         => [ '--tt-sp-5', '24px' ],
            'sp-6'         => [ '--tt-sp-6', '32px' ],
            'fs-sm'        => [ '--tt-fs-sm', '0.875rem' ],
            'primary'      => [ '--tt-primary', '#0b3d2e' ],
            'primary-ink'  => [ '--tt-primary-ink', '#0a5c1a' ],
            'secondary'    => [ '--tt-secondary', '#e8b624' ],
            'danger-soft'  => [ '--tt-danger-soft', '#fbebeb' ],
            'sp-7'         => [ '--tt-sp-7', '48px' ],
            'r-md'         => [ '--tt-r-md', '6px' ],
            'fs-xs'        => [ '--tt-fs-xs', '0.75rem' ],
            'fs-xl'        => [ '--tt-fs-xl', '1.5rem' ],
        ];
    }

    /**
     * @dataProvider defaults
     */
    public function test_tokens_css_holds_the_default( string $token, string $value ): void {
        $this->assertMatchesRegularExpression(
            '/^\s*' . preg_quote( $token, '/' ) . '\s*:\s*' . preg_quote( $value, '/' ) . '\s*;/m',
            $this->css( 'tokens.css' )
        );
    }
}
