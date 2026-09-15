<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\FilterBar;
use TT\Shared\Frontend\FrontendComparisonView;

/**
 * #3352 — the comparison view is a form that happens to contain a bar.
 *
 * `FrontendComparisonView::render()` opened its own GET form and the bar
 * emitted a second one inside it. Nested forms are invalid HTML, and every
 * browser repairs them the same way: discard the inner one. That repair is
 * the only reason the surface ever worked — the date range, the evaluation
 * type, the player slots and Compare all re-associated with the OUTER form.
 *
 * Which is also why `refresh => true` could never take there: the attribute
 * landed on the form the parser had already thrown away, `filter-refresh.js`
 * bound to nothing, and the surface kept full-page reloading with nothing
 * failing loudly to say so.
 *
 * The defect is invisible in a browser, so it has to be asserted on the
 * markup: one `<form>`, not two.
 */
final class FilterBarHostFormTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        $_SERVER['REQUEST_URI'] = '/dash/?tt_view=comparison';
    }

    /** @param array<string,mixed> $extra */
    private function bar( array $extra = [] ): string {
        return FilterBar::html( array_merge( [
            'form_action' => '/dash/',
            'hidden'      => [ 'tt_view' => 'comparison' ],
            'groups'      => [
                [
                    'type'        => 'select',
                    'key'         => 'eval-type',
                    'name'        => 'eval_type_id',
                    'label'       => 'Evaluation Type',
                    'selected'    => '',
                    'placeholder' => 'All types',
                    'options'     => [ '3' => 'Mid-season' ],
                ],
            ],
        ], $extra ) );
    }

    /* ---- the component ------------------------------------------------ */

    public function test_the_bar_owns_a_form_by_default(): void {
        $html = $this->bar();

        $this->assertStringContainsString( '<form method="get" class="tt-filterbar__form"', $html );
        $this->assertStringContainsString( FilterBar::FORM_MARKER, $html );
        $this->assertStringContainsString( '</form>', $html );
    }

    public function test_form_false_emits_no_form_element(): void {
        $html = $this->bar( [ 'form' => false ] );

        $this->assertStringNotContainsString( '<form', $html );
        $this->assertStringNotContainsString( '</form>', $html );
    }

    /**
     * Not merely "no form" — the controls still have to be there, or the
     * surface loses its filters and the assertion above passes for the
     * wrong reason.
     */
    public function test_form_false_still_emits_the_groups_and_the_sheet(): void {
        $html = $this->bar( [ 'form' => false ] );

        $this->assertStringContainsString( 'name="eval_type_id"', $html );
        $this->assertStringContainsString( 'tt-filter-sheet', $html );
        $this->assertStringContainsString( 'data-tt-filterbar', $html );
    }

    /** The hidden routing fields belong to the host form now, but they stay. */
    public function test_form_false_keeps_the_hidden_fields(): void {
        $html = $this->bar( [ 'form' => false ] );

        $this->assertStringContainsString( 'name="tt_view" value="comparison"', $html );
    }

    /**
     * The refresh marker goes on the host form, not on a bar that renders
     * no form — emitting it here would put it on a <div> that
     * `filter-refresh.js` never looks at.
     */
    public function test_form_false_does_not_carry_the_refresh_marker(): void {
        $html = $this->bar( [ 'form' => false, 'refresh' => true ] );

        $this->assertStringNotContainsString( FilterBar::REFRESH_MARKER, $html );
        // …but `refresh` still suppresses the auto-submit, which would
        // otherwise navigate on the same change the host is refreshing on.
        $this->assertStringNotContainsString( 'data-tt-filter-submit', $html );
    }

    /** A bar that owns its form still takes the marker, unchanged. */
    public function test_an_owning_bar_still_carries_the_refresh_marker(): void {
        $html = $this->bar( [ 'refresh' => true ] );

        $this->assertStringContainsString( FilterBar::REFRESH_MARKER . '="1"', $html );
    }

    /** The host form needs both markers: one to be found, one to opt in. */
    public function test_host_form_attrs_carries_both_markers(): void {
        $attrs = FilterBar::hostFormAttrs();

        $this->assertStringContainsString( FilterBar::FORM_MARKER, $attrs );
        $this->assertStringContainsString( FilterBar::REFRESH_MARKER . '="1"', $attrs );
    }

    /* ---- the surface --------------------------------------------------- */

    /**
     * The defect itself. A browser repairs nested forms silently, so this
     * counts the tags in the response rather than exercising the page.
     */
    public function test_the_comparison_view_emits_exactly_one_form(): void {
        $html = $this->renderComparison();

        $this->assertSame(
            1,
            substr_count( $html, '<form' ),
            'The comparison view emitted a nested <form>; browsers discard the inner one silently.'
        );
    }

    public function test_the_comparison_form_carries_the_bar_markers(): void {
        $html = $this->renderComparison();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*' . preg_quote( FilterBar::FORM_MARKER, '/' ) . '/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<form[^>]*' . preg_quote( FilterBar::REFRESH_MARKER, '/' ) . '="1"/',
            $html
        );
    }

    /**
     * `filter-refresh.js` bails without a region, so the opt-in above is
     * only half of it — and the region has to hold the empty state too, or
     * a window with no evaluations could never replace a populated compare.
     */
    public function test_the_comparison_view_marks_a_refresh_region(): void {
        $html = $this->renderComparison();

        $this->assertStringContainsString( 'data-tt-filter-region', $html );
        $this->assertStringContainsString( 'Pick at least one player above', $html );
        $region = strpos( $html, 'data-tt-filter-region' );
        $empty  = strpos( $html, 'Pick at least one player above' );
        $this->assertNotFalse( $region );
        $this->assertNotFalse( $empty );
        $this->assertLessThan( $empty, $region, 'The empty state must sit inside the swapped region.' );
    }

    /** Compare is the commit, and it stays (CLAUDE.md §6). */
    public function test_compare_is_still_the_commit(): void {
        $html = $this->renderComparison();

        $this->assertMatchesRegularExpression(
            '/<button type="submit"[^>]*>\s*Compare\s*<\/button>/',
            $html
        );
    }

    private function renderComparison(): string {
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $had = $_GET;
        $_GET = [ 'tt_view' => 'comparison' ];

        ob_start();
        FrontendComparisonView::render();
        $html = (string) ob_get_clean();

        $_GET = $had;
        return $html;
    }
}
