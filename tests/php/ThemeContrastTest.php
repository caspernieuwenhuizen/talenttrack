<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #5089 — every theme clears the contrast floors of CLAUDE.md §2.
 *
 * Body ink at 4.5:1 on paper and on the soft background, white on the
 * primary button at 4.5:1, the focus ring at 3:1 on paper, and the app
 * shell's active marker at 3:1 on the rail it sits on. A theme inherits
 * any token it does not set from tokens.css, and a new theme-*.css is
 * picked up by glob.
 */
final class ThemeContrastTest extends WP_UnitTestCase {

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    /** @return array<string, string> token => hex, first declaration wins */
    private function tokens( string $css ): array {
        $out = [];
        if ( preg_match_all( '/(--tt-[a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{6})\b/', $css, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $row ) {
                if ( ! isset( $out[ $row[1] ] ) ) $out[ $row[1] ] = strtolower( $row[2] );
            }
        }
        return $out;
    }

    private function luminance( string $hex ): float {
        $channels = [];
        foreach ( [ 1, 3, 5 ] as $i ) {
            $c          = hexdec( substr( $hex, $i, 2 ) ) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function contrast( string $a, string $b ): float {
        $la = $this->luminance( $a );
        $lb = $this->luminance( $b );
        return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
    }

    /**
     * A colour value as written in a rule: a hex, or a var() resolved against the tokens.
     *
     * @param array<string, string> $t
     */
    private function resolve( string $value, array $t ): ?string {
        $value = trim( $value );
        if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
            return strtolower( $value );
        }
        if ( preg_match( '/^var\((--tt-[a-z0-9-]+)\)$/', $value, $m ) ) {
            return $t[ $m[1] ] ?? null;
        }
        return null;
    }

    /** @return array<string, array{tokens: array<string, string>, css: string}> */
    private function palettes(): array {
        $base     = $this->tokens( (string) file_get_contents( $this->root() . '/assets/css/tokens.css' ) );
        $palettes = [ 'default' => [ 'tokens' => $base, 'css' => '' ] ];
        foreach ( glob( $this->root() . '/assets/css/theme-*.css' ) ?: [] as $path ) {
            $css = (string) file_get_contents( $path );
            $palettes[ basename( $path, '.css' ) ] = [
                'tokens' => array_merge( $base, $this->tokens( $css ) ),
                'css'    => $css,
            ];
        }
        return $palettes;
    }

    private function assertRatio( float $floor, string $fg, string $bg, string $what ): void {
        $ratio = $this->contrast( $fg, $bg );
        $this->assertGreaterThanOrEqual( $floor, $ratio, sprintf( '%s: %s on %s is %.2f:1', $what, $fg, $bg, $ratio ) );
    }

    public function test_body_ink_and_the_primary_button_clear_four_and_a_half(): void {
        foreach ( $this->palettes() as $name => $p ) {
            $t = $p['tokens'];
            $this->assertRatio( 4.5, $t['--tt-ink'], $t['--tt-paper'], $name . ' ink on paper' );
            $this->assertRatio( 4.5, $t['--tt-ink'], $t['--tt-bg-soft'], $name . ' ink on bg-soft' );
            $this->assertRatio( 4.5, '#ffffff', $t['--tt-primary'], $name . ' white on primary' );
        }
    }

    /**
     * `--tt-focus` is drawn in `--tt-primary` (tokens.css). On the rail a
     * theme rings in its own colour, which has to clear the rail instead.
     */
    public function test_the_focus_ring_clears_three(): void {
        foreach ( $this->palettes() as $name => $p ) {
            $t = $p['tokens'];
            $this->assertRatio( 3.0, $t['--tt-primary'], $t['--tt-paper'], $name . ' focus ring on paper' );

            if ( ! preg_match( '/\.tt-shell-nav__link:focus-visible\s*\{[^}]*outline:\s*2px solid ([^;]+);/', $p['css'], $ring ) ) {
                continue;
            }
            $rail = $this->rail( $p['css'], $t );
            $fg   = $this->resolve( $ring[1], $t );
            $this->assertNotNull( $fg, $name . ' rail focus colour does not resolve' );
            $this->assertRatio( 3.0, (string) $fg, $rail, $name . ' focus ring on the rail' );
        }
    }

    /** @param array<string, string> $t */
    private function rail( string $css, array $t ): string {
        $this->assertSame( 1, preg_match( '/\.tt-shell-nav\s*\{[^}]*background:\s*([^;]+);/', $css, $rail ), 'a themed rail rule paints no rail' );
        $bg = $this->resolve( $rail[1], $t );
        $this->assertNotNull( $bg, 'rail colour does not resolve' );
        return (string) $bg;
    }

    public function test_the_active_marker_clears_three_on_the_rail(): void {
        foreach ( $this->palettes() as $name => $p ) {
            $css = $p['css'];
            if ( ! preg_match( '/\.tt-shell-nav__link\.is-active\s*\{[^}]*box-shadow:\s*inset 3px 0 0 ([^;]+);/', $css, $marker ) ) {
                continue;
            }
            $fg = $this->resolve( $marker[1], $p['tokens'] );
            $this->assertNotNull( $fg, $name . ' marker colour does not resolve' );
            $this->assertRatio( 3.0, (string) $fg, $this->rail( $css, $p['tokens'] ), $name . ' active marker on the rail' );
        }
    }
}
