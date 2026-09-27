<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\EvaluationsBlockOptions;
use TT\Modules\Analytics\Reports\LevelBlockOptions;
use TT\Modules\Analytics\Reports\MatchesBlockOptions;
use TT\Modules\Analytics\Reports\SectionLevel;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDelivery;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;

/**
 * #4133 (epic #4094) — one Summary / Details choice per section.
 *
 * Pinned: each section's default is what it printed before the choice
 * existed, recorded as absence; the stored options of saved compositions,
 * snapshots and schedules map onto the level without changing what prints;
 * the layout rule says where Details cannot print and why; and the schedule
 * form names each section's level.
 */
final class TeamMonthlyReportSectionLevelTest extends WP_UnitTestCase {

    public function test_the_defaults_are_what_each_section_printed_before(): void {
        $this->assertSame( SectionLevel::DETAILS, LevelBlockOptions::level( [] ) );
        $this->assertSame( SectionLevel::DETAILS, MatchesBlockOptions::level( [] ) );
        $this->assertSame( SectionLevel::SUMMARY, EvaluationsBlockOptions::level( [] ) );

        $this->assertSame( [], LevelBlockOptions::normalise( [ 'level' => 'details' ] ) );
        $this->assertSame( [ 'level' => 'summary' ], LevelBlockOptions::normalise( [ 'level' => 'Summary ' ] ) );
        $this->assertSame( [], LevelBlockOptions::normalise( [ 'level' => 'everything' ] ) );
        $this->assertSame( [], MatchesBlockOptions::normalise( [ 'level' => 'details' ] ) );
        $this->assertSame( [], EvaluationsBlockOptions::normalise( [ 'level' => 'summary', 'sub' => true ] ), 'subcategories belong to Details' );
    }

    /** A matches bag from before the level: scorers and squads off is the summary. */
    public function test_an_old_matches_bag_maps_onto_the_level(): void {
        $this->assertSame( [ 'level' => 'summary' ], MatchesBlockOptions::normalise( [ 'scorers' => false ] ) );
        $this->assertSame( [ 'level' => 'summary', 'record' => false ], MatchesBlockOptions::normalise( [ 'record' => false, 'scorers' => false ] ) );
        $this->assertSame( [ 'squads' => true ], MatchesBlockOptions::normalise( [ 'squads' => true ] ) );

        // It prints what it printed.
        $old = [ 'scorers' => false ];
        $this->assertTrue( MatchesBlockOptions::shows( $old, MatchesBlockOptions::RECORD ) );
        $this->assertFalse( MatchesBlockOptions::shows( $old, MatchesBlockOptions::SCORERS ) );
        $this->assertFalse( MatchesBlockOptions::shows( $old, MatchesBlockOptions::SQUADS ) );

        // Under the summary the detail parts print nothing, whatever they say.
        $this->assertFalse( MatchesBlockOptions::shows( [ 'level' => 'summary', 'squads' => true ], MatchesBlockOptions::SQUADS ) );
    }

    /** An old saved composition and its mapped form are the same report. */
    public function test_an_old_composition_and_its_mapped_form_compare_equal(): void {
        $old = TeamMonthlyReportComposition::normalise( [
            'team_id' => 4, 'blocks' => [ 'tests', 'matches' ],
            'options' => [ 'tests' => [ 'show' => 'values_trend' ], 'matches' => [ 'scorers' => false ] ],
        ] );
        $new = TeamMonthlyReportComposition::normalise( [
            'team_id' => 4, 'blocks' => [ 'tests', 'matches' ],
            'options' => [ 'tests' => [ 'level' => 'details' ], 'matches' => [ 'level' => 'summary' ] ],
        ] );
        $this->assertTrue( TeamMonthlyReportComposition::same( $old, $new ) );
    }

    public function test_the_layout_says_where_details_cannot_print(): void {
        foreach ( [ 'tests', 'evaluations' ] as $block ) {
            $this->assertTrue( TeamMonthlyReportLayout::supportsDetails( 'B', $block ) );
            $this->assertFalse( TeamMonthlyReportLayout::supportsDetails( 'A', $block ) );
            $this->assertFalse( TeamMonthlyReportLayout::supportsDetails( 'C', $block ) );
            $this->assertNotSame( '', TeamMonthlyReportLayout::detailsReason( 'A', $block ) );
            $this->assertSame( 'summary', TeamMonthlyReportLayout::levelFor( 'C', $block, 'details' ) );
        }
        foreach ( [ 'attendance', 'minutes' ] as $block ) {
            $this->assertTrue( TeamMonthlyReportLayout::supportsDetails( 'A', $block ), 'the one-pager prints the bars, elided when it must' );
            $this->assertFalse( TeamMonthlyReportLayout::supportsDetails( 'C', $block ), 'landscape prints them as roster columns' );
        }
        foreach ( TeamMonthlyReportLayout::ALL as $layout ) {
            $this->assertTrue( TeamMonthlyReportLayout::supportsDetails( $layout, 'matches' ) );
        }
    }

    /** The schedule form names each section's level, and a level the layout cannot print. */
    public function test_the_schedule_form_names_each_level(): void {
        $composition = TeamMonthlyReportComposition::forSchedule( [
            'team_id' => 4, 'layout' => 'A', 'blocks' => 'attendance,matches,tests',
            'options' => [ 'attendance' => [ 'level' => 'summary' ], 'tests' => [ 'level' => 'details' ] ],
        ] );
        $lines = TeamMonthlyReportDelivery::optionLines( $composition );

        $this->assertCount( 3, $lines );
        $this->assertSame( 'Attendance: Summary.', $lines[0]['text'] );
        $this->assertStringStartsWith( 'Matches: Details', $lines[1]['text'] );
        $this->assertStringStartsWith( 'Tests: Details', $lines[2]['text'] );
        $this->assertNotSame( '', $lines[2]['note'], 'the one-pager prints the tests summary, and the form says so' );
        $this->assertSame( '', $lines[0]['note'] );
    }
}
