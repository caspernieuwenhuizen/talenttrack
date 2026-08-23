<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Export\ExportValueFormatter;

/**
 * #2012 — human-facing exports carry labels, not database codes.
 *
 * The headers always went through `__()`; the values did not, so a Dutch
 * install produced a file whose column titles read Dutch and whose contents
 * read `active` and `["CB","LB"]`. The export is usually the artifact that
 * leaves the academy — parents, federation desks, the board — which is the
 * worst place for a raw code.
 *
 * These assertions run in the test suite's English locale, so what they pin
 * is not the Dutch wording but the *mechanics* that silently fail: the
 * format each helper expects, and the JSON decode. A helper handed the
 * wrong format returns its input unchanged, which looks exactly like a
 * working export.
 */
final class ExportValueFormatterTest extends WP_UnitTestCase {

    /**
     * `tt_goals.status` stores "In Progress"; `LabelTranslator::goalStatus()`
     * switches on "in_progress". Without normalisation the call is a no-op —
     * the defect this test exists for.
     */
    public function test_goal_status_is_normalised_before_lookup(): void {
        $this->assertSame( 'In Progress', ExportValueFormatter::goalStatus( 'In Progress' ) );
        $this->assertSame( 'On Hold', ExportValueFormatter::goalStatus( 'On Hold' ) );

        // The proof it routed through the switch rather than falling through:
        // humanise() would title-case the raw code, not resolve the label.
        $this->assertNotSame( 'In_progress', ExportValueFormatter::goalStatus( 'In Progress' ) );
    }

    public function test_positions_decode_from_json_and_join(): void {
        $this->assertSame(
            'Centre back / Left back',
            ExportValueFormatter::positions( '["CB","LB"]' )
        );
        $this->assertSame( 'Goalkeeper', ExportValueFormatter::positions( '["GK"]' ) );
    }

    /**
     * An export that silently drops a position it does not recognise is worse
     * than one showing the raw code — the code is at least actionable.
     */
    public function test_unknown_position_codes_survive(): void {
        $this->assertSame( 'ZZ', ExportValueFormatter::positions( '["ZZ"]' ) );
        $this->assertSame( 'Goalkeeper / ZZ', ExportValueFormatter::positions( '["GK","ZZ"]' ) );
    }

    public function test_empty_and_malformed_values_stay_empty(): void {
        $this->assertSame( '', ExportValueFormatter::positions( '' ) );
        $this->assertSame( '', ExportValueFormatter::positions( null ) );
        $this->assertSame( '', ExportValueFormatter::playerStatus( '' ) );
        $this->assertSame( '', ExportValueFormatter::goalStatus( null ) );
        $this->assertSame( '', ExportValueFormatter::attendanceStatus( '' ) );
    }

    /**
     * A row written before positions became JSON holds a bare or
     * comma-separated code. It still has to render.
     */
    public function test_legacy_non_json_positions_still_render(): void {
        $this->assertSame( 'Goalkeeper', ExportValueFormatter::positions( 'GK' ) );
        $this->assertSame( 'Centre back / Left back', ExportValueFormatter::positions( 'CB, LB' ) );
    }

    /**
     * `tt_attendance.status` is stored capitalised, which is what the helper
     * switches on. Pinned because the reverse assumption would be invisible.
     */
    public function test_attendance_status_matches_the_stored_case(): void {
        $this->assertSame( 'Present', ExportValueFormatter::attendanceStatus( 'Present' ) );
        $this->assertSame( 'Absent', ExportValueFormatter::attendanceStatus( 'Absent' ) );
    }

    public function test_player_and_person_status_resolve(): void {
        $this->assertSame( 'Active', ExportValueFormatter::playerStatus( 'active' ) );
        $this->assertSame( 'Active', ExportValueFormatter::personStatus( 'active' ) );
    }
}
