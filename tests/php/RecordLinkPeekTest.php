<?php
/**
 * Peek attribute on cross-entity links (#2458).
 *
 * Peek is adopted by teaching RecordLink rather than by editing dozens of
 * call sites, so what needs pinning is that the helper marks exactly the
 * record types with a summary endpoint and leaves everything else — and
 * every href — untouched.
 */

use TT\Shared\Frontend\Components\RecordLink;

class RecordLinkPeekTest extends WP_UnitTestCase {

    public function test_marks_the_record_types_that_have_a_summary_endpoint(): void {
        foreach ( [ 'players', 'teams', 'activities' ] as $slug ) {
            $html = RecordLink::inline( 'Label', RecordLink::detailUrlFor( $slug, 7 ) );
            $this->assertStringContainsString( 'data-tt-peek="' . $slug . ':7"', $html, $slug . ' should be peekable' );
        }
    }

    public function test_leaves_other_record_types_alone(): void {
        $html = RecordLink::inline( 'Label', RecordLink::detailUrlFor( 'goals', 7 ) );

        $this->assertStringNotContainsString( 'data-tt-peek', $html );
        $this->assertStringContainsString( 'tt_view=goals', html_entity_decode( $html ) );
    }

    public function test_the_href_is_unchanged_and_still_works_without_js(): void {
        $url  = RecordLink::detailUrlFor( 'players', 42 );
        $html = RecordLink::inline( 'Sem de Vries', $url );

        // Peek is layered on a working link, never a substitute for one.
        $this->assertStringContainsString( 'href="' . esc_url( $url ) . '"', $html );
    }

    public function test_ignores_urls_without_a_record_id(): void {
        $listing = add_query_arg( [ 'tt_view' => 'players' ], home_url( '/' ) );

        $this->assertStringNotContainsString( 'data-tt-peek', RecordLink::inline( 'Players', $listing ) );
    }

    public function test_ignores_a_non_zero_but_invalid_id(): void {
        $bad = add_query_arg( [ 'tt_view' => 'players', 'id' => 'abc' ], home_url( '/' ) );

        $this->assertStringNotContainsString( 'data-tt-peek', RecordLink::inline( 'Players', $bad ) );
    }

    public function test_wrap_marks_links_the_same_way(): void {
        ob_start();
        RecordLink::wrap( RecordLink::detailUrlFor( 'teams', 3 ) );
        RecordLink::close();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'data-tt-peek="teams:3"', $html );
    }

    public function test_a_back_carrying_url_is_still_peekable(): void {
        // detailUrlForWithBack() appends tt_back; the tt_view + id pair is
        // still what decides, so the two features compose.
        $html = RecordLink::inline( 'Sem', RecordLink::detailUrlForWithBack( 'players', 9 ) );

        $this->assertStringContainsString( 'data-tt-peek="players:9"', $html );
    }

    public function test_an_empty_label_or_url_renders_no_link_at_all(): void {
        $this->assertStringNotContainsString( '<a', RecordLink::inline( '', 'https://example.test/' ) );
        $this->assertStringNotContainsString( '<a', RecordLink::inline( 'Label', '' ) );
    }
}
