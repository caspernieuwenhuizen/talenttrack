<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TestsBlockOptions;
use TT\Modules\Measurements\Units\UnitContext;
use TT\Modules\Measurements\Units\UnitRegistry;

/**
 * #4063 — the monthly report's test rounds, as the composer spells them.
 *
 * Pinned: a duration test reads m:ss (never raw minutes) with its change in
 * seconds, a plain test reads as a locale number, and the readings of a test
 * with a direction run best to worst — ties and tests without a direction in
 * shirt order.
 */
final class TeamMonthlyReportTestRoundsTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Rounds U14', 'age_group' => 'U14' ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    private function player( string $last, int $jersey ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team_id, 'first_name' => 'Squad', 'last_name' => $last,
            'jersey_number' => $jersey, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A test entered in minutes and shown as mm:ss, stored in seconds. */
    private function durationTest( string $direction ): int {
        $minutes = ( new UnitRegistry() )->bySymbol( 'min' );
        $this->assertNotNull( $minutes, 'The unit registry should carry minutes.' );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_measurement_definitions", [
            'club_id' => $this->club, 'category_id' => 0, 'name' => '9 laps', 'value_type' => 'numeric',
            'unit' => 'min', 'dimension' => $minutes->dimension, 'entry_unit_id' => (int) $minutes->id,
            'numeric_format' => 'duration', 'direction' => $direction, 'is_active' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function plainTest( string $direction, string $unit ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_measurement_definitions", [
            'club_id' => $this->club, 'category_id' => 0, 'name' => 'Plain ' . $unit, 'value_type' => 'numeric',
            'unit' => $unit, 'direction' => $direction, 'is_active' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<int,float> $by_player player id => canonical value */
    private function round( int $definition_id, string $date, array $by_player ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_measurement_sessions", [
            'club_id' => $this->club, 'uuid' => wp_generate_uuid4(), 'definition_id' => $definition_id,
            'team_id' => $this->team_id, 'planned_date' => $date, 'status' => 'completed',
        ] );
        foreach ( $by_player as $player_id => $value ) {
            $wpdb->insert( "{$wpdb->prefix}tt_measurement_results", [
                'club_id' => $this->club, 'definition_id' => $definition_id, 'player_id' => $player_id,
                'recorded_date' => $date, 'value_numeric' => $value,
            ] );
        }
    }

    /** @return array<string,mixed> the one round the report holds for the definition */
    private function roundFor( int $definition_id ): array {
        $report = ( new TeamMonthlyReport() )->forTeam(
            $this->team_id, '2020-09-01', '2020-09-30', [ 'tests' ], 0,
            [ 'tests' => [ 'definitions' => [ $definition_id ], 'show' => TestsBlockOptions::SHOW_VALUES_TREND ] ]
        );
        $rounds = $report['data']['tests']['rounds'];
        $this->assertCount( 1, $rounds );
        return $rounds[0];
    }

    public function test_a_duration_reads_as_minutes_and_seconds_with_its_change_in_seconds(): void {
        $def    = $this->durationTest( 'lower' );
        $joeri  = $this->player( 'Veenhof', 4 );
        $samuel = $this->player( 'Liana', 7 );

        $this->round( $def, '2020-07-01', [ $joeri => 943.0, $samuel => 790.0 ] );
        $this->round( $def, '2020-09-01', [ $joeri => 964.0, $samuel => 783.0 ] );

        $round = $this->roundFor( $def );
        $this->assertSame( 'mm:ss', $round['unit_label'] );
        $this->assertTrue( $round['is_duration'] );

        $by_name = array_column( $round['readings'], null, 'name' );
        $this->assertSame( '16:04', $by_name['Squad Veenhof']['value_display'], 'Never 16.066666666667.' );
        $this->assertSame( '13:03', $by_name['Squad Liana']['value_display'] );
        $this->assertSame( '+21 s', $by_name['Squad Veenhof']['delta_display'] );
        $this->assertSame( '−7 s', $by_name['Squad Liana']['delta_display'] );
    }

    public function test_a_plain_test_reads_as_a_locale_number(): void {
        $def    = $this->plainTest( 'lower', 's' );
        $player = $this->player( 'Sprinter', 3 );
        $this->round( $def, '2020-09-05', [ $player => 3.45 ] );

        $round = $this->roundFor( $def );
        $this->assertSame( 's', $round['unit_label'] );
        $this->assertFalse( $round['is_duration'] );
        $this->assertSame( UnitContext::localeNumber( 3.45 ), $round['readings'][0]['value_display'] );
        $this->assertSame( '—', $round['readings'][0]['delta_display'], 'A first reading has nothing to compare with.' );
    }

    public function test_a_lower_test_lists_the_fastest_first_and_ties_keep_shirt_order(): void {
        $def  = $this->durationTest( 'lower' );
        $slow = $this->player( 'Slow', 1 );
        $tieb = $this->player( 'TieB', 9 );
        $fast = $this->player( 'Fast', 5 );
        $tiea = $this->player( 'TieA', 2 );
        $this->round( $def, '2020-09-01', [ $slow => 964.0, $tieb => 800.0, $fast => 678.0, $tiea => 800.0 ] );

        $this->assertSame(
            [ 'Squad Fast', 'Squad TieA', 'Squad TieB', 'Squad Slow' ],
            array_column( $this->roundFor( $def )['readings'], 'name' )
        );
    }

    public function test_a_higher_test_lists_the_highest_first(): void {
        $def  = $this->plainTest( 'higher', 'cm' );
        $low  = $this->player( 'Low', 1 );
        $high = $this->player( 'High', 2 );
        $this->round( $def, '2020-09-01', [ $low => 28.0, $high => 35.0 ] );

        $this->assertSame( [ 'Squad High', 'Squad Low' ], array_column( $this->roundFor( $def )['readings'], 'name' ) );
    }

    /**
     * #4069 — the figures the PDF's stat strip prints are the composer's:
     * squad average against the previous round, best and worst, who moved,
     * band counts from the age group's target, a PB, and the history.
     */
    public function test_a_round_carries_the_stat_strip_figures(): void {
        $def  = $this->durationTest( 'lower' );
        $fast = $this->player( 'Fast', 1 );
        $slow = $this->player( 'Slow', 2 );
        $new  = $this->player( 'New', 3 );

        global $wpdb;
        // Green up to 12:30, amber up to 13:30, in canonical seconds.
        $wpdb->insert( "{$wpdb->prefix}tt_measurement_targets", [
            'club_id' => $this->club, 'definition_id' => $def, 'age_group' => 'U14',
            'green_max' => 750.0, 'amber_max' => 810.0,
        ] );

        $this->round( $def, '2020-05-01', [ $fast => 700.0, $slow => 900.0 ] );
        $this->round( $def, '2020-07-01', [ $fast => 720.0, $slow => 940.0 ] );
        $this->round( $def, '2020-09-01', [ $fast => 678.0, $slow => 964.0, $new => 780.0 ] );

        $round = $this->roundFor( $def );

        $this->assertSame( '2020-07-01', $round['previous_date'] );
        $this->assertSame( '13:27', $round['average']['display'], 'Mean of 11:18, 16:04 and 13:00, to the second.' );
        $this->assertSame( '13:50', $round['average']['previous_display'] );
        $this->assertSame( 'up', $round['average']['trend'], 'A lower average on a lower-is-better test.' );
        $this->assertSame( 'Squad Fast', $round['best']['name'] );
        $this->assertSame( '11:18', $round['best']['value_display'] );
        $this->assertSame( 'Squad Slow', $round['worst']['name'] );
        $this->assertSame( [ 'up' => 1, 'down' => 1, 'flat' => 0, 'first' => 1 ], $round['moves'] );
        $this->assertSame( [ 'age_group' => 'U14', 'ok' => 1, 'warn' => 1, 'bad' => 1, 'of' => 3 ], $round['bands'] );
        $this->assertSame( [ '2020-05-01', '2020-07-01', '2020-09-01' ], array_column( $round['history'], 'date' ) );

        $by_name = array_column( $round['readings'], null, 'name' );
        $this->assertTrue( $by_name['Squad Fast']['pb'], 'Faster than every earlier reading.' );
        $this->assertFalse( $by_name['Squad Slow']['pb'] );
        $this->assertFalse( $by_name['Squad New']['pb'], 'A first reading is not a record.' );
        $this->assertSame( 'ok', $by_name['Squad Fast']['flag'] );
        $this->assertSame( 'bad', $by_name['Squad Slow']['flag'] );
        $this->assertSame( '12:00', $by_name['Squad Fast']['previous_display'] );
        $this->assertSame( 1, $by_name['Squad Fast']['rank'] );
        $this->assertTrue( $by_name['Squad Slow']['worse_than_avg'] );
        $this->assertFalse( $by_name['Squad Fast']['worse_than_avg'] );

        // #4093 — the target as the profile prints it, and each reading's
        // standing in the profile's words.
        $this->assertSame( '≤ 12:30', $round['target'] );
        $this->assertSame( 'U14', $round['target_age_group'] );
        $this->assertFalse( $round['target_absent'] );
        $this->assertSame( 'Target U14: ≤ 12:30', \TT\Modules\Analytics\Reports\TeamMonthlyReport::testTargetLabel( $round ) );
        $this->assertSame( 'on target', $by_name['Squad Fast']['verdict_label'] );
        $this->assertSame( 'just over target', $by_name['Squad New']['verdict_label'] );
        $this->assertSame( 'well over target', $by_name['Squad Slow']['verdict_label'] );
        $this->assertSame( 'bad', $by_name['Squad Slow']['verdict_tone'] );
    }

    /** #4069 — no band for the team's age group is no band cell, not "0 on target". */
    public function test_a_test_without_a_band_has_no_band_counts(): void {
        $def    = $this->plainTest( 'lower', 's' );
        $player = $this->player( 'Sprinter', 3 );
        $this->round( $def, '2020-09-05', [ $player => 3.45 ] );

        $this->assertNull( $this->roundFor( $def )['bands'] );
    }

    public function test_a_test_without_a_direction_stays_in_shirt_order(): void {
        $def   = $this->plainTest( 'neutral', 'cm' );
        $tall  = $this->player( 'Tall', 8 );
        $short = $this->player( 'Short', 2 );
        $this->round( $def, '2020-09-01', [ $tall => 180.0, $short => 150.0 ] );

        $this->assertSame( [ 'Squad Short', 'Squad Tall' ], array_column( $this->roundFor( $def )['readings'], 'name' ) );
    }
}
