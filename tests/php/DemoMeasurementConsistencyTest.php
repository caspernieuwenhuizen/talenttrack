<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoAnthropometry;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoRoster;
use TT\Modules\DemoData\Generators\MeasurementGenerator;
use TT\Modules\Measurements\Units\UnitContext;

/**
 * #4036 — a generated academy's youngest age group.
 *
 * The battery's age model was a straight line anchored on a twelve-year-old,
 * so a U7 squad got a juggling target band opening at **-15 reps** and
 * results to match, while the Height and Weight tests ran on a second body
 * model that disagreed with the player record the same run had written: 15 kg
 * measured against 24 kg on the profile, a 117.5-121 cm band against 114 cm.
 *
 * What is pinned here is that the youngest rung reads as a child: nothing
 * negative, and the body readings are the record rather than a second opinion
 * about it.
 *
 * `$now` is pinned — a test whose expectations drift with the wall clock
 * fails on a Tuesday in August for no reason.
 */
final class DemoMeasurementConsistencyTest extends WP_UnitTestCase {

    /** Mid-September, early in the 2026/2027 season — pinned, per DemoSeasonCadenceTest. */
    private const NOW = 1789430400; // 2026-09-15 00:00:00 UTC

    private const WEEKS = 8;

    /** The squad's nominal age, and the age-group label it carries. */
    private const AGE       = 7;
    private const AGE_GROUP = 'JO7';

    private int $team_id = 0;

    /** @var list<object> */
    private array $players = [];

