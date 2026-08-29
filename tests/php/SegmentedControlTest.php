<?php
/**
 * SegmentedControl rendering (#2822).
 *
 * The point of the component is that it is NOT a tab strip: the two grid
 * surfaces and the custom-CSS surface switcher change what the screen shows
 * rather than which facet of a record you are on. So the assertions worth
 * pinning are the ones that stop it drifting back into a tablist.
 */

use TT\Shared\Frontend\Components\SegmentedControl;

class SegmentedControlTest extends WP_UnitTestCase {

    private function render( array $config ): string {
        ob_start();
        SegmentedControl::render( $config );
        return (string) ob_get_clean();
    }

    public function test_renders_nothing_without_options(): void {
        $this->assertSame( '', $this->render( [] ) );
        $this->assertSame( '', $this->render( [ 'options' => [] ] ) );
    }

    public function test_is_not_a_tablist(): void {
        $html = $this->render( [
            'label'   => 'Grid',
            'options' => [
                [ 'label' => 'Attendance', 'current' => true ],
                [ 'label' => 'Minutes', 'url' => '/x?tt_view=minutes-grid' ],
            ],
        ] );

        $this->assertStringNotContainsString( 'role="tablist"', $html );
        $this->assertStringNotContainsString( 'role="tab"', $html );
        $this->assertStringContainsString( 'aria-label="Grid"', $html );
    }

    public function test_the_current_option_is_not_a_link(): void {
        $html = $this->render( [
            'options' => [
                [ 'label' => 'Attendance', 'current' => true ],
                [ 'label' => 'Minutes', 'url' => '/x?tt_view=minutes-grid' ],
            ],
        ] );

        $this->assertStringContainsString( '<span class="tt-segmented__opt is-on" aria-current="page">Attendance</span>', $html );
        $this->assertStringContainsString( 'href="/x?tt_view=minutes-grid"', $html );
    }

    public function test_an_option_with_no_url_renders_as_the_current_one(): void {
        $html = $this->render( [ 'options' => [ [ 'label' => 'Only' ] ] ] );

        $this->assertStringContainsString( 'is-on', $html );
        $this->assertStringNotContainsString( '<a ', $html );
    }

    public function test_escapes_label_and_url(): void {
        $html = $this->render( [
            'label'   => '"onload="alert(1)',
            'options' => [ [ 'label' => '<script(alert(1))/script(', 'url' => 'javascript:alert(1)' ] ],
        ] );

        $this->assertStringNotContainsString( '<script', $html );
        $this->assertStringNotContainsString( 'onload="alert', $html );
    }

    public function test_options_without_a_label_are_skipped(): void {
        $html = $this->render( [
            'options' => [
                [ 'label' => '', 'url' => '/x' ],
                [ 'label' => 'Minutes', 'url' => '/y' ],
            ],
        ] );

        $this->assertStringNotContainsString( 'href="/x"', $html );
        $this->assertStringContainsString( 'href="/y"', $html );
    }
}
