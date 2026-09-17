<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlockOptions;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;

/**
 * #3514 (epic #3513) — the composition can carry per-block options.
 *
 * The property that matters most is the one that is easiest to lose: **a
 * composition with no options behaves exactly as it did before this existed.**
 * Every saved view, every schedule and every bookmarked URL in the product
 * predates options, and all of them must still open the report they always
 * opened.
 *
 * These pin the **carrier**, not one block's use of it: what a composition
 * does with an option bag, not what any option means. `tests` is the only
 * block that owns options (#3515), and what its options do is
 * `TestsBlockOptionsTest`'s job.
 */
final class TeamMonthlyReportBlockOptionsTest extends WP_UnitTestCase {

    /** @return array<string,mixed> */
    private function raw( array $overrides = [] ): array {
        return array_merge( [
            'team_id' => 7,
            'period'  => 'last_month',
            'layout'  => 'A',
            'blocks'  => [ 'kpi', 'tests' ],
        ], $overrides );
    }

    // ── nothing changes without options ────────────────────────────────

    public function test_a_composition_with_no_options_is_unchanged(): void {
        $before = $this->raw();
        $after  = TeamMonthlyReportComposition::normalise( $before );

        $this->assertSame( [], $after['options'], 'No options recorded means none carried.' );
        $this->assertSame( [ 'kpi', 'tests' ], $after['blocks'] );
        $this->assertSame( 7, $after['team_id'] );
        $this->assertSame( 'last_month', $after['period'] );
        $this->assertSame( 'A', $after['layout'] );
    }

    /**
     * A view saved before options existed has no `options` key at all. It must
     * normalise, not warn or fail.
     */
    public function test_a_composition_saved_before_options_existed_still_opens(): void {
        $stored = [ 'team_id' => 3, 'period' => 'last_month', 'layout' => 'B', 'blocks' => [ 'roster' ] ];

        $this->assertSame( [], TeamMonthlyReportComposition::normalise( $stored )['options'] );
    }

    // ── carrying options ───────────────────────────────────────────────

    /**
     * A bag aimed at a block with no owner normalises away — the owner decides
     * what is valid, and there is none. The point is that an unowned bag is
     * *dropped*, not that it invalidates the composition around it.
     *
     * `roster` stands in for "no owner" because `tests` acquired one in #3515.
     */
    public function test_an_unowned_option_bag_is_dropped_and_the_rest_survives(): void {
        $c = TeamMonthlyReportComposition::normalise( $this->raw( [
            'blocks'  => [ 'kpi', 'roster' ],
            'options' => [ 'roster' => [ 'definitions' => [ 4, 9 ] ] ],
        ] ) );

        $this->assertSame( [], $c['options'] );
        $this->assertSame( [ 'kpi', 'roster' ], $c['blocks'] );
    }

    /**
     * A URL and a saved view describe the same composition, so the JSON form
     * and the array form must normalise identically. The value itself belongs
     * to `TestsBlockOptions` and is covered by its own test.
     */
    public function test_options_accept_the_json_a_url_carries(): void {
        $from_json = TeamMonthlyReportComposition::normalise( $this->raw( [
            'options' => '{"tests":{"definitions":[4]}}',
        ] ) );
        $from_array = TeamMonthlyReportComposition::normalise( $this->raw( [
            'options' => [ 'tests' => [ 'definitions' => [ 4 ] ] ],
        ] ) );

        $this->assertSame( $from_array['options'], $from_json['options'] );
        $this->assertSame( [ 4 ], $from_json['options']['tests']['definitions'] );
    }

    public function test_malformed_json_is_dropped_rather_than_fatal(): void {
        $c = TeamMonthlyReportComposition::normalise( $this->raw( [ 'options' => 'not json {' ] ) );

        $this->assertSame( [], $c['options'] );
        $this->assertSame( [ 'kpi', 'tests' ], $c['blocks'], 'The rest of the composition survives.' );
    }

    public function test_options_for_an_unknown_block_are_dropped(): void {
        $c = TeamMonthlyReportComposition::normalise( $this->raw( [
            'options' => [ 'nonsense' => [ 'a' => 1 ] ],
        ] ) );

        $this->assertArrayNotHasKey( 'nonsense', $c['options'] );
    }

    // ── the registry ───────────────────────────────────────────────────

    /**
     * Two blocks own options so far: `tests` (#3515) and `matches` (#3516).
     * This is not a rule about which — it is a reminder that every other block
     * still renders from the block list alone, so giving one block options
     * must not change what the rest do.
     */
    public function test_only_the_registered_blocks_accept_options(): void {
        $accepting = array_values( array_filter(
            TeamMonthlyReportBlock::ALL,
            static fn( string $block ): bool => TeamMonthlyReportBlockOptions::accepts( $block )
        ) );
        sort( $accepting );

        $this->assertSame(
            [ TeamMonthlyReportBlock::MATCHES, TeamMonthlyReportBlock::TESTS ],
            $accepting
        );
    }

    /**
     * Options aimed at a block that accepts none are all unknown. A caller
     * that wants to be strict — a schedule being saved, say — can then say
     * *which* option was not understood rather than rendering a report quietly
     * missing what was asked for.
     */
    public function test_unknown_options_are_reported_with_their_block(): void {
        $unknown = TeamMonthlyReportComposition::unknownOptions( [
            'options' => [ 'kpi' => [ 'nonsense' => 1 ] ],
        ] );

        $this->assertSame( [ 'kpi.nonsense' ], $unknown );
    }

    public function test_unknown_options_report_an_unknown_block_by_name(): void {
        $unknown = TeamMonthlyReportComposition::unknownOptions( [
            'options' => [ 'not_a_block' => [ 'x' => 1 ] ],
        ] );

        $this->assertSame( [ 'not_a_block' ], $unknown );
    }

    // ── identity ───────────────────────────────────────────────────────

    /**
     * Without this, a saved view showing one test would report itself "active"
     * while the reader is looking at a different one.
     */
    public function test_compositions_differing_only_in_options_are_not_the_same(): void {
        $a = TeamMonthlyReportComposition::normalise( $this->raw() );
        $b = TeamMonthlyReportComposition::normalise( $this->raw() );
        $this->assertTrue( TeamMonthlyReportComposition::same( $a, $b ) );

        $b = TeamMonthlyReportComposition::normalise( $this->raw( [
            'options' => [ 'tests' => [ 'definitions' => [ 4 ] ] ],
        ] ) );
        $this->assertFalse(
            TeamMonthlyReportComposition::same( $a, $b ),
            'Options are part of what a composition is.'
        );
    }

    public function test_optionsFor_always_answers_an_array(): void {
        $c = TeamMonthlyReportComposition::normalise( $this->raw() );

        $this->assertSame( [], TeamMonthlyReportComposition::optionsFor( $c, 'tests' ) );
        $this->assertSame( [], TeamMonthlyReportComposition::optionsFor( $c, 'kpi' ) );
    }

    public function test_params_carries_options(): void {
        $this->assertContains( 'options', TeamMonthlyReportComposition::PARAMS );
    }
}
