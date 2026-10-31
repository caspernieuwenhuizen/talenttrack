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
        'frontend-team-stats.css'          => [ '.tt-ts-window__form .tt-btn' ],
        'frontend-pop.css'                 => [ '.tt-pdp-team-gate__form .tt-btn' ],
        'frontend-parent-search.css'       => [ '.tt-parent-search__form .tt-btn' ],
        'frontend-cohort-transitions.css'  => [ '.tt-dashboard .tt-cohort-filters .tt-btn' ],
        'frontend-suspensions.css'         => [ '.tt-suspension-filters .tt-btn' ],
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

    /**
     * A `.tt-btn` sized by one class (`.tt-me-load { min-height: 48px }`,
     * 0,1,0) loses to the fine floor `.tt-dashboard .tt-btn` (0,2,0): the
     * sheet claims a height the button never gets under a mouse. Either the
     * height is deliberate and has a `.tt-dashboard`-scoped rule, or the
     * declaration goes. Classes count as buttons when markup in `src/` or
     * `assets/js/` puts them in the same string as `tt-btn`.
     */
    public function test_no_button_class_claims_a_height_the_fine_floor_outranks(): void {
        $root    = dirname( __DIR__, 2 );
        $buttons = [];
        foreach ( [ 'src' => '.php', 'assets/js' => '.js' ] as $dir => $ext ) {
            $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
            foreach ( $files as $file ) {
                if ( substr( (string) $file, -strlen( $ext ) ) !== $ext ) continue;
                $src = (string) file_get_contents( (string) $file );
                if ( strpos( $src, 'tt-btn' ) === false ) continue;
                preg_match_all( '/[^"\'\n]*(?<![\w-])tt-btn(?![\w-])[^"\'\n]*/', $src, $literals );
                foreach ( $literals[0] as $literal ) {
                    preg_match_all( '/(?<![\w-])(tt-[a-z0-9_-]+)/', $literal, $classes );
                    foreach ( $classes[1] as $class ) {
                        if ( $class !== 'tt-btn' && strpos( $class, 'tt-btn-' ) !== 0 ) $buttons[ $class ] = true;
                    }
                }
            }
        }
        $this->assertNotEmpty( $buttons, 'the markup scan found the classes that sit beside tt-btn' );

        $weak   = [];
        $strong = [];
        $files  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/assets/css', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            if ( substr( (string) $file, -4 ) !== '.css' ) continue;
            $css = self::stripComments( (string) file_get_contents( (string) $file ) );
            // A height inside a coarse-pointer block is the touch floor, not a desktop claim.
            $css = (string) preg_replace_callback(
                '/@media\s*\(\s*pointer\s*:\s*coarse\s*\)\s*\{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/',
                static fn( array $m ): string => str_repeat( ' ', strlen( $m[0] ) ),
                $css
            );
            preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );
            foreach ( $rules as $rule ) {
                if ( ! preg_match( '/(^|;|\s)(min-)?height\s*:\s*(?!auto|0\b|100%|inherit|initial|unset)[^;]+/', $rule[2] ) ) continue;
                foreach ( explode( ',', $rule[1] ) as $selector ) {
                    $selector = trim( (string) preg_replace( '/\s+/', ' ', $selector ) );
                    if ( preg_match( '/^(?:[a-z]+)?\.(tt-[a-z0-9_-]+)$/', $selector, $one ) && isset( $buttons[ $one[1] ] ) ) {
                        $weak[ $one[1] ][] = basename( (string) $file ) . ': ' . $selector;
                    } elseif ( preg_match( '/^\.tt-dashboard \.(tt-[a-z0-9_-]+)$/', $selector, $scoped ) ) {
                        $strong[ $scoped[1] ] = true;
                    }
                }
            }
        }

        $offenders = [];
        foreach ( $weak as $class => $where ) {
            if ( ! isset( $strong[ $class ] ) ) $offenders = array_merge( $offenders, $where );
        }
        sort( $offenders );
        $this->assertSame( [], $offenders, 'scope the height under .tt-dashboard if it is deliberate, or drop it' );
    }
}
