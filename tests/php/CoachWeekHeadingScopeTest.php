<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4554 — the This week panel's headings must outrank the dashboard's own
 * heading rules. `.tt-dashboard hN` is (0,1,1); a bare `.tt-week-title`
 * (0,1,0) loses to it, so every rule styling a class the panel puts on a
 * heading is scoped under `.tt-week` (0,2,0).
 */
final class CoachWeekHeadingScopeTest extends WP_UnitTestCase {

    public function test_every_panel_heading_rule_is_scoped_under_tt_week(): void {
        $root = dirname( __DIR__, 2 );

        // The classes the panel's markup puts on an <h1>-<h6>.
        $classes = [];
        $sources = [ $root . '/src/Shared/Frontend/FrontendTileGrid.php' ];
        foreach ( glob( $root . '/src/Modules/Activities/CoachWeek/Blocks/*.php' ) ?: [] as $file ) $sources[] = $file;
        foreach ( $sources as $file ) {
            preg_match_all( '#<h[1-6][^>]*class="([^"]+)"#', (string) file_get_contents( $file ), $m );
            foreach ( $m[1] as $attr ) {
                foreach ( preg_split( '/\s+/', $attr ) ?: [] as $class ) {
                    if ( str_starts_with( $class, 'tt-week' ) ) $classes[ $class ] = true;
                }
            }
        }
        $this->assertArrayHasKey( 'tt-week-title', $classes, 'the scan finds the panel title' );
        $this->assertArrayHasKey( 'tt-week-next-title', $classes );

        $css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . '/assets/css/frontend-coach-week.css' ) );
        preg_match_all( '/([^{}]+)\{[^{}]*\}/', $css, $rules );

        $loose = [];
        foreach ( $rules[1] as $selector_list ) {
            foreach ( explode( ',', $selector_list ) as $selector ) {
                $selector = trim( (string) preg_replace( '/\s+/', ' ', $selector ) );
                foreach ( array_keys( $classes ) as $class ) {
                    if ( ! preg_match( '/\.' . preg_quote( $class, '/' ) . '(?![\w-])/', $selector ) ) continue;
                    if ( ! str_starts_with( $selector, '.tt-week ' ) ) $loose[] = $selector;
                }
            }
        }
        $this->assertSame( [], $loose, 'scope these under .tt-week so .tt-dashboard hN does not win' );
    }
}
