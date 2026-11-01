<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4575 — muted text clears 4.5:1 (CLAUDE.md §2, body text) on the
 * paper and soft backgrounds of the default palette and of every theme.
 *
 * Muted small text measured 4.39:1 in the federation theme. A theme
 * inherits any token it does not set from tokens.css, so each theme is
 * checked with that fallback, and a new theme-*.css is picked up by glob.
 */
final class ThemeMutedContrastTest extends WP_UnitTestCase {

    private function css( string $relative ): string {
        return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
    }

    /** @return array<string, string> token => hex, first declaration wins */
    private function tokens( string $css ): array {
        $out = [];
        if ( preg_match_all( '/(--tt-(?:muted|paper|bg-soft))\s*:\s*(#[0-9a-fA-F]{6})\b/', $css, $m, PREG_SET_ORDER ) ) {
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

    /** @return array<string, array<string, string>> palette name => tokens */
    private function palettes(): array {
        $base     = $this->tokens( $this->css( 'assets/css/tokens.css' ) );
        $palettes = [ 'default' => $base ];
        foreach ( glob( dirname( __DIR__, 2 ) . '/assets/css/theme-*.css' ) ?: [] as $path ) {
            $palettes[ basename( $path, '.css' ) ] = array_merge( $base, $this->tokens( (string) file_get_contents( $path ) ) );
        }
        return $palettes;
    }

    public function test_muted_text_clears_four_and_a_half_on_paper_and_soft_backgrounds(): void {
        $palettes = $this->palettes();
        $this->assertArrayHasKey( 'theme-federation', $palettes );
        $this->assertArrayHasKey( 'theme-leon-hutten', $palettes );

        foreach ( $palettes as $name => $t ) {
            foreach ( [ '--tt-paper', '--tt-bg-soft' ] as $bg ) {
                $ratio = $this->contrast( $t['--tt-muted'], $t[ $bg ] );
                $this->assertGreaterThanOrEqual(
                    4.5,
                    $ratio,
                    sprintf( '%s: --tt-muted %s on %s %s is %.2f:1', $name, $t['--tt-muted'], $bg, $t[ $bg ], $ratio )
                );
            }
        }
    }

    public function test_the_two_themes_carry_the_decided_values(): void {
        $this->assertSame( '#5a6a7d', $this->palettes()['theme-federation']['--tt-muted'] );
        $this->assertSame( '#5f6a75', $this->palettes()['theme-leon-hutten']['--tt-muted'] );
    }
}
