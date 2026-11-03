<?php
namespace TT\Tests\Php;

use TT\Core\Kernel;
use TT\Shared\Frontend\BrandStyles;
use WP_UnitTestCase;

/**
 * #4829 — the Branding "Focus ring color" field colours the focus ring again.
 *
 * Since #4804 the ring followed the primary, and the field did nothing. A
 * focus colour the academy set now feeds `--tt-focus-color`, as long as it
 * reaches 3:1 against the page. A fainter one is saved with a warning, and
 * the ring keeps following the primary so keyboard focus never disappears.
 */
final class FocusColourFieldTest extends WP_UnitTestCase {

    private const KEYS = [ 'primary_color', 'color_focus' ];

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

    public function test_a_visible_focus_colour_colours_the_ring(): void {
        $this->cfg()->set( 'primary_color', '#7a1fa2' );
        $this->cfg()->set( 'color_focus', '#6200ea' );

        $this->assertStringContainsString( '--tt-focus-color:#6200ea', BrandStyles::css() );
    }

    public function test_the_focus_colour_wins_over_the_primary_shade(): void {
        // A light primary would get a darker shade; the academy's own
        // focus colour replaces it, once.
        $this->cfg()->set( 'primary_color', '#ffd54f' );
        $this->cfg()->set( 'color_focus', '#6200ea' );

        $css = BrandStyles::css();
        $this->assertSame( 1, substr_count( $css, '--tt-focus-color:' ) );
        $this->assertStringContainsString( '--tt-focus-color:#6200ea', $css );
    }

    public function test_a_faint_focus_colour_leaves_the_ring_on_the_primary(): void {
        $this->cfg()->set( 'primary_color', '#7a1fa2' );
        $this->cfg()->set( 'color_focus', '#ffeb3b' );
        $this->assertStringNotContainsString( '--tt-focus-color', BrandStyles::css(), 'a dark primary needs no shade' );
        $this->assertStringNotContainsString( '--tt-focus-ring:#ffeb3b', BrandStyles::css(), 'the eval form draws from the ring token' );

        $this->cfg()->set( 'primary_color', '#ffd54f' );
        $css = BrandStyles::css();
        $this->assertStringNotContainsString( '--tt-focus-color:#ffeb3b', $css );
        $this->assertStringContainsString( '--tt-focus-color:#' . ltrim( BrandStyles::focusColor( '#ffd54f' ), '#' ), $css, 'the primary shade' );
    }

    public function test_an_unset_focus_colour_follows_the_primary(): void {
        $this->cfg()->set( 'primary_color', '#7a1fa2' );

        $this->assertStringNotContainsString( '--tt-focus-color', BrandStyles::css() );
    }

    /**
     * `#1e88e5` is the field's form default, posted on every Branding save,
     * so it says nothing about what the academy wanted: the ring keeps
     * following the primary. A different colour still applies.
     */
    public function test_the_form_default_counts_as_unset(): void {
        $this->cfg()->set( 'primary_color', '#7a1fa2' );
        foreach ( [ '#1e88e5', '#1E88E5', ' #1e88e5 ' ] as $value ) {
            $this->cfg()->set( 'color_focus', $value );
            $css = BrandStyles::css();
            $this->assertStringNotContainsString( '--tt-focus-color', $css, $value );
            $this->assertStringNotContainsString( '--tt-focus-ring', $css, $value );
        }

        $this->cfg()->set( 'primary_color', '#ffd54f' );
        $this->cfg()->set( 'color_focus', '#1e88e5' );
        $this->assertStringContainsString( '--tt-focus-color:' . BrandStyles::focusColor( '#ffd54f' ), BrandStyles::css(), 'the primary shade, not the form default' );

        $this->cfg()->set( 'color_focus', '#1e88e6' );
        $this->assertStringContainsString( '--tt-focus-color:#1e88e6', BrandStyles::css() );
    }

    public function test_visibility_is_three_to_one_against_the_page(): void {
        $this->assertTrue( BrandStyles::isVisibleFocusColor( '#6200ea' ) );
        $this->assertTrue( BrandStyles::isVisibleFocusColor( '#1e88e5' ) );
        $this->assertFalse( BrandStyles::isVisibleFocusColor( '#ffeb3b' ) );
        $this->assertFalse( BrandStyles::isVisibleFocusColor( 'not a colour' ) );
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function saves(): array {
        return [
            'faint colour warns'    => [ '#ffeb3b', true ],
            'visible colour silent' => [ '#6200ea', false ],
        ];
    }

    /**
     * @dataProvider saves
     */
    public function test_saving_a_faint_focus_colour_warns_but_saves( string $value, bool $warns ): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $request = new \WP_REST_Request( 'POST', '/talenttrack/v1/config' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [ 'config' => [ 'color_focus' => $value ] ] ) );
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertTrue( $data['success'] );
        $this->assertSame( 1, $data['data']['keys_written'], 'the value is saved either way' );
        $this->assertSame( $value, \TT\Infrastructure\Query\QueryHelpers::get_config( 'color_focus', '' ) );

        $fields = array_column( $data['data']['warnings'], 'field' );
        $this->assertSame( $warns, in_array( 'color_focus', $fields, true ) );
    }
}
