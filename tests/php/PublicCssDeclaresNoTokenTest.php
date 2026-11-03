<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4824 — tokens.css is the only stylesheet that declares the design tokens.
 *
 * public.css repeated a set of them on `.tt-root` and three more on
 * `.tt-dashboard`, at the same values. A second declaration drifts the
 * moment one copy changes, and a theme then has to override both.
 */
final class PublicCssDeclaresNoTokenTest extends WP_UnitTestCase {

    public function test_public_css_declares_no_token(): void {
        $css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/public.css' );
        $css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

        $this->assertDoesNotMatchRegularExpression( '/(^|[;{\s])--tt-[a-z0-9-]+\s*:/m', $css );
    }

    /**
     * Every token public.css used to declare still has its default here.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function moved(): array {
        return [
            'ink'            => [ '--tt-ink', '#0e1a14' ],
            'line'           => [ '--tt-line', '#e3e1d8' ],
            'muted'          => [ '--tt-muted', '#6a6d66' ],
            'shadow-sm'      => [ '--tt-shadow-sm', 'none' ],
            'motion'         => [ '--tt-motion-duration', '150ms' ],
            'fs-body'        => [ '--tt-fs-body', '1rem' ],
            'lh-body'        => [ '--tt-lh-body', '1.5' ],
            'danger-subtle'  => [ '--tt-danger-subtle', '#fde2e2' ],
        ];
    }

    /**
     * @dataProvider moved
     */
    public function test_tokens_css_keeps_the_default( string $token, string $value ): void {
        $this->assertMatchesRegularExpression(
            '/^\s*' . preg_quote( $token, '/' ) . '\s*:\s*' . preg_quote( $value, '/' ) . '\s*;/m',
            (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/tokens.css' )
        );
    }
}
