<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\FrontendMobilePromptView;

/**
 * #2811 — the desktop-only prompt page.
 *
 * The page stands in front of 77 surfaces, so the thing worth testing is
 * that it says something different depending on which one. A page that
 * renders the same generic sentence for every slug would pass a smoke test
 * and fail the issue entirely.
 *
 * The reason and alternative resolvers are private, so these go through
 * `render()` and read the output — which also covers the wiring, and would
 * catch a resolver that works but is never called.
 */
final class MobilePromptViewTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function test_a_roster_grid_gets_the_roster_reason_not_the_generic_one(): void {
        $html = $this->render( 'attendance-grid' );

        $this->assertStringContainsString( 'spreadsheet-style entry', $html );
        $this->assertStringNotContainsString( 'for the best experience', $html );
    }

    public function test_a_matrix_and_a_document_get_different_reasons(): void {
        $matrix   = $this->render( 'matrix' );
        $document = $this->render( 'match-prep' );

        $this->assertStringContainsString( 'rows and the columns', $matrix );
        $this->assertStringContainsString( 'printed page', $document );

        // The point of the issue: two gated surfaces must not read alike.
        $this->assertNotSame( $matrix, $document );
    }

    public function test_an_unlisted_surface_still_gets_the_generic_reason(): void {
        // Not every one of the 77 has a family, and the fallback has to
        // remain a complete sentence rather than an empty paragraph.
        $html = $this->render( 'some-unlisted-surface' );

        $this->assertStringContainsString( 'for the best experience', $html );
    }

    public function test_the_override_is_a_visible_control_carrying_force_mobile(): void {
        $html = $this->render( 'matrix' );

        $this->assertStringContainsString( 'force_mobile', $html );
        $this->assertStringContainsString( 'Show it anyway', $html );
        // A button, not a footnote link: it carries the button class.
        $this->assertMatchesRegularExpression(
            '/class="[^"]*tt-btn[^"]*"[^>]*>\s*Show it anyway/',
            $html
        );
    }

    public function test_the_email_and_dashboard_affordances_survive(): void {
        // Both predate this issue and are the only way onward for a surface
        // with no phone alternative.
        $html = $this->render( 'matrix' );

        $this->assertStringContainsString( 'tt_mobile_email_link', $html );
        $this->assertStringContainsString( 'Email me the link', $html );
        $this->assertStringContainsString( 'Go to dashboard', $html );
    }

    public function test_a_surface_with_a_phone_path_offers_it_by_name(): void {
        $html = $this->render( 'attendance-grid' );

        $this->assertStringContainsString( 'Open the activity', $html );
        $this->assertStringContainsString( 'tt_view=activities', $html );
    }

    public function test_a_surface_without_a_phone_path_offers_none(): void {
        // Sparse on purpose — an invented alternative costs a coach more
        // than the honest absence of one.
        $html = $this->render( 'matrix' );

        $this->assertStringNotContainsString( 'tt-mprompt__alt', $html );
    }

    public function test_no_inline_style_attributes_remain_in_the_prompt_markup(): void {
        // The page has to meet the rules it is telling people about, and its
        // styling now lives in frontend-mobile-prompt.css.
        //
        // Scoped to the prompt's own container: the shared `renderHeader()`
        // above it still carries two grandfathered inline styles, which are
        // not this issue's to remove and would make the assertion a test of
        // somebody else's file.
        $html  = $this->render( 'matrix' );
        $start = strpos( $html, '<div class="tt-mprompt">' );

        $this->assertNotFalse( $start, 'the prompt container did not render' );
        $this->assertStringNotContainsString( 'style="', substr( $html, $start ) );
    }

    private function render( string $slug ): string {
        ob_start();
        FrontendMobilePromptView::render( get_current_user_id(), $slug );
        return (string) ob_get_clean();
    }
}
