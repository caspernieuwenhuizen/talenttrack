<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\FilterBar;

/**
 * #3291 — every filter group carries a modifier for its TYPE, not only for
 * its key.
 *
 * #2203 right-aligned the trailing utility controls with a rule written
 * against `.tt-filterbar__group--status`. That is a KEY modifier, and no
 * surface in the app uses `status` as a group key: the list tables and the
 * activities view pass `archived`, the alerts inbox passes `state`, and
 * `FrontendListTable::statusGroup()` passes the caller's own filter key. The
 * rule therefore matched nothing, on every surface, since it was written —
 * the `⋯` menu sat mid-row against its neighbour with empty space to its
 * right, reading as one more filter rather than a utility.
 *
 * The lesson the assertions below encode: a rule about what a group IS has
 * to key on the type, because the key belongs to the caller and will not
 * agree with it.
 */
final class FilterGroupTypeModifierTest extends WP_UnitTestCase {

    private function render(): string {
        return FilterBar::html( [
            'action' => '/',
            'method' => 'get',
            'groups' => [
                [
                    'type'    => 'select',
                    'key'     => 'team',
                    'name'    => 'team_id',
                    'label'   => 'Team',
                    'value'   => '',
                    'options' => [ [ 'value' => '', 'label' => 'All' ] ],
                ],
                [
                    'type'    => 'status',
                    'key'     => 'state',
                    'label'   => 'State',
                    'param'   => 'state',
                    'options' => [ [ 'value' => 'open', 'label' => 'Open', 'url' => '/?state=open' ] ],
                ],
                [
                    'type'    => 'menu',
                    'key'     => 'archived',
                    'label'   => 'More',
                    'options' => [ [ 'value' => '1', 'label' => 'Archived', 'url' => '/?archived=1' ] ],
                ],
            ],
        ] );
    }

    /**
     * The two groups the right-align rule reaches for. Neither of them uses
     * `status` as its key, which is exactly why the old rule was dead.
     */
    public function test_status_and_menu_groups_carry_a_type_modifier(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'tt-filterbar__group--t-status', $html );
        $this->assertStringContainsString( 'tt-filterbar__group--t-menu', $html );
    }

    /** The key modifier is still there — surfaces style individual groups by it. */
    public function test_the_key_modifier_is_kept_alongside_it(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'tt-filterbar__group--state', $html );
        $this->assertStringContainsString( 'tt-filterbar__group--archived', $html );
        $this->assertStringContainsString( 'tt-filterbar__group--team', $html );
    }

    /** Every group gets one, not just the trailing pair. */
    public function test_an_ordinary_group_carries_its_type_too(): void {
        $this->assertStringContainsString( 'tt-filterbar__group--t-select', $this->render() );
    }

    /**
     * The alignment rule the modifier exists for must actually name it.
     *
     * Asserting the CSS rather than the markup alone is the point: the defect
     * was a selector that referred to a class nothing emitted, and a test
     * that only checked the markup would have passed throughout.
     */
    public function test_the_stylesheet_aligns_on_the_type_modifier(): void {
        $css = (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/assets/css/frontend-filter-bar.css'
        );

        $this->assertStringContainsString( '.tt-filterbar__group--t-status', $css );
        $this->assertStringContainsString( '.tt-filterbar__group--t-menu', $css );
        $this->assertStringNotContainsString(
            '.tt-dashboard .tt-filterbar__group--status {',
            $css,
            'the dead key-modifier rule must not come back'
        );
    }
}