    /** @var list<object> */
    private array $teams = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [
            'club_id'   => 1,
            'name'      => 'Measurement ' . self::AGE_GROUP,
            'age_group' => self::AGE_GROUP,
        ] );
        $this->team_id = (int) $wpdb->insert_id;

        // The same body model `PlayerGenerator` writes a record from, so the
        // fixture is the shape a generated academy actually has.
        for ( $i = 1; $i <= 5; $i++ ) {
            $height = DemoAnthropometry::heightForAge( self::AGE );
            $wpdb->insert( "{$wpdb->prefix}tt_players", [
                'club_id'       => 1,
                'first_name'    => 'Tiny',
                'last_name'     => 'Talent ' . $i,
                'date_of_birth' => '2019-03-0' . $i,
                'height_cm'     => $height,
                'weight_kg'     => DemoAnthropometry::weightForAge( self::AGE, $height ),
                'team_id'       => $this->team_id,
                'date_joined'   => '2024-08-01',
                'wp_user_id'    => null,
            ] );
        }

        $this->players = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE team_id = %d ORDER BY id",
            $this->team_id
        ) );
        $this->teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_teams WHERE id = %d",
            $this->team_id
        ) );
        foreach ( $this->teams as $team ) {
            $team->head_coach_user_id = 0;
        }
    }

    private function generate(): void {
        // Seeded, so a failure is reproducible rather than a coin toss.
        mt_srand( 4036 );

        $calendar = new DemoCalendar( self::WEEKS, self::NOW );

        ( new MeasurementGenerator(
            new DemoBatchRegistry( 'test-4036' ),
            $this->players,
            $this->teams,
            [ 'admin' => 0 ],
            self::WEEKS,
            'en_US',
            $calendar,
            new DemoRoster( $calendar, $this->teams, $this->players )
        ) )->generate();
    }

    // ----- Nothing impossible -----

    public function test_no_generated_reading_is_negative(): void {
        global $wpdb;
        $this->generate();

        $rows = $wpdb->get_results(
            "SELECT d.name, r.entered_value
               FROM {$wpdb->prefix}tt_measurement_results r
               JOIN {$wpdb->prefix}tt_measurement_definitions d ON d.id = r.definition_id
              WHERE r.entered_value < 0"
        );

        $this->assertSame( [], (array) $rows, 'no test in the battery can read below zero' );
        $this->assertGreaterThan( 0, $this->resultCount(), 'the fixture must produce readings, or this proves nothing' );
    }

    public function test_no_target_band_edge_is_negative(): void {
        global $wpdb;
        $this->generate();

        $bad = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_measurement_targets
              WHERE green_min < 0 OR green_max < 0 OR amber_min < 0 OR amber_max < 0"
        );

        $this->assertSame( 0, $bad, 'a U7 band opening at a negative count is what #4036 reported' );
    }

    public function test_the_youngest_squad_juggles_a_positive_number_of_times(): void {
        $this->generate();

        $values = $this->enteredValuesFor( 'Juggling' );
        $this->assertNotSame( [], $values, 'the juggling test must produce readings' );

        foreach ( $values as $value ) {
            $this->assertGreaterThan( 0.0, $value, 'a seven-year-old juggles the ball a positive number of times' );
        }

        $band = $this->bandFor( 'Juggling' );
        $this->assertGreaterThan( 0.0, $band['amber_min'], 'and the band they are judged against starts above zero' );
        $this->assertLessThanOrEqual( $band['green_min'], $band['amber_min'] );
        $this->assertLessThanOrEqual( $band['green_max'], $band['amber_max'] );
    }

    // ----- The body readings agree with the record -----

    public function test_the_latest_height_and_weight_match_the_player_record(): void {
        $this->generate();

        foreach ( [ 'Height' => 'height_cm', 'Weight' => 'weight_kg' ] as $test => $column ) {
            $latest = $this->latestPerPlayer( $test );
            $this->assertNotSame( [], $latest, "the {$test} test must produce readings" );

            foreach ( $this->players as $player ) {
                $id = (int) $player->id;
                if ( ! isset( $latest[ $id ] ) ) continue;

                $this->assertEqualsWithDelta(
                    (float) $player->{$column},
                    $latest[ $id ],
                    3.0,
                    "the latest {$test} reading must agree with the player record, not a second age model"
                );
            }
        }
    }

    public function test_the_body_target_bands_contain_what_the_age_group_typically_is(): void {
        $this->generate();

        $height = $this->bandFor( 'Height' );
        $typical_height = DemoAnthropometry::typicalHeight( self::AGE );
        $this->assertGreaterThanOrEqual( $height['amber_min'], $typical_height );
        $this->assertLessThanOrEqual( $height['amber_max'], $typical_height );
        $this->assertGreaterThanOrEqual( $height['green_min'], $typical_height );
        $this->assertLessThanOrEqual( $height['green_max'], $typical_height );

        $weight = $this->bandFor( 'Weight' );
        $typical_weight = DemoAnthropometry::typicalWeight( self::AGE );
        $this->assertGreaterThanOrEqual( $weight['amber_min'], $typical_weight );
        $this->assertLessThanOrEqual( $weight['amber_max'], $typical_weight );
    }

    // Readers

    private function resultCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_measurement_results" );
    }

    private function definition( string $name ): object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_measurement_definitions WHERE name = %s LIMIT 1",
            $name
        ) );
        $this->assertIsObject( $row, "the battery must carry a {$name} test" );
        return $row;
    }

    /** @return list<float> every reading for one test, in the unit it was entered in */
    private function enteredValuesFor( string $name ): array {
        global $wpdb;

        $values = $wpdb->get_col( $wpdb->prepare(
            "SELECT entered_value FROM {$wpdb->prefix}tt_measurement_results WHERE definition_id = %d",
            (int) $this->definition( $name )->id
        ) );
        return array_map( 'floatval', (array) $values );
    }

    /**
     * The most recent reading per player for one test, in the entry unit.
     *
     * @return array<int,float>
     */
    private function latestPerPlayer( string $name ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT player_id, entered_value, recorded_date
               FROM {$wpdb->prefix}tt_measurement_results
              WHERE definition_id = %d
              ORDER BY recorded_date ASC, id ASC",
            (int) $this->definition( $name )->id
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row->player_id ] = (float) $row->entered_value;
        }
        return $out;
    }

    /**
     * One test's band for this squad's age group, converted back out of the
     * dimension's base into the unit the battery states.
     *
     * @return array{green_min:float, green_max:float, amber_min:float, amber_max:float}
     */
    private function bandFor( string $name ): array {
        global $wpdb;

        $definition = $this->definition( $name );
        $units      = UnitContext::forDefinition( $definition );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT green_min, green_max, amber_min, amber_max
               FROM {$wpdb->prefix}tt_measurement_targets
              WHERE definition_id = %d AND age_group = %s LIMIT 1",
            (int) $definition->id,
            self::AGE_GROUP
        ) );
        $this->assertIsObject( $row, "the {$name} test must carry a band for " . self::AGE_GROUP );

        return [
            'green_min' => $units->fromBase( (float) $row->green_min ),
            'green_max' => $units->fromBase( (float) $row->green_max ),
            'amber_min' => $units->fromBase( (float) $row->amber_min ),
            'amber_max' => $units->fromBase( (float) $row->amber_max ),
        ];
    }
}
