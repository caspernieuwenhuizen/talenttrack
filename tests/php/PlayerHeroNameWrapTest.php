<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4701 — the player hero name wraps instead of ending in an ellipsis.
 *
 * The hero heading was one line with `nowrap` and an ellipsis, so on a
 * phone a long name was cut to "Maximiliaan-Al…". With the current
 * breadcrumb cut to one line as well (#4655), that left the name whole
 * nowhere on the page below about 480px.
 *
 * The team hero reuses the same class and keeps its two-line clamp on a
 * phone and its one-line ellipsis from 768px. It used to borrow the clip
 * and the ellipsis from the base rule, so it now has to carry them itself.
 *
 * A stylesheet assertion guards this, as in `TapTargetFloorTest`: the
 * defect is a declaration, not markup.
 */
final class PlayerHeroNameWrapTest extends WP_UnitTestCase {

    private function css(): string {
        return (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/frontend-player-detail.css' );
    }

    /**
     * The declarations of the first rule whose selector is exactly
     * `$selector`, at the start of a line.
     */
    private function rule( string $selector ): string {
        $css = $this->css();
        $at  = strpos( $css, "\n" . $selector . ' {' );

        $this->assertNotFalse( $at, 'No `' . $selector . '` rule in frontend-player-detail.css' );

        $open  = strpos( $css, '{', (int) $at );
        $close = strpos( $css, '}', (int) $open );

        return substr( $css, (int) $open + 1, (int) $close - (int) $open - 1 );
    }

    public function test_the_player_name_wraps_and_is_never_cut(): void {
        $rule = $this->rule( '.tt-player-hero__name' );

        $this->assertStringContainsString( 'overflow-wrap: anywhere', $rule );
        $this->assertStringNotContainsString( 'white-space: nowrap', $rule );
        $this->assertStringNotContainsString( 'text-overflow', $rule );
        $this->assertStringNotContainsString( 'overflow: hidden', $rule );
        $this->assertStringNotContainsString( 'line-clamp', $rule );
    }

    public function test_the_team_name_keeps_its_own_clamp_and_ellipsis(): void {
        $rule = $this->rule( '.tt-team-hero .tt-player-hero__name' );

        $this->assertStringContainsString( '-webkit-line-clamp: 2', $rule );
        $this->assertStringContainsString( 'overflow: hidden', $rule );
        $this->assertStringContainsString( 'text-overflow: ellipsis', $rule );
    }
}
