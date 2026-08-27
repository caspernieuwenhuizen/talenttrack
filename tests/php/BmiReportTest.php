<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Reports\BmiQuery;

/**
 * #2895 — the BMI-for-age read model and its REST route.
 *
 * The domain layer (the WHO reference, the pairing rule) is verified against
 * WHO's own published cut-offs by tools/verify-growth-reference.php. What is
 * tested here is the layer above it: that the roster rows, the per-player
 * series and the endpoint agree, and that the honest-empty cases stay honest.
 */
final class BmiReportTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function test_age_in_months_counts_whole_months(): void {
        $this->assertSame( 0,   BmiQuery::ageInMonths( '2010-01-01', '2010-01-31' ) );
        $this->assertSame( 1,   BmiQuery::ageInMonths( '2010-01-01', '2010-02-01' ) );
        $this->assertSame( 144, BmiQuery::ageInMonths( '2010-01-01', '2022-01-01' ) );
    }

    /**
     * A player with no birth date has no age, so no percentile. Guessing one
     * would be worse than showing nothing.
     */
    public function test_age_in_months_is_null_without_a_birth_date(): void {
        $this->assertNull( BmiQuery::ageInMonths( '', '2022-01-01' ) );
        $this->assertNull( BmiQuery::ageInMonths( '2010-01-01', '' ) );
        $this->assertNull( BmiQuery::ageInMonths( '2010-01-01', '2009-01-01' ),
            'a measurement before birth is not an age' );
    }

    public function test_percentile_from_z_matches_the_normal_distribution(): void {
        $this->assertEqualsWithDelta( 50.0,  BmiQuery::percentileFromZ( 0.0 ),  0.05 );
        $this->assertEqualsWithDelta( 97.7,  BmiQuery::percentileFromZ( 2.0 ),  0.15 );
        $this->assertEqualsWithDelta( 2.3,   BmiQuery::percentileFromZ( -2.0 ), 0.15 );
        $this->assertEqualsWithDelta( 84.1,  BmiQuery::percentileFromZ( 1.0 ),  0.15 );
    }

    /**
     * A player with no measurements is still returned, with nulls. Dropping
     * them would make the report look complete when it is not — knowing who
     * you have no data for is the first thing an academy needs.
     */
    public function test_roster_includes_players_with_no_measurements(): void {
        $team_id   = $this->insertTeam();
        $player_id = $this->insertPlayer( $team_id, 'male', '2012-03-01' );

        $rows = ( new BmiQuery() )->rosterRows( [ $team_id ] );

        $this->assertCount( 1, $rows );
        $this->assertSame( $player_id, $rows[0]['player_id'] );
        $this->assertNull( $rows[0]['bmi'] );
        $this->assertNull( $rows[0]['percentile'] );
        $this->assertSame( 0, $rows[0]['points'] );
    }

    public function test_roster_row_carries_bmi_and_percentile(): void {
        $team_id   = $this->insertTeam();
        $player_id = $this->insertPlayer( $team_id, 'male', '2012-01-01' );
        [ $h, $w ] = $this->insertHeightWeightDefinitions();

        // 1.55 m, 40 kg -> BMI 16.65 at 10 years 0 months.
        $this->insertResult( $player_id, $h, '2022-01-01', 155.0 );
        $this->insertResult( $player_id, $w, '2022-01-01', 40.0 );

        $rows = ( new BmiQuery() )->rosterRows( [ $team_id ] );

        $this->assertCount( 1, $rows );
        $this->assertEqualsWithDelta( 16.65, (float) $rows[0]['bmi'], 0.02 );
        $this->assertSame( 120, $rows[0]['age_months'] );
        $this->assertTrue( $rows[0]['covered'] );
        $this->assertNotNull( $rows[0]['percentile'] );
        $this->assertSame( 0, $rows[0]['gap_days'], 'same-day readings pair with no gap' );
    }

    /**
     * A player whose sex is blank gets a BMI but no curve — the reference is
     * sex-specific and there is no neutral variant to fall back on.
     */
    public function test_blank_sex_yields_bmi_without_a_percentile(): void {
        $team_id   = $this->insertTeam();
        $player_id = $this->insertPlayer( $team_id, '', '2012-01-01' );
        [ $h, $w ] = $this->insertHeightWeightDefinitions();

        $this->insertResult( $player_id, $h, '2022-01-01', 155.0 );
        $this->insertResult( $player_id, $w, '2022-01-01', 40.0 );

        $rows = ( new BmiQuery() )->rosterRows( [ $team_id ] );

        $this->assertNotNull( $rows[0]['bmi'], 'BMI itself needs no sex' );
        $this->assertNull( $rows[0]['sds'] );
        $this->assertFalse( $rows[0]['covered'] );
    }

    /**
     * The 30-day pairing rule is a tolerance, not a fact — so it has a hard
     * edge and the gap is reported.
     */
    public function test_a_height_outside_the_window_does_not_pair(): void {
        $team_id   = $this->insertTeam();
        $player_id = $this->insertPlayer( $team_id, 'male', '2012-01-01' );
        [ $h, $w ] = $this->insertHeightWeightDefinitions();

        $this->insertResult( $player_id, $h, '2022-01-01', 155.0 );
        $this->insertResult( $player_id, $w, '2022-03-01', 40.0 ); // 59 days later

        $rows = ( new BmiQuery() )->rosterRows( [ $team_id ] );

        $this->assertNull( $rows[0]['bmi'], 'a height two months away describes a different body' );
    }

    public function test_player_series_orders_oldest_first_and_carries_z(): void {
        $team_id   = $this->insertTeam();
        $player_id = $this->insertPlayer( $team_id, 'female', '2010-01-01' );
        [ $h, $w ] = $this->insertHeightWeightDefinitions();

        $this->insertResult( $player_id, $h, '2022-01-01', 150.0 );
        $this->insertResult( $player_id, $w, '2022-01-01', 40.0 );
        $this->insertResult( $player_id, $h, '2022-06-01', 155.0 );
        $this->insertResult( $player_id, $w, '2022-06-01', 45.0 );

        $series = ( new BmiQuery() )->playerSeries( $player_id );

        $this->assertCount( 2, $series );
        $this->assertSame( '2022-01-01', $series[0]['date'] );
        $this->assertSame( '2022-06-01', $series[1]['date'] );
        $this->assertNotNull( $series[0]['sds'] );
        $this->assertNotNull( $series[1]['percentile'] );
    }

    /** Reference bands are what a chart draws the player's points against. */
    public function test_reference_bands_span_minus_two_to_plus_two(): void {
        $bands = ( new BmiQuery() )->referenceBands( 120, 'male' );

        $this->assertIsArray( $bands );
        $this->assertSame( [ '-2', '-1', '0', '+1', '+2' ], array_keys( $bands ) );
        $this->assertLessThan( $bands['0'],  $bands['-1'] );
        $this->assertLessThan( $bands['+1'], $bands['0'] );
    }

    public function test_reference_bands_are_null_outside_coverage(): void {
        $this->assertNull( ( new BmiQuery() )->referenceBands( 120, '' ) );
        $this->assertNull( ( new BmiQuery() )->referenceBands( 12, 'male' ),
            'the 5-19 reference does not describe a one-year-old' );
    }

    /**
     * REST smoke test — the route exists, is registered, and refuses an
     * anonymous caller. The endpoint mandate (#1388) requires this.
     */
    public function test_rest_route_is_registered_and_gated(): void {
        do_action( 'rest_api_init' );
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey( '/talenttrack/v1/reports/player-bmi', $routes,
            'the BMI report must be reachable through REST, not only through PHP render' );

        wp_set_current_user( 0 );
        $request  = new \WP_REST_Request( 'GET', '/talenttrack/v1/reports/player-bmi' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 401, $response->get_status(),
            'an anonymous caller must not read measurements about minors' );
    }

    /* ---- helpers -------------------------------------------------------- */

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U12 BMI' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $sex, string $dob ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'first_name'    => 'Bo',
            'last_name'     => 'Meting',
            'sex'           => $sex,
            'date_of_birth' => $dob,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return array{0:int,1:int} height definition id, weight definition id */
    private function insertHeightWeightDefinitions(): array {
        global $wpdb;
        // `direction` is 'neutral' for both: height and weight have no better
        // or worse, which is exactly why BMI-for-age exists as its own report
        // rather than as a column on Test trends.
        $wpdb->insert( "{$this->p}tt_measurement_definitions", [
            'club_id'     => $this->club,
            'category_id' => 1,
            'name'        => 'Height',
            'value_type'  => 'numeric',
            'unit'        => 'cm',
            'direction'   => 'neutral',
        ] );
        $height = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_measurement_definitions", [
            'club_id'     => $this->club,
            'category_id' => 1,
            'name'        => 'Weight',
            'value_type'  => 'numeric',
            'unit'        => 'kg',
            'direction'   => 'neutral',
        ] );
        $weight = (int) $wpdb->insert_id;

        return [ $height, $weight ];
    }

    private function insertResult( int $player_id, int $definition_id, string $date, float $value ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_measurement_results", [
            'club_id'       => $this->club,
            'player_id'     => $player_id,
            'definition_id' => $definition_id,
            'recorded_date' => $date,
            'value_numeric' => $value,
        ] );
    }
}
