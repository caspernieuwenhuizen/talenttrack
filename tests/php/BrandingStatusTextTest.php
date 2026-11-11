<?php
namespace TT\Tests\Php;

use TT\Core\Kernel;
use TT\Shared\Frontend\BrandStyles;
use WP_UnitTestCase;

/**
 * #5108 — a status colour printed as text reads at 4.5:1.
 *
 * The status hues are fills. Text reads `--tt-<status>-text`, which
 * tokens.css sets from the status ink and Branding feeds, through
 * `--tt-<status>-text-color`, from a chosen status colour however light.
 * No stylesheet prints a bare status colour as text.
 */
final class BrandingStatusTextTest extends WP_UnitTestCase {

    private const KEYS = [
        'color_success' => 'success',
        'color_warning' => 'warning',
        'color_danger'  => 'danger',
        'color_info'    => 'info',
    ];

    /** @var array<string, string> */
    private $saved = [];

    private function cfg(): \TT\Infrastructure\Config\ConfigService {
        return Kernel::instance()->container()->get( 'config' );
    }

    public function setUp(): void {
        parent::setUp();
        foreach ( array_keys( self::KEYS ) as $key ) {
            $this->saved[ $key ] = (string) $this->cfg()->get( $key, '' );
            $this->cfg()->set( $key, '' );
        }
    }

    public function tearDown(): void {
        foreach ( $this->saved as $key => $value ) {
            $this->cfg()->set( $key, $value );
        }
        parent::tearDown();
    }

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgb( string $hex ): array {
        return [ (int) hexdec( substr( $hex, 1, 2 ) ), (int) hexdec( substr( $hex, 3, 2 ) ), (int) hexdec( substr( $hex, 5, 2 ) ) ];
    }

    private function tokensSubtle( string $status ): string {
        $css = (string) file_get_contents( $this->root() . '/assets/css/tokens.css' );
        $this->assertSame( 1, preg_match( '/--tt-' . $status . '-subtle:\s*(#[0-9a-f]{6})\b/', $css, $m ), $status );
        return $m[1];
    }

    public function test_the_subtle_fills_match_tokens_css(): void {
        $map = ( new \ReflectionClassConstant( BrandStyles::class, 'STATUS_SUBTLE' ) )->getValue();
        foreach ( self::KEYS as $key => $status ) {
            $this->assertSame( $this->tokensSubtle( $status ), $map[ $key ], $status );
        }
    }

    public function test_a_light_status_colour_gets_a_shade_that_reads(): void {
        foreach ( self::KEYS as $key => $status ) {
            $backgrounds = [ '#ffffff', '#faf8f3', '#f4f5f7', $this->tokensSubtle( $status ) ];
            foreach ( [ '#ffd400', '#7cff7c', '#ff9a9a', '#9ad0ff', '#ffffff', '#e8902b' ] as $colour ) {
                $text = BrandStyles::statusTextColor( $key, $colour );
                foreach ( $backgrounds as $bg ) {
                    $ratio = BrandStyles::contrast( $this->rgb( $text ), $this->rgb( $bg ) );
                    $this->assertGreaterThanOrEqual( 4.5, $ratio, sprintf( '%s %s -> %s on %s is %.2f:1', $status, $colour, $text, $bg, $ratio ) );
                }
            }
        }
    }

    public function test_a_dark_status_colour_is_used_as_it_is(): void {
        $this->assertSame( '#a32b22', BrandStyles::statusTextColor( 'color_danger', '#a32b22' ) );
        $this->assertSame( '', BrandStyles::statusTextColor( 'color_danger', 'not-a-colour' ) );
        $this->assertSame( '', BrandStyles::statusTextColor( 'primary_color', '#a32b22' ) );
    }

    public function test_branding_emits_the_text_input_with_the_status_colour(): void {
        $this->cfg()->set( 'color_warning', '#ffd400' );
        $css = BrandStyles::css();

        $this->assertStringContainsString( '--tt-warning:#ffd400', $css );
        $this->assertStringContainsString( '--tt-warning-text-color:' . BrandStyles::statusTextColor( 'color_warning', '#ffd400' ), $css );
        $this->assertStringNotContainsString( '--tt-danger-text-color', $css, 'only the chosen status' );
    }

    public function test_tokens_css_points_each_text_token_at_its_input_then_the_ink(): void {
        $css = (string) file_get_contents( $this->root() . '/assets/css/tokens.css' );
        foreach ( self::KEYS as $status ) {
            $this->assertMatchesRegularExpression(
                '/--tt-' . $status . '-text:\s*var\(--tt-' . $status . '-text-color,\s*var\(--tt-' . $status . '-ink\)\)/',
                $css,
                $status
            );
        }
    }

    /**
     * Every `color:` in a sheet that reaches a status colour without its
     * `-text` variant: directly (`var(--tt-warning)`), or through a custom
     * property the sheet defines as one (`--tt-warn: var(--tt-warning)`),
     * including an alias of an alias.
     *
     * @return list<string> the property each offending `color:` reads first
     */
    private function bareStatusText( string $css ): array {
        $css     = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
        $names   = [ '--tt-success', '--tt-warning', '--tt-danger', '--tt-info' ];
        $aliases = $names;
        do {
            $count = count( $aliases );
            $alt   = implode( '|', array_map( static fn( string $n ): string => preg_quote( $n, '/' ), $aliases ) );
            if ( preg_match_all( '/(--[a-z0-9-]+)\s*:\s*var\(\s*(?:' . $alt . ')\s*[,)]/', $css, $m ) ) {
                $aliases = array_values( array_unique( array_merge( $aliases, $m[1] ) ) );
            }
        } while ( count( $aliases ) !== $count );

        $alt = implode( '|', array_map( static fn( string $n ): string => preg_quote( $n, '/' ), $aliases ) );
        preg_match_all( '/(?:^|[;{\s])color:\s*var\(\s*(' . $alt . ')\s*[,)]/m', $css, $hits );
        return $hits[1];
    }

    public function test_the_guard_sees_a_status_colour_through_a_local_alias(): void {
        $planted = '.tt-x { --tt-warn: var(--tt-warning, #e8902b); --tt-warn-2: var(--tt-warn); }'
            . ' .tt-a { color: var(--tt-warn, #c75c1f); }'
            . ' .tt-b { color: var(--tt-warn-2); }'
            . ' .tt-c { border-left: 3px solid var(--tt-warn); background: var(--tt-warn); }'
            . ' .tt-d { color: var(--tt-warning-text, var(--tt-warn, #c75c1f)); }'
            . ' .tt-e { color: var(--tt-danger); }';

        $this->assertSame( [ '--tt-warn', '--tt-warn-2', '--tt-danger' ], $this->bareStatusText( $planted ) );
    }

    public function test_no_stylesheet_prints_a_bare_status_colour_as_text(): void {
        $offenders = [];
        $files     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root() . '/assets/css' ) );
        foreach ( $files as $file ) {
            $path = str_replace( '\\', '/', (string) $file );
            if ( substr( $path, -4 ) !== '.css' ) continue;
            $name = basename( $path );
            if ( $name === 'tokens.css' || strpos( $name, 'theme-' ) === 0 ) continue;
            $hits = $this->bareStatusText( (string) file_get_contents( $path ) );
            if ( $hits ) {
                $offenders[] = $name . ' (' . implode( ', ', array_unique( $hits ) ) . ')';
            }
        }
        $this->assertSame( [], $offenders, 'read --tt-<status>-text for text, also behind a local alias' );
    }
}
