<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\MatchPrep\Frontend\FrontendMatchPrepView;

/**
 * #2099 — slots_json becomes the authoritative pitch geometry.
 *
 * The match-prep pitch positioned players from a hardcoded shape-keyed
 * table, so every template sharing a shape string (e.g. the flat 3-4-3 and
 * the 3-4-3 diamond) collapsed onto one flat layout. The fix reads the
 * template's own `slots_json`; `parseSlotsJson()` is the pure conversion at
 * the heart of it. These tests pin its contract:
 *
 *   - a num-bearing layout scales pos 0..1 → 0..100 and keeps num + label;
 *   - a layout WITHOUT slot numbers returns null, so callers fall back to
 *     the shape default rather than mis-aligning the lineup.
 */
final class MatchPrepSlotLayoutTest extends WP_UnitTestCase {

    public function test_num_bearing_json_scales_to_percentages(): void {
        $json = wp_json_encode( [
            [ 'label' => 'AM', 'num' => 10, 'pos' => [ 'x' => 0.50, 'y' => 0.40 ] ],
            [ 'label' => 'DM', 'num' => 4,  'pos' => [ 'x' => 0.50, 'y' => 0.66 ] ],
        ] );

        $layout = FrontendMatchPrepView::parseSlotsJson( $json );

        $this->assertIsArray( $layout );
        $this->assertCount( 2, $layout );

        $this->assertSame( 10, $layout[0]['num'] );
        $this->assertSame( 'AM', $layout[0]['label'] );
        $this->assertEqualsWithDelta( 50.0, $layout[0]['x'], 0.001 );
        $this->assertEqualsWithDelta( 40.0, $layout[0]['y'], 0.001 );

        $this->assertSame( 4, $layout[1]['num'] );
        $this->assertEqualsWithDelta( 66.0, $layout[1]['y'], 0.001 );
    }

    public function test_layout_without_slot_numbers_returns_null(): void {
        // The seeded shape — labels + positions but no `num`. Must fall back.
        $json = wp_json_encode( [
            [ 'label' => 'AM', 'pos' => [ 'x' => 0.50, 'y' => 0.40 ] ],
            [ 'label' => 'DM', 'pos' => [ 'x' => 0.50, 'y' => 0.66 ] ],
        ] );

        $this->assertNull( FrontendMatchPrepView::parseSlotsJson( $json ) );
    }

    public function test_partial_numbers_returns_null(): void {
        // One slot numbered, one not — can't align the lineup, so fall back.
        $json = wp_json_encode( [
            [ 'label' => 'AM', 'num' => 10, 'pos' => [ 'x' => 0.50, 'y' => 0.40 ] ],
            [ 'label' => 'DM', 'pos' => [ 'x' => 0.50, 'y' => 0.66 ] ],
        ] );

        $this->assertNull( FrontendMatchPrepView::parseSlotsJson( $json ) );
    }

    public function test_empty_or_invalid_json_returns_null(): void {
        $this->assertNull( FrontendMatchPrepView::parseSlotsJson( '' ) );
        $this->assertNull( FrontendMatchPrepView::parseSlotsJson( 'not-json' ) );
        $this->assertNull( FrontendMatchPrepView::parseSlotsJson( '[]' ) );
    }
}
