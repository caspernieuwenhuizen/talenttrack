<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Growth\BmiSeriesBuilder;

/**
 * #2895 — pairing dated height and weight readings into a BMI series.
 *
 * Reference-independent by design: this answers "what was this player's BMI,
 * and when", which is testable without a growth curve anywhere near it.
 *
 * The rule under test: a BMI point is anchored on a weight and paired with
 * the nearest height within 30 days. A weight with no height in range
 * produces no point, rather than reaching further back — which is exactly
 * the silent staleness that using the undated `tt_players` snapshot columns
 * would have introduced.
 */
final class BmiSeriesBuilderTest extends WP_UnitTestCase {

    private int $club;
    private int $height_def;
    private int $weight_def;
    private int $player_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->club = (int) CurrentClub::id();

        $this->height_def = $this->definition( 'Height' );
        $this->weight_def = $this->definition( 'Weight' );

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => $this->club,
            'first_name' => 'Growth',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $this->player_id = (int) $wpdb->insert_id;
    }

    private function definition( string $name ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_measurement_definitions', [
            'club_id'     => $this->club,
            'category_id' => 1,
            'name'        => $name,
            'value_type'  => 'numeric',
            'direction'   => 'neutral',
            'is_active'   => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function reading( int $definition_id, string $date, float $value, ?string $archived = null ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_measurement_results', [
            'club_id'       => $this->club,
            'player_id'     => $this->player_id,
            'definition_id' => $definition_id,
            'recorded_date' => $date,
            'value_numeric' => $value,
            'archived_at'   => $archived,
        ] );
    }

    private function series(): array {
        return ( new BmiSeriesBuilder() )->forPlayer( $this->player_id, $this->club );
    }

    /** 60 kg at 1.70 m is 20.76. */
    public function test_a_paired_reading_produces_a_bmi_point(): void {
        $this->reading( $this->height_def, '2026-03-01', 170.0 );
        $this->reading( $this->weight_def, '2026-03-05', 60.0 );

        $series = $this->series();

        $this->assertCount( 1, $series );
        $this->assertSame( '2026-03-05', $series[0]['date'], 'the point is dated by the weight' );
        $this->assertEqualsWithDelta( 20.76, $series[0]['bmi'], 0.01 );
        $this->assertSame( 4, $series[0]['gap_days'] );
    }

    /**
     * The rule that stops a stale height producing a confident wrong number.
     */
    public function test_a_weight_with_no_height_in_range_produces_nothing(): void {
        $this->reading( $this->height_def, '2026-01-01', 170.0 );
        $this->reading( $this->weight_def, '2026-03-05', 60.0 ); // 63 days later

        $this->assertSame( [], $this->series() );
    }

    /** Exactly 30 days is inside the window; 31 is not. */
    public function test_the_window_boundary(): void {
        $this->reading( $this->height_def, '2026-03-01', 170.0 );
        $this->reading( $this->weight_def, '2026-03-31', 60.0 ); // 30 days
        $this->reading( $this->weight_def, '2026-04-01', 61.0 ); // 31 days

        $series = $this->series();

        $this->assertCount( 1, $series );
        $this->assertSame( '2026-03-31', $series[0]['date'] );
    }

    /** Weight is the anchor, so monthly weigh-ins give monthly points. */
    public function test_weight_is_the_anchor(): void {
        $this->reading( $this->height_def, '2026-03-15', 170.0 );
        $this->reading( $this->weight_def, '2026-03-01', 60.0 );
        $this->reading( $this->weight_def, '2026-03-20', 61.0 );
        $this->reading( $this->weight_def, '2026-04-05', 62.0 );

        $series = $this->series();

        $this->assertCount( 3, $series, 'one height can serve several weights' );
        $this->assertSame(
            [ '2026-03-01', '2026-03-20', '2026-04-05' ],
            array_column( $series, 'date' ),
            'oldest first'
        );
    }

    /** The nearest height wins when several are in range. */
    public function test_the_nearest_height_is_used(): void {
        $this->reading( $this->height_def, '2026-03-01', 165.0 );
        $this->reading( $this->height_def, '2026-03-18', 172.0 );
        $this->reading( $this->weight_def, '2026-03-20', 60.0 );

        $series = $this->series();

        $this->assertCount( 1, $series );
        $this->assertEqualsWithDelta( 172.0, $series[0]['height_cm'], 0.01 );
        $this->assertSame( '2026-03-18', $series[0]['height_date'] );
    }

    /** An archived reading is one somebody deleted; it is not evidence. */
    public function test_archived_readings_are_ignored(): void {
        $this->reading( $this->height_def, '2026-03-01', 170.0 );
        $this->reading( $this->weight_def, '2026-03-05', 60.0, '2026-04-01 00:00:00' );

        $this->assertSame( [], $this->series() );
    }

    /** A player with only one of the two measurements has no series. */
    public function test_one_sided_data_produces_nothing(): void {
        $this->reading( $this->height_def, '2026-03-01', 170.0 );
        $this->assertSame( [], $this->series() );
    }

    /** The academy owns its vocabulary — a Dutch install says Lengte. */
    public function test_dutch_definition_names_are_recognised(): void {
        $lengte  = $this->definition( 'Lengte' );
        $gewicht = $this->definition( 'Gewicht' );

        $this->reading( $lengte,  '2026-05-01', 180.0 );
        $this->reading( $gewicht, '2026-05-02', 70.0 );

        $series = $this->series();

        $this->assertCount( 1, $series );
        $this->assertEqualsWithDelta( 21.60, $series[0]['bmi'], 0.01 );
    }
}
