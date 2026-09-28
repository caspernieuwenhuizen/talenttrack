<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Modules\Analytics\Reports\PlayerReport;

/**
 * #4149 — the player report's "Same position" line names the positions
 * instead of printing their stored keys.
 *
 * `positionGroupLabel()` imploded `comparison.position.positions` as
 * stored, so the monthly report read `Same position ·
 * RECHTER_MIDDENVELDER, CAM · 5 players`. The group carries codes (they
 * are the matching key in `shareComparison()`, upper-cased there); the
 * label resolves each through `LabelTranslator::positionLabel()`.
 *
 * The operator-label path (a lookup row with a translation winning) is not
 * asserted here for the reason `PositionLabelResolutionTest` gives: the
 * lookup row cache is a process-lifetime static that makes a
 * translation-backed assertion depend on test order. What is pinned is that
 * a seeded code reads as its long form and an academy key never reaches the
 * page raw, whichever case it arrives in.
 */
final class PositionGroupLabelTest extends WP_UnitTestCase {

    public function test_a_seeded_code_reads_as_its_long_form(): void {
        $label = PlayerReport::positionGroupLabel( [ 'positions' => [ 'CAM' ], 'count' => 3, 'share' => 40 ] );

        $this->assertStringContainsString( 'Attacking midfielder', $label );
        $this->assertStringNotContainsString( 'CAM', $label );
    }

    public function test_an_academy_key_is_named_not_printed(): void {
        // Upper-cased, as shareComparison() hands it over.
        $label = PlayerReport::positionGroupLabel( [
            'positions' => [ 'RECHTER_MIDDENVELDER', 'CAM' ],
            'count'     => 5,
            'share'     => 50,
        ] );

        $this->assertSame( 'Same position · Rechter Middenvelder, Attacking midfielder · 5 players', $label );
        $this->assertStringNotContainsString( '_', $label );
    }

    public function test_the_primary_position_reads_the_json_column(): void {
        $this->assertSame( 'Centre back', LabelTranslator::primaryPositionLabel( '["CB","RB"]' ) );
        $this->assertSame( 'Right back', LabelTranslator::primaryPositionLabel( 'RB, CB' ) );
        $this->assertSame( 'Linker Middenvelder', LabelTranslator::primaryPositionLabel( '["linker_middenvelder"]' ) );
        $this->assertSame( '', LabelTranslator::primaryPositionLabel( '' ) );
        $this->assertSame( '', LabelTranslator::primaryPositionLabel( '[]' ) );
    }
}
