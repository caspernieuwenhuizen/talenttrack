<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\FilterBar;

/**
 * #3294 — the filter bottom sheet is a real `<dialog>`.
 *
 * It was `<div role="dialog" aria-modal="true">`, which asserts modality
 * without implementing any of it. `aria-modal` tells a screen reader the
 * background is unavailable; the browser enforces nothing for a div, so Tab
 * walked out of the sheet into a list the user could not see, assistive tech
 * traversed into content the sheet was covering, and the body scrolled under
 * the scrim.
 *
 * `showModal()` supplies the focus trap, the top layer, `::backdrop`,
 * Escape-to-close and inertness natively — which is why the scrim element and
 * the document-level Escape listener are gone rather than reimplemented.
 *
 * The markup contract is worth pinning because the failure mode is silent: a
 * `<div>` that claims to be modal looks correct in every screenshot and only
 * misbehaves under a keyboard or a screen reader.
 */
final class FilterSheetIsADialogTest extends WP_UnitTestCase {

    private function render(): string {
        return FilterBar::html( [
            'action'    => '/',
            'method'    => 'get',
            'reset_url' => '/?reset=1',
            'groups'    => [
                [
                    'type'  => 'select',
                    'key'   => 'team',
                    'name'  => 'team_id',
                    'label' => 'Team',
                    'value' => '',
                    // value => label map, not a list of arrays — the list
                    // shape renders `Array` per option and raises a warning
                    // CI counts as a failure.
                    'options' => [ '' => 'All', '2' => 'Ajax U17' ],
                ],
            ],
        ] );
    }

    public function test_the_sheet_is_a_dialog_element(): void {
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/<dialog[^>]*class="tt-filter-sheet"/',
            $html,
            'the sheet must be a <dialog> so showModal() can supply the modality'
        );
        $this->assertStringContainsString( '</dialog>', $html );
    }

    /**
     * The two attributes that were claiming what the markup did not do.
     * A real modal `<dialog>` is announced as modal by the platform; adding
     * `role="dialog"` / `aria-modal` back would be redundant at best.
     */
    public function test_the_hand_rolled_modality_assertions_are_gone(): void {
        $html = $this->render();

        $this->assertStringNotContainsString( 'role="dialog"', $html );
        $this->assertStringNotContainsString( 'aria-modal', $html );
    }

    /** The accessible name survives the change — it is the sheet's only label. */
    public function test_the_sheet_keeps_its_accessible_name(): void {
        $this->assertMatchesRegularExpression(
            '/<dialog[^>]*aria-label="[^"]+"/',
            $this->render()
        );
    }

    /** No scrim element: `::backdrop` replaces it. */
    public function test_the_scrim_element_is_gone(): void {
        $this->assertStringNotContainsString( 'tt-filter-sheet-scrim', $this->render() );
    }

    /**
     * The dialog stays inside the form.
     *
     * `showModal()` promotes it to the top layer for rendering only — the DOM
     * position is unchanged, so its Apply button keeps its form owner and
     * #3288 keeps working. Moving the dialog out of the form to "fix"
     * stacking would silently break submitting again.
     */
    public function test_the_dialog_is_still_inside_the_form(): void {
        $html = $this->render();

        $form   = strpos( $html, '<form' );
        $dialog = strpos( $html, '<dialog' );
        $endf   = strrpos( $html, '</form>' );

        $this->assertNotFalse( $form );
        $this->assertNotFalse( $dialog );
        $this->assertTrue( $form < $dialog && $dialog < $endf );
    }

    /**
     * The stylesheet has to give the open dialog its flex layout and drop the
     * UA's centring box, or the sheet renders as a centred bordered card.
     */
    public function test_the_stylesheet_dresses_the_dialog(): void {
        $css = (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/assets/css/frontend-filter-bar.css'
        );

        $this->assertStringContainsString( 'dialog.tt-filter-sheet', $css );
        $this->assertStringContainsString( '::backdrop', $css );
        $this->assertStringContainsString( 'tt-sheet-lock', $css );
    }
}
