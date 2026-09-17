<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\MatchesBlockOptions;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlockOptions;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;

/**
 * #3516 (epic #3513) — how much of the match section to print.
 *
 * Two things are worth pinning. **Squads default off**, because they are the
 * longest part of the section and repeat the minutes block; a change to that
 * default would quietly double the length of every full report. And **the
 * defaults are stored as absence**, so a composition holding only defaults is
 * the same report as one holding nothing, and a saved view keeps recognising
 * itself.
 */
final class MatchesBlockOptionsTest extends WP_UnitTestCase {

    public function test_the_section_shows_the_record_and_scorers_by_default(): void {
        $this->assertTrue( MatchesBlockOptions::shows( [], MatchesBlockOptions::RECORD ) );
        $this->assertTrue( MatchesBlockOptions::shows( [], MatchesBlockOptions::SCORERS ) );
    }

    /**
     * The squad tables repeat the minutes block and are the longest thing the
     * section prints, so a full report must not include them unasked.
     */
    public function test_squads_are_off_by_default(): void {
        $this->assertFalse( MatchesBlockOptions::shows( [], MatchesBlockOptions::SQUADS ) );
    }

    // ── absence is the default ─────────────────────────────────────────

    public function test_asking_for_the_defaults_records_nothing(): void {
        $bag = MatchesBlockOptions::normalise( [ 'record' => true, 'scorers' => true, 'squads' => false ] );

        $this->assertSame( [], $bag );
    }

    public function test_only_a_departure_from_the_default_is_recorded(): void {
        $bag = MatchesBlockOptions::normalise( [ 'record' => true, 'scorers' => false, 'squads' => true ] );

        $this->assertSame( [ 'scorers' => false, 'squads' => true ], $bag );
    }

    public function test_a_default_and_an_absent_option_are_the_same_report(): void {
        $explicit = TeamMonthlyReportComposition::normalise( [
            'team_id' => 3,
            'blocks'  => [ 'matches' ],
            'options' => [ 'matches' => [ 'record' => true, 'squads' => false ] ],
        ] );
        $absent = TeamMonthlyReportComposition::normalise( [
            'team_id' => 3,
            'blocks'  => [ 'matches' ],
        ] );

        $this->assertTrue( TeamMonthlyReportComposition::same( $explicit, $absent ) );
    }

    public function test_two_compositions_differing_in_squads_are_not_the_same(): void {
        $with = TeamMonthlyReportComposition::normalise( [
            'team_id' => 3, 'blocks' => [ 'matches' ], 'options' => [ 'matches' => [ 'squads' => true ] ],
        ] );
        $without = TeamMonthlyReportComposition::normalise( [
            'team_id' => 3, 'blocks' => [ 'matches' ],
        ] );

        $this->assertFalse( TeamMonthlyReportComposition::same( $with, $without ) );
    }

    // ── the shapes a switch arrives in ─────────────────────────────────

    /** A checkbox, a URL and stored JSON all say "off" differently. */
    public function test_a_switch_is_read_from_every_shape_it_arrives_in(): void {
        foreach ( [ '1', 'true', 'on', 'yes', 1, true ] as $yes ) {
            $this->assertTrue( MatchesBlockOptions::shows( [ 'squads' => $yes ], 'squads' ), var_export( $yes, true ) );
        }
        foreach ( [ '0', 'false', 'off', 'no', 0, false ] as $no ) {
            $this->assertFalse( MatchesBlockOptions::shows( [ 'record' => $no ], 'record' ), var_export( $no, true ) );
        }
    }

    /** Unusable is not the same as "off" — it falls back to the default. */
    public function test_an_unusable_value_falls_back_to_the_default(): void {
        $this->assertTrue( MatchesBlockOptions::shows( [ 'record' => 'perhaps' ], 'record' ) );
        $this->assertFalse( MatchesBlockOptions::shows( [ 'squads' => [ 1 ] ], 'squads' ) );
    }

    public function test_an_unusable_value_is_not_recorded(): void {
        $this->assertSame( [], MatchesBlockOptions::normalise( [ 'record' => 'perhaps' ] ) );
    }

    /** A bag saved before an option existed reads as that option's default. */
    public function test_a_bag_saved_before_an_option_existed_reads_as_its_default(): void {
        $this->assertTrue( MatchesBlockOptions::shows( [ 'squads' => true ], 'record' ) );
    }

    // ── unknown keys ───────────────────────────────────────────────────

    public function test_an_unknown_key_is_reported(): void {
        $this->assertSame( [ 'fixtures' ], MatchesBlockOptions::unknownKeys( [ 'record' => true, 'fixtures' => true ] ) );
    }

    public function test_the_known_keys_are_not_reported(): void {
        $this->assertSame( [], MatchesBlockOptions::unknownKeys( [ 'record' => 1, 'scorers' => 0, 'squads' => 1 ] ) );
    }

    // ── the block is wired in ──────────────────────────────────────────

    public function test_matches_is_a_block(): void {
        $this->assertTrue( TeamMonthlyReportBlock::isValid( TeamMonthlyReportBlock::MATCHES ) );
        $this->assertContains( TeamMonthlyReportBlock::MATCHES, TeamMonthlyReportBlock::ALL );
    }

    /** Results sit after the participation numbers and before the agenda. */
    public function test_matches_prints_after_minutes_and_before_the_agenda(): void {
        $order = array_flip( TeamMonthlyReportBlock::ALL );

        $this->assertGreaterThan( $order[ TeamMonthlyReportBlock::MINUTES ], $order[ TeamMonthlyReportBlock::MATCHES ] );
        $this->assertLessThan( $order[ TeamMonthlyReportBlock::ATTENTION ], $order[ TeamMonthlyReportBlock::MATCHES ] );
    }

    public function test_the_block_accepts_options(): void {
        $this->assertTrue( TeamMonthlyReportBlockOptions::accepts( TeamMonthlyReportBlock::MATCHES ) );
    }

    public function test_the_registry_prefixes_an_unknown_key_with_the_block(): void {
        $this->assertSame(
            [ 'matches.fixtures' ],
            TeamMonthlyReportBlockOptions::unknownKeys( TeamMonthlyReportBlock::MATCHES, [ 'fixtures' => 1 ] )
        );
    }

    /**
     * A composition that names its sections predates this one and must not
     * gain it — the reader chose those sections.
     */
    public function test_a_composition_that_names_its_sections_does_not_gain_matches(): void {
        $c = TeamMonthlyReportComposition::normalise( [
            'team_id' => 3,
            'blocks'  => [ 'kpi', 'attendance' ],
        ] );

        $this->assertNotContains( TeamMonthlyReportBlock::MATCHES, $c['blocks'] );
    }
}
