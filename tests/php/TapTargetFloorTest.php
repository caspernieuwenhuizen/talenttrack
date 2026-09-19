<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3598 — the 48px tap-target floor reaches form controls.
 *
 * The floor in `public.css` sized buttons, pager links, record links,
 * checkboxes and `summary`, but never a `select` or a text input. Those came
 * out at 44.4px — ten pixels of padding, a 16px line at 1.4, two borders —
 * which is under the CLAUDE.md §2 minimum on every phone and tablet and
 * reads as "nearly right" on a filter bar, so it survived two audits.
 *
 * Three sheets also beat the floor on specificity: a `.tt-dashboard .thing`
 * rule outranks `.tt-btn`, so the exports shortcuts, the team-planner
 * actions and the my-tasks row selector stayed small no matter what the
 * floor said. Each one lifts itself back under a coarse pointer.
 *
 * A stylesheet assertion is what guards this. The defect is a missing
 * declaration, not markup, and the browser-level proof lives in the
 * Playwright gate (`tests/e2e/mobile-viewport.spec.js`), which only runs
 * where wp-env does.
 */
final class TapTargetFloorTest extends WP_UnitTestCase {

    private function css( string $relative ): string {
        return (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/' . $relative );
    }

    /**
     * Everything after the `@media (pointer: coarse)` opener in public.css.
     * Asserting the declaration exists somewhere in the file would pass on a
     * rule that sized controls for a desktop mouse too, which is the one
     * thing this change must not do.
     */
    private function coarseBlock( string $relative ): string {
        $css = $this->css( $relative );
        $at  = strpos( $css, '@media (pointer: coarse)' );

        $this->assertNotFalse( $at, $relative . ' has no coarse-pointer block' );

        return substr( $css, (int) $at );
    }

    public function test_the_floor_sizes_selects_and_inputs(): void {
        $block = $this->coarseBlock( 'public.css' );

        $this->assertStringContainsString( '.tt-dashboard select,', $block );
        $this->assertStringContainsString( '.tt-dashboard .tt-input,', $block );
        $this->assertStringContainsString( '[type="number"],', $block );
        $this->assertStringContainsString( '[type="date"],', $block );
    }

    /**
     * The three sheets that outrank the floor. Named individually because
     * each is a separate specificity accident, and a sweep that fixed two of
     * them would still leave a coach tapping a 32px button.
     *
     * @return array<string, array{0: string, 1: string[]}>
     */
    public static function overridingSheets(): array {
        return [
            'exports column picker' => [
                'frontend-exports.css',
                [ '.tt-export-card__columns-action', '.tt-export-card__columns-item' ],
            ],
            'team planner' => [
                'components/team-planner.css',
                [ '.tt-planner-team-dd-action', '.tt-planner-apply' ],
            ],
            'my tasks' => [
                'frontend-my-tasks.css',
                [ '.tt-mtasks-checkbox', '.tt-mtasks-check-label' ],
            ],
        ];
    }

    /**
     * @dataProvider overridingSheets
     *
     * @param string[] $selectors
     */
    public function test_an_overriding_sheet_lifts_itself_back_under_a_coarse_pointer( string $sheet, array $selectors ): void {
        $block = $this->coarseBlock( $sheet );

        foreach ( $selectors as $selector ) {
            $this->assertStringContainsString(
                $selector,
                $block,
                $selector . ' must be lifted to 48px inside ' . $sheet . "'s coarse-pointer block"
            );
        }

        $this->assertStringContainsString( 'min-height: 48px;', $block );
    }

    /**
     * The my-tasks row selector was a bare `<input>` in a `<div>`: a 22px
     * target, and a checkbox a screen reader announces without saying which
     * task it selects. The label is both fixes at once.
     */
    public function test_the_my_tasks_row_selector_is_a_named_label(): void {
        $php = (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/src/Modules/Workflow/Frontend/FrontendMyTasksView.php'
        );

        $this->assertStringContainsString( '<label class="tt-mtasks-checkbox">', $php );
        $this->assertStringNotContainsString( '<div class="tt-mtasks-checkbox">', $php );
        $this->assertStringContainsString( "__( 'Select task: %s', 'talenttrack' )", $php );
    }
}
