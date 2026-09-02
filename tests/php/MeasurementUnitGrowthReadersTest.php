<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Growth\BmiSeriesBuilder;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Services\ProfileMeasurementSync;
use TT\Modules\Measurements\Units\UnitRegistry;

/**
 * #3273 — the growth readers convert instead of assuming.
 *
 * `BmiSeriesBuilder` divided by 100 and `ProfileMeasurementSync` wrote whatever
 * number it found straight into `tt_players.height_cm`, both having found the
 * test by name and then assumed centimetres. `m` was always a selectable unit,
 * so an academy recording height in metres got `height_cm = 1.82` and a BMI two
 * orders of magnitude out, with nothing reporting an error.
 *
 * These tests record the same player twice — one club measuring in centimetres,
 * one in metres — and require both to arrive at the same height and the same
 * BMI. A regression here is a silent one, which is why it is asserted rather
 * than left to the type system.
 */
final class MeasurementUnitGrowthReadersTest extends WP_UnitTestCase {

    private int $club;

    public function set_up(): void {
        parent::set_up();
        $this->club = (int) CurrentClub::id();
    }

    /**
     * A height or weight definition recorded in a given unit.
     */
    private function definition( string $name, string $symbol ): int {
        global $wpdb;

        $unit = ( new UnitRegistry() )->bySymbol( $symbol );
        $this->assertNotNull( $unit, "The unit registry should carry '{$symbol}'." );

        $wpdb->insert( $wpdb->prefix . 'tt_measurement_definitions', [
            'club_id'       => $this->club,
            'category_id'   => 1,
            'name'          => $name,
            'value_type'    => 'numeric',
            'unit'          => $symbol,
            'dimension'     => (string) $unit->dimension,
            'entry_unit_id' => (int) $unit->id,
            'direction'     => 'neutral',
            'is_active'     => 1,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function player( string $last_name ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => $this->club,
            'first_name' => 'Unit',
            'last_name'  => $last_name,
            'status'     => 'active',
            'date_of_birth' => '2012-01-01',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * @return int the result id
     */
    private function record( int $player_id, int $definition_id, string $date, float $canonical ): int {
        return ( new MeasurementResultsRepository() )->create( [
            'club_id'       => $this->club,
            'player_id'     => $player_id,
            'definition_id' => $definition_id,
            'recorded_date' => $date,
            'value_numeric' => $canonical,
        ] );
    }

    private function profileHeight( int $player_id ): ?float {
        global $wpdb;
        $v = $wpdb->get_var( $wpdb->prepare(
            "SELECT height_cm FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ) );
        return $v === null ? null : (float) $v;
    }

    public function test_height_in_metres_and_in_centimetres_sync_the_same_profile_height(): void {
        // Both record 1.82m. Canonical storage is metres either way — the
        // difference is only which unit the academy types in.
        $in_cm  = $this->definition( 'Lengte', 'cm' );
        $in_m   = $this->definition( 'Length', 'm' );

        $player_cm = $this->player( 'Centimetre' );
        $player_m  = $this->player( 'Metre' );

        $id_cm = $this->record( $player_cm, $in_cm, '2026-03-01', 1.82 );
        $id_m  = $this->record( $player_m,  $in_m,  '2026-03-01', 1.82 );

        ( new ProfileMeasurementSync() )->onResultSaved( $id_cm, $player_cm );
        ( new ProfileMeasurementSync() )->onResultSaved( $id_m,  $player_m );

        $this->assertEqualsWithDelta( 182.0, $this->profileHeight( $player_cm ), 0.5 );
        $this->assertEqualsWithDelta( 182.0, $this->profileHeight( $player_m ), 0.5 );
    }

    public function test_bmi_is_the_same_whichever_unit_the_academy_measures_in(): void {
        $height_cm = $this->definition( 'Lengte', 'cm' );
        $height_m  = $this->definition( 'Length', 'm' );
        $weight_kg = $this->definition( 'Gewicht', 'kg' );
        $weight_g  = $this->definition( 'Weight', 'g' );

        $player_a = $this->player( 'Metric' );
        $player_b = $this->player( 'Grams' );

        // 1.82 m and 62.4 kg, canonical, however they were typed.
        $this->record( $player_a, $height_cm, '2026-03-01', 1.82 );
        $this->record( $player_a, $weight_kg, '2026-03-01', 62.4 );

        $this->record( $player_b, $height_m,  '2026-03-01', 1.82 );
        $this->record( $player_b, $weight_g,  '2026-03-01', 62.4 );

        $builder = new BmiSeriesBuilder();
        $a = $builder->forPlayer( $player_a, $this->club );
        $b = $builder->forPlayer( $player_b, $this->club );

        $this->assertNotEmpty( $a, 'A player with a paired height and weight should produce a BMI point.' );
        $this->assertNotEmpty( $b );

        $this->assertEqualsWithDelta( 18.84, $a[0]['bmi'], 0.05 );
        $this->assertEqualsWithDelta( $a[0]['bmi'], $b[0]['bmi'], 0.001 );
        $this->assertEqualsWithDelta( 182.0, $a[0]['height_cm'], 0.5 );
        $this->assertEqualsWithDelta( 182.0, $b[0]['height_cm'], 0.5 );
    }

    public function test_a_definition_without_a_dimension_keeps_the_legacy_reading(): void {
        global $wpdb;

        // An install that has not classified its height test yet: the value was
        // never converted by the migration either, so it still means what it
        // always meant on that install.
        $wpdb->insert( $wpdb->prefix . 'tt_measurement_definitions', [
            'club_id'     => $this->club,
            'category_id' => 1,
            'name'        => 'Lengte',
            'value_type'  => 'numeric',
            'unit'        => 'lengte-eenheid',
            'dimension'   => 'dimensionless',
            'direction'   => 'neutral',
            'is_active'   => 1,
        ] );
        $legacy_def = (int) $wpdb->insert_id;

        $player = $this->player( 'Legacy' );
        $id     = $this->record( $player, $legacy_def, '2026-03-01', 178.0 );

        ( new ProfileMeasurementSync() )->onResultSaved( $id, $player );

        $this->assertEqualsWithDelta( 178.0, $this->profileHeight( $player ), 0.5 );
    }
}
