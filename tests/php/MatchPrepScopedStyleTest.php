<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\MatchPrep\Print\MatchPrepPrintableRenderer;

/**
 * The read-only match prep sheet renders inside the dashboard, so the
 * printable CSS it carries must not reach the page around it. Every
 * selector in `scopedStyleBlock()` starts with the scope or with a `.tt-`
 * class; a bare rule added to `styleBlock()` later (`p {`, `img {`) fails
 * here until `scopedStyleBlock()` narrows it too.
 */
final class MatchPrepScopedStyleTest extends WP_UnitTestCase {

    private const SCOPE = '.tt-mp--readonly';

    public function test_every_selector_is_scoped_or_a_tt_class(): void {
        $selectors = self::selectors( MatchPrepPrintableRenderer::scopedStyleBlock( self::SCOPE ) );
        $this->assertNotSame( [], $selectors, 'precondition: the block has rules' );

        $loose = array_values( array_filter(
            $selectors,
            static fn( string $sel ): bool => strpos( $sel, self::SCOPE ) !== 0 && strpos( $sel, '.tt-' ) !== 0
        ) );
        $this->assertSame( [], $loose, 'selectors that would restyle the page around the sheet' );
    }

    public function test_the_print_block_itself_still_has_its_bare_rules(): void {
        // The print page and the PDF are documents of their own and keep
        // the unscoped block; this pins that the check above is not vacuous.
        $this->assertContains( 'body', self::selectors( MatchPrepPrintableRenderer::styleBlock() ) );
    }

    /** @return list<string> Every selector of every rule, comments removed. */
    private static function selectors( string $css ): array {
        $css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
        preg_match_all( '/([^{}]+)\{[^{}]*\}/', $css, $m );
        $out = [];
        foreach ( $m[1] as $group ) {
            foreach ( explode( ',', $group ) as $sel ) {
                $sel = trim( $sel );
                if ( $sel !== '' ) $out[] = $sel;
            }
        }
        return $out;
    }
}
