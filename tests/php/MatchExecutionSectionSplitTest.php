<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use ReflectionMethod;
use ReflectionProperty;
use TT\Modules\MatchExecution\Frontend\FrontendMatchExecutionView;

/**
 * #2935 — the sectioned layout regroups the view by capturing its body
 * once and splitting it on invisible markers. These pin the two halves of
 * that mechanism, because both have a failure mode that is silent:
 *
 *   - `cut()` must emit nothing at all under the classic layout. If it
 *     ever starts emitting, every classic render grows HTML comments and
 *     the rollback contract behind `MatchExecutionLayout` is gone.
 *   - `splitOnCuts()` must keep the html that FOLLOWS each marker with
 *     that marker, drop what precedes the first one, and survive a marker
 *     whose section did not render — five sections sit inside
 *     `PENDING_REVIEW` conditionals, so an absent marker is normal rather
 *     than exceptional.
 */
final class MatchExecutionSectionSplitTest extends WP_UnitTestCase {

    /** @return array<string,string> */
    private function split( string $html ): array {
        $m = new ReflectionMethod( FrontendMatchExecutionView::class, 'splitOnCuts' );
        $m->setAccessible( true );
        /** @var array<string,string> $out */
        $out = $m->invoke( null, $html );
        return $out;
    }

    private function cut( string $name, bool $sectioned ): string {
        $flag = new ReflectionProperty( FrontendMatchExecutionView::class, 'sectioned' );
        $flag->setAccessible( true );
        $was = $flag->getValue();
        $flag->setValue( null, $sectioned );

        $m = new ReflectionMethod( FrontendMatchExecutionView::class, 'cut' );
        $m->setAccessible( true );
        ob_start();
        $m->invoke( null, $name );
        $out = (string) ob_get_clean();

        $flag->setValue( null, $was );
        return $out;
    }

    public function test_cut_emits_nothing_under_the_classic_layout(): void {
        $this->assertSame( '', $this->cut( 'squad', false ) );
    }

    public function test_cut_emits_a_marker_under_the_sectioned_layout(): void {
        $this->assertSame( '<!--tt-cut:squad-->', $this->cut( 'squad', true ) );
    }

    public function test_each_marker_keeps_the_html_that_follows_it(): void {
        $parts = $this->split(
            '<!--tt-cut:score--><section id="s"></section>'
            . '<!--tt-cut:timer--><section id="t"></section>'
        );

        $this->assertSame( [ 'score', 'timer' ], array_keys( $parts ) );
        $this->assertSame( '<section id="s"></section>', $parts['score'] );
        $this->assertSame( '<section id="t"></section>', $parts['timer'] );
    }

    public function test_html_before_the_first_marker_is_dropped(): void {
        $parts = $this->split( "\n  <!--tt-cut:score--><b>x</b>" );

        $this->assertSame( [ 'score' ], array_keys( $parts ) );
        $this->assertSame( '<b>x</b>', $parts['score'] );
    }

    /**
     * A section inside a `PENDING_REVIEW` conditional contributes its
     * marker but no content when the branch does not run. That has to come
     * back as an empty string, not a missing key — `renderSectioned()`
     * concatenates several parts per panel and a null would be a warning.
     */
    public function test_a_marker_with_no_content_yields_an_empty_string(): void {
        $parts = $this->split( '<!--tt-cut:review--><!--tt-cut:late--><b>x</b>' );

        $this->assertSame( '', $parts['review'] );
        $this->assertSame( '<b>x</b>', $parts['late'] );
    }

    public function test_a_body_with_no_markers_yields_nothing(): void {
        $this->assertSame( [], $this->split( '<section>only classic here</section>' ) );
    }

    public function test_an_empty_body_yields_nothing(): void {
        $this->assertSame( [], $this->split( '' ) );
    }
}
