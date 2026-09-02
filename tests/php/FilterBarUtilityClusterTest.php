<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\FilterBar;

/**
 * #3289 — Clear and the summary chips are rendered once, outside both the
 * mobile block and the sheet, so a desktop reader can see them.
 *
 * They used to live in the two places the desktop stylesheet hides:
 * `.tt-filterbar__mobile` and `.tt-filter-sheet__foot`. Every surface passed
 * `reset_url`, every surface rendered it, and no desktop user had ever seen
 * it — a bar with five filters set offered no way to clear them and no
 * readback of what was applied. For the link-based groups (`period`,
 * `status`, the archive `menu`) there is often no "none" option to walk back
 * to either, so the filters could not be undone by hand.
 *
 * The sheet keeps its own Clear pointing at the same URL: it is where a
 * phone user expects it, and one extra link is cheaper than teaching them a
 * new place to look.
 */
final class FilterBarUtilityClusterTest extends WP_UnitTestCase {

    /** @param array<string,mixed> $extra */
    private function render( array $extra = [] ): string {
        return FilterBar::html( array_merge( [
            'action'       => '/',
            'method'       => 'get',
            'reset_url'    => '/?reset=1',
            'active_count' => 2,
            'chips'        => [ 'Team: Ajax U17', 'Position: Striker' ],
            'groups'       => [
                [
                    'type'    => 'select',
                    'key'     => 'team',
                    'name'    => 'team_id',
                    'label'   => 'Team',
                    'value'   => '2',
                    'options' => [ '' => 'All', '2' => 'Ajax U17' ],
                ],
            ],
        ], $extra ) );
    }

    public function test_the_cluster_renders_outside_the_mobile_block_and_the_sheet(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'tt-filterbar__utils', $html );

        // Positional, because the defect was entirely about WHERE this
        // markup sat: the mobile block closes before the cluster opens.
        $mobile_open  = strpos( $html, 'tt-filterbar__mobile' );
        $utils_open   = strpos( $html, 'tt-filterbar__utils' );
        $sheet_open   = strpos( $html, 'tt-filter-sheet' );

        $this->assertNotFalse( $mobile_open );
        $this->assertNotFalse( $utils_open );
        $this->assertTrue( $mobile_open < $utils_open, 'the cluster comes after the mobile trigger block' );
        $this->assertTrue( $utils_open < $sheet_open, 'the cluster comes before the sheet, not inside it' );
    }

    public function test_clear_is_in_the_cluster(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'tt-filterbar__clear', $html );
        $this->assertMatchesRegularExpression(
            '/tt-filterbar__utils.{0,600}?tt-filterbar__clear/s',
            $html,
            'Clear must render inside the utility cluster'
        );
    }

    public function test_the_chips_are_in_the_cluster(): void {
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/tt-filterbar__utils.{0,200}?tt-chips/s',
            $html,
            'the chips must render inside the utility cluster'
        );
        $this->assertStringContainsString( 'Team: Ajax U17', $html );
    }

    /** The phone still gets a Clear where it expects one. */
    public function test_the_sheet_keeps_its_own_clear(): void {
        $this->assertStringContainsString( 'tt-filter-sheet__reset', $this->render() );
    }

    /** The trigger and its badge stay mobile-only — desktop shows the controls. */
    public function test_the_trigger_and_badge_stay_in_the_mobile_block(): void {
        $html = $this->render();

        $mobile_open = strpos( $html, 'tt-filterbar__mobile' );
        $trigger     = strpos( $html, 'tt-filterbtn' );
        $utils_open  = strpos( $html, 'tt-filterbar__utils' );

        $this->assertTrue( $mobile_open < $trigger && $trigger < $utils_open );
        $this->assertStringContainsString( 'tt-filterbtn__badge', $html );
    }

    /** Nothing to say, nothing rendered — an empty cluster would be a stray box. */
    public function test_no_cluster_without_chips_or_a_reset_url(): void {
        $html = $this->render( [ 'chips' => [], 'reset_url' => '', 'active_count' => 0 ] );

        $this->assertStringNotContainsString( 'tt-filterbar__utils', $html );
    }

    /**
     * The cluster is laid out in both viewports. The whole defect was a
     * `display: none` above 1024px, so a stylesheet assertion is what
     * actually guards it — markup alone was never the problem.
     */
    public function test_the_stylesheet_lays_the_cluster_out_in_both_viewports(): void {
        $css = (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/assets/css/frontend-filter-bar.css'
        );

        $this->assertStringContainsString( '.tt-filterbar__utils', $css );
        // It must NOT be swept up by the desktop rule that hides the mobile
        // chrome — that is exactly how it was invisible before.
        $this->assertStringNotContainsString(
            ".tt-dashboard .tt-filterbar__utils {\n\t\tdisplay: none;",
            $css
        );
    }
}
