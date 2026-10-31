<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4310 — the shared button's two height floors live in one place.
 *
 * The canonical `.tt-btn` block set no height, so a fine-pointer button came
 * out at 39px, and the 48px floor existed only in public.css's coarse-pointer
 * block. Sheets patched their own buttons back to 48px on every pointer, which
 * is how the rule drifted per surface. The decision (D-18, option B): 48px
 * under a coarse pointer, a 36px floor under a fine one, both next to the
 * canonical block, and no sheet repeating them.
 *
 * A stylesheet assertion guards it; the browser-level proof is the mobile
 * gate (`tests/e2e/mobile-viewport.spec.js`), which runs with touch emulation.
 */
final class ButtonTapFloorTest extends WP_UnitTestCase {

    /**
     * Rules allowed to keep their own 48px floor on a `.tt-btn`, keyed by
     * sheet. Each one carries the reason in a comment beside the rule, or is
     * outside this change's reach.
     *
     * @var array<string, list<string>>
     */
    private const KEPT = [
        // The button shares a row with a 48px field.
        'frontend-team-stats.css' => [ '.tt-ts-window__form .tt-btn' ],
        'frontend-pop.css'        => [ '.tt-pdp-team-gate__form .tt-btn' ],
        // The player profile's sheets were left as they were.
        'frontend-player-detail.css'        => [ '.tt-bp-card__actions .tt-btn', '.tt-player-row--injury .tt-player-row__actions .tt-btn' ],
        'frontend-player-hero-popovers.css' => [ '.tt-player-hero__actions .tt-btn', '.tt-pp-footer .tt-btn' ],
    ];

    private function css( string $relative ): string {
        return (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/' . $relative );
    }

    private static function stripComments( string $css ): string {
        return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
    }

    public function test_the_tokens_carry_both_floors(): void {
        $tokens = $this->css( 'tokens.css' );

        $this->assertMatchesRegularExpression( '/--tt-tap-min:\s*48px;/', $tokens );
        $this->assertMatchesRegularExpression( '/--tt-tap-min-fine:\s*2\.25rem;/', $tokens );
    }

    public function test_the_canonical_block_has_the_fine_floor_and_the_coarse_rule_follows_it(): void {
        $css = self::stripComments( $this->css( 'frontend-admin.css' ) );

        $canonical = strpos( $css, '.tt-dashboard .tt-btn-danger {' );
        $this->assertNotFalse( $canonical, 'the canonical button block is in frontend-admin.css' );
        $block = substr( $css, (int) $canonical, (int) strpos( $css, '}', (int) $canonical ) - (int) $canonical );
        $this->assertStringContainsString( 'min-height: var(--tt-tap-min-fine, 2.25rem);', $block );

        $coarse = strpos( $css, '@media (pointer: coarse)', (int) $canonical );
        $this->assertNotFalse( $coarse, 'the coarse-pointer floor sits after the canonical block' );
        $rule = substr( $css, (int) $coarse, (int) strpos( $css, '}', (int) $coarse ) - (int) $coarse );
        foreach ( [ 'tt-btn', 'tt-btn-primary', 'tt-btn-secondary', 'tt-btn-danger' ] as $variant ) {
            $this->assertMatchesRegularExpression( '/(^|[\s,{])\.' . $variant . '\s*,/', $rule, ".{$variant} outside .tt-dashboard" );
            $this->assertStringContainsString( '.tt-dashboard .' . $variant, $rule, "a scoped .{$variant}, or the fine floor outranks it" );
        }
        $this->assertStringContainsString( 'min-height: var(--tt-tap-min, 48px);', $rule );
    }

    public function test_public_css_no_longer_carries_the_button_floor(): void {
        $css = self::stripComments( $this->css( 'public.css' ) );

        $this->assertDoesNotMatchRegularExpression( '/(^|[\s}])\.tt-btn\s*\{\s*min-height/', $css );
    }

    public function test_no_sheet_repeats_the_floor_on_its_own_buttons(): void {
        $root      = dirname( __DIR__, 2 ) . '/assets/css';
        $offenders = [];
        $files     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            if ( substr( (string) $file, -4 ) !== '.css' ) continue;
            $sheet = basename( (string) $file );
            $css   = self::stripComments( (string) file_get_contents( (string) $file ) );
            preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );
            foreach ( $rules as $rule ) {
                $selectors = array_map(
                    static fn( string $s ): string => (string) preg_replace( '/\s+/', ' ', trim( $s ) ),
                    explode( ',', $rule[1] )
                );
                $plain = array_filter( $selectors, static fn( string $s ): bool => (bool) preg_match( '/(^|[\s>])(button)?\.tt-btn(:link|:visited)?$/', $s ) );
                if ( ! $plain || count( $plain ) !== count( $selectors ) ) continue;
                if ( ! preg_match( '/min-height\s*:\s*(48px|3rem|44px)\s*(;|$)/m', $rule[2] ) ) continue;
                foreach ( $selectors as $selector ) {
                    if ( in_array( $selector, self::KEPT[ $sheet ] ?? [], true ) ) continue 2;
                }
                $offenders[] = $sheet . ': ' . implode( ', ', $selectors );
            }
        }

        $this->assertSame( [], $offenders, 'the shared floor covers these; drop the min-height or justify it beside the rule' );
    }
}
