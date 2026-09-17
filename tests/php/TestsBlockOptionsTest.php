<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlockOptions;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;
use TT\Modules\Analytics\Reports\TestsBlockOptions;

/**
 * #3515 (epic #3513) — telling the tests section which tests, and how much.
 *
 * The behaviour worth pinning is not the happy path. It is what happens to a
 * composition saved months ago, because that is the feature: a coach saves the
 * report once and reopens it every month. So: a bag from before these options
 * existed, a `show` value this version does not know, and a definition that has
 * since been deleted all have to open a report rather than fail.
 */
final class TestsBlockOptionsTest extends WP_UnitTestCase {

    /** @return array<string,mixed> */
    private function bag( array $raw ): array {
        return TestsBlockOptions::normalise( $raw );
    }

    // ── the default is absence ─────────────────────────────────────────

    public function test_no_options_is_an_empty_bag(): void {
        $this->assertSame( [], $this->bag( [] ) );
    }

    /**
     * The default is recorded as absence, not as `show=summary`. Otherwise two
     * compositions that render the same report hash differently and a saved
     * view stops reporting itself as active.
     */
    public function test_asking_for_the_default_records_nothing(): void {
        $this->assertSame( [], $this->bag( [ 'show' => 'summary' ] ) );
        $this->assertSame( [], $this->bag( [ 'definitions' => [] ] ) );
    }

    public function test_a_default_and_an_absent_option_are_the_same_report(): void {
        $explicit = TeamMonthlyReportComposition::normalise( [
            'team_id' => 4,
            'blocks'  => [ 'tests' ],
            'options' => [ 'tests' => [ 'show' => 'summary' ] ],
        ] );
        $absent = TeamMonthlyReportComposition::normalise( [
            'team_id' => 4,
            'blocks'  => [ 'tests' ],
        ] );

        $this->assertTrue( TeamMonthlyReportComposition::same( $explicit, $absent ) );
    }

    // ── which tests ────────────────────────────────────────────────────

    public function test_definitions_are_kept_in_the_order_given(): void {
        $this->assertSame( [ 9, 2, 7 ], $this->bag( [ 'definitions' => [ 9, 2, 7 ] ] )['definitions'] );
    }

    /** The same option arrives from a checkbox group, a URL and stored JSON. */
    public function test_definitions_may_arrive_as_a_comma_separated_string(): void {
        $this->assertSame( [ 3, 5 ], $this->bag( [ 'definitions' => '3, 5' ] )['definitions'] );
    }

    public function test_duplicate_and_junk_definitions_are_dropped(): void {
        $bag = $this->bag( [ 'definitions' => [ 4, 4, 0, -2, 'x', 6 ] ] );

        $this->assertSame( [ 4, 6 ], $bag['definitions'] );
    }

    /** A composition naming fifty tests is a hand-edited URL, not a choice. */
    public function test_an_absurd_number_of_definitions_is_capped(): void {
        $bag = $this->bag( [ 'definitions' => range( 1, 100 ) ] );

        $this->assertCount( 20, $bag['definitions'] );
    }

    // ── how much ───────────────────────────────────────────────────────

    public function test_each_show_value_is_kept(): void {
        foreach ( [ 'values', 'trend', 'values_trend' ] as $show ) {
            $this->assertSame( $show, $this->bag( [ 'show' => $show ] )['show'], $show );
        }
    }

    /** A value from a later version of the report, or a typo. */
    public function test_an_unknown_show_value_falls_back_to_the_summary(): void {
        $this->assertSame( [], $this->bag( [ 'show' => 'everything' ] ) );
        $this->assertSame( TestsBlockOptions::SHOW_SUMMARY, TestsBlockOptions::show( [ 'show' => 'everything' ] ) );
    }

    public function test_a_bag_saved_before_show_existed_reads_as_the_summary(): void {
        $this->assertSame( TestsBlockOptions::SHOW_SUMMARY, TestsBlockOptions::show( [ 'definitions' => [ 3 ] ] ) );
    }

    public function test_show_survives_a_non_scalar(): void {
        $this->assertSame( [], $this->bag( [ 'show' => [ 'values' ] ] ) );
    }

    // ── which columns each value means ─────────────────────────────────

    public function test_summary_puts_no_player_table_on_the_page(): void {
        $this->assertFalse( TestsBlockOptions::showsPlayers( TestsBlockOptions::SHOW_SUMMARY ) );
        $this->assertFalse( TestsBlockOptions::showsValues( TestsBlockOptions::SHOW_SUMMARY ) );
        $this->assertFalse( TestsBlockOptions::showsTrend( TestsBlockOptions::SHOW_SUMMARY ) );
    }

