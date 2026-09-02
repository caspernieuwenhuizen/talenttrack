<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\FilterBar;

/**
 * #3288 — the bottom sheet's Apply button has to submit the bar's form.
 *
 * It was `type="button"` with only the close hook, so on a phone a date
 * range or free-text filter set inside the sheet was thrown away when the
 * sheet shut. Nothing said so: selects and toggles auto-submit on `change`,
 * so a user who also set a team saw the page reload and reasonably concluded
 * the dates had applied too.
 *
 * The E2E suite could not have caught it — `filterbar.spec.js` drives the
 * players list, and `FrontendListTable`'s hydrator live-filters on any
 * `change` event, so the sheet's Apply is never the thing that submits
 * there. The eleven surfaces that call `FilterBar::render()` directly are
 * the affected ones, and none is covered.
 *
 * So the contract is pinned on the rendered markup instead: this is a
 * one-attribute regression that would otherwise be invisible again.
 */
final class FilterSheetApplySubmitsTest extends WP_UnitTestCase {

    /** A bar with a date range — the group that has no other way to submit. */
    private function render(): string {
        return FilterBar::html( [
            'action'    => '/',
            'method'    => 'get',
            'reset_url' => '/?reset=1',
            'groups'    => [
                [
                    'type' => 'date_range',
                    'key'  => 'range',
                    'from' => [ 'name' => 'from', 'value' => '2026-01-01', 'label' => 'From' ],
                    'to'   => [ 'name' => 'to',   'value' => '2026-06-30', 'label' => 'To' ],
                ],
            ],
        ] );
    }

    public function test_the_sheet_apply_button_is_a_submit(): void {
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/<button[^>]*type="submit"[^>]*tt-filter-sheet__apply/',
            $html,
            'the sheet footer Apply must submit the form, not merely close the sheet'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*type="button"[^>]*tt-filter-sheet__apply/',
            $html
        );
    }

    /**
     * It still closes the sheet. `closeSheet()` does not preventDefault, so
     * the two behaviours coexist — but if the hook were dropped the sheet
     * would stay open over a page that is already navigating away.
     */
    public function test_the_sheet_apply_button_still_closes_the_sheet(): void {
        $this->assertMatchesRegularExpression(
            '/<button[^>]*tt-filter-sheet__apply[^>]*data-tt-filter-close/',
            $this->render()
        );
    }

    /**
     * The sheet lives inside the form. A submit button outside it would
     * submit nothing, which is the other way this could be wrong.
     */
    public function test_the_sheet_is_inside_the_form(): void {
        $html = $this->render();

        $form_at  = strpos( $html, '<form' );
        $sheet_at = strpos( $html, 'tt-filter-sheet' );
        $close_at = strrpos( $html, '</form>' );

        $this->assertNotFalse( $form_at );
        $this->assertNotFalse( $sheet_at );
        $this->assertNotFalse( $close_at );
        $this->assertTrue(
            $form_at < $sheet_at && $sheet_at < $close_at,
            'the sheet must render between <form> and </form> for its Apply to submit anything'
        );
    }
}