    public function test_values_shows_readings_only(): void {
        $this->assertTrue( TestsBlockOptions::showsValues( TestsBlockOptions::SHOW_VALUES ) );
        $this->assertFalse( TestsBlockOptions::showsTrend( TestsBlockOptions::SHOW_VALUES ) );
    }

    public function test_trend_shows_change_only(): void {
        $this->assertFalse( TestsBlockOptions::showsValues( TestsBlockOptions::SHOW_TREND ) );
        $this->assertTrue( TestsBlockOptions::showsTrend( TestsBlockOptions::SHOW_TREND ) );
    }

    public function test_values_trend_shows_both(): void {
        $this->assertTrue( TestsBlockOptions::showsValues( TestsBlockOptions::SHOW_VALUES_TREND ) );
        $this->assertTrue( TestsBlockOptions::showsTrend( TestsBlockOptions::SHOW_VALUES_TREND ) );
    }

    // ── unknown keys ───────────────────────────────────────────────────

    public function test_an_unknown_key_is_reported_not_dropped_silently(): void {
        $this->assertSame( [ 'definitons' ], TestsBlockOptions::unknownKeys( [ 'definitons' => [ 3 ] ] ) );
    }

    public function test_the_known_keys_are_not_reported(): void {
        $this->assertSame( [], TestsBlockOptions::unknownKeys( [ 'definitions' => [ 3 ], 'show' => 'values' ] ) );
    }

    /** An unknown *value* is expected — a deleted test — and is not an error. */
    public function test_an_unknown_value_is_not_an_unknown_key(): void {
        $this->assertSame( [], TestsBlockOptions::unknownKeys( [ 'show' => 'nonsense' ] ) );
    }

    // ── the block is wired into the registry ───────────────────────────

    public function test_the_tests_block_now_accepts_options(): void {
        $this->assertTrue( TeamMonthlyReportBlockOptions::accepts( TeamMonthlyReportBlock::TESTS ) );
    }

    public function test_the_registry_prefixes_an_unknown_key_with_the_block(): void {
        $unknown = TeamMonthlyReportBlockOptions::unknownKeys( TeamMonthlyReportBlock::TESTS, [ 'nope' => 1 ] );

        $this->assertSame( [ 'tests.nope' ], $unknown );
    }

    public function test_another_block_still_accepts_nothing(): void {
        $this->assertFalse( TeamMonthlyReportBlockOptions::accepts( TeamMonthlyReportBlock::ROSTER ) );
    }

    // ── through the composition ────────────────────────────────────────

    public function test_options_survive_a_round_trip_through_the_composition(): void {
        $composition = TeamMonthlyReportComposition::normalise( [
            'team_id' => 7,
            'blocks'  => [ 'tests' ],
            'options' => [ 'tests' => [ 'definitions' => [ 5, 9 ], 'show' => 'values_trend' ] ],
        ] );

        $bag = TeamMonthlyReportComposition::optionsFor( $composition, 'tests' );

        $this->assertSame( [ 5, 9 ], $bag['definitions'] );
        $this->assertSame( 'values_trend', $bag['show'] );
    }

    /** Options for a section the reader switched off describe nothing. */
    public function test_options_for_an_unselected_block_are_dropped(): void {
        $composition = TeamMonthlyReportComposition::normalise( [
            'team_id' => 7,
            'blocks'  => [ 'kpi' ],
            'options' => [ 'tests' => [ 'show' => 'values' ] ],
        ] );

        $this->assertSame( [], $composition['options'] );
    }

    public function test_two_compositions_showing_different_tests_are_not_the_same(): void {
        $sprint = TeamMonthlyReportComposition::normalise( [
            'team_id' => 7, 'blocks' => [ 'tests' ], 'options' => [ 'tests' => [ 'definitions' => [ 5 ] ] ],
        ] );
        $jump = TeamMonthlyReportComposition::normalise( [
            'team_id' => 7, 'blocks' => [ 'tests' ], 'options' => [ 'tests' => [ 'definitions' => [ 9 ] ] ],
        ] );

        $this->assertFalse( TeamMonthlyReportComposition::same( $sprint, $jump ) );
    }

    public function test_malformed_json_leaves_the_report_renderable(): void {
        $composition = TeamMonthlyReportComposition::normalise( [
            'team_id' => 7,
            'blocks'  => [ 'tests' ],
            'options' => '{"tests":{"definitions":',
        ] );

        $this->assertSame( [], $composition['options'] );
        $this->assertSame( [ 'tests' ], $composition['blocks'] );
    }
}
