<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Units\DurationFormat;
use TT\Modules\Measurements\Units\UnitContext;
use TT\Modules\Measurements\Units\UnitRegistry;

/**
 * #3273 — the unit of measure is part of the datum.
 *
 * Three properties are worth pinning down, because each of them was a silent
 * failure before the registry existed:
 *
 *   - a duration round-trips as mm:ss and is stored in seconds, so 5:30 is
 *     330 and never 5.3 minutes;
 *   - a value entered in one unit and a value entered in another land on the
 *     same canonical number, which is what makes a series comparable;
 *   - editing a definition's unit does not touch results already recorded
 *     against it. That is the property the old design could not have: the
 *     unit lived on the definition, so changing it redefined history.
 */
final class MeasurementUnitConversionTest extends WP_UnitTestCase {

    private function contextFor( string $symbol, string $format = 'plain' ): UnitContext {
        $unit = ( new UnitRegistry() )->bySymbol( $symbol );
        $this->assertNotNull( $unit, "The unit registry should carry '{$symbol}' after migration 0252." );

        return UnitContext::forDefinition( (object) [
            'unit'           => $symbol,
            'dimension'      => $unit->dimension,
            'entry_unit_id'  => (int) $unit->id,
            'numeric_format' => $format,
            'value_type'     => 'numeric',
        ] );
    }

    // ── the duration notation ───────────────────────────────────────

    public function test_duration_parses_minutes_and_seconds(): void {
        $this->assertSame( 330.0, DurationFormat::parse( '5:30' ) );
        $this->assertSame( 330.0, DurationFormat::parse( '05:30' ) );
        $this->assertSame( 3723.5, DurationFormat::parse( '1:02:03.5' ) );
    }

    public function test_duration_refuses_an_impossible_seconds_field(): void {
        // 5:75 would silently become 6:15 under a normalising parser. A typo
        // that reinterprets itself is exactly what this issue is about.
        $this->assertNull( DurationFormat::parse( '5:75' ) );
        $this->assertNull( DurationFormat::parse( 'half past' ) );
        $this->assertNull( DurationFormat::parse( '330' ) );
    }

    public function test_duration_formats_without_inventing_precision(): void {
        $this->assertSame( '5:30', DurationFormat::format( 330.0 ) );
        $this->assertSame( '12:00', DurationFormat::format( 720.0 ) );
        $this->assertSame( '1:30.25', DurationFormat::format( 90.25 ) );
        $this->assertSame( '1:02:03', DurationFormat::format( 3723.0 ) );
    }

    public function test_a_duration_test_stores_seconds_and_reads_back_as_mm_ss(): void {
        $units = $this->contextFor( 'min', 'duration' );

        $parsed = $units->parse( '5:30' );
        $this->assertNull( $parsed['error'] );
        $this->assertEqualsWithDelta( 330.0, $parsed['value'], 0.0001 );
        $this->assertSame( '5:30', $units->format( $parsed['value'] ) );
    }

    public function test_a_duration_test_still_accepts_a_bare_number_in_its_own_unit(): void {
        // No colon means the unit the test declares — 5.5 minutes, not 5.5
        // seconds and not 5 minutes 5 seconds.
        $units  = $this->contextFor( 'min', 'duration' );
        $parsed = $units->parse( '5.5' );
        $this->assertEqualsWithDelta( 330.0, $parsed['value'], 0.0001 );
    }

    public function test_duration_is_refused_on_a_test_that_is_not_a_time(): void {
        // A length test asking for mm:ss is a data error, not a preference.
        $units = $this->contextFor( 'cm', 'duration' );
        $this->assertFalse( $units->isDuration() );
    }

    // ── conversion ──────────────────────────────────────────────────

    public function test_the_same_height_in_different_units_lands_on_one_number(): void {
        $in_cm = $this->contextFor( 'cm' );
        $in_m  = $this->contextFor( 'm' );

        $this->assertEqualsWithDelta( 1.82, $in_cm->parse( '182' )['value'], 0.00001 );
        $this->assertEqualsWithDelta( 1.82, $in_m->parse( '1.82' )['value'], 0.00001 );
    }

    public function test_a_canonical_value_reads_back_in_the_unit_it_was_entered_in(): void {
        $in_cm = $this->contextFor( 'cm' );
        $this->assertSame( '182 cm', $in_cm->format( 1.82 ) );

        $in_m = $this->contextFor( 'm' );
        $this->assertSame( '1.82 m', $in_m->format( 1.82 ) );
    }

    public function test_an_implausible_magnitude_is_refused_with_a_reason(): void {
        $units  = $this->contextFor( 'm' );
        $parsed = $units->parse( '5000' ); // 5km tall

        $this->assertNull( $parsed['value'] );
        $this->assertNotNull( $parsed['error'] );
    }

    public function test_a_custom_unit_is_dimensionless_and_passes_values_through(): void {
        $units = UnitContext::forDefinition( (object) [
            'unit'           => 'watt/kg',
            'dimension'      => 'dimensionless',
            'entry_unit_id'  => null,
            'numeric_format' => 'plain',
            'value_type'     => 'numeric',
        ] );

        $this->assertFalse( $units->isConvertible() );
        $this->assertEqualsWithDelta( 4.2, $units->parse( '4.2' )['value'], 0.00001 );
        $this->assertSame( '4.2 watt/kg', $units->format( 4.2 ) );
    }

    // ── history is not rewritten ────────────────────────────────────

    public function test_changing_a_definition_unit_leaves_recorded_values_alone(): void {
        global $wpdb;

        $registry = new UnitRegistry();
        $cm       = $registry->bySymbol( 'cm' );
        $m        = $registry->bySymbol( 'm' );
        $this->assertNotNull( $cm );
        $this->assertNotNull( $m );

        $repo = new MeasurementDefinitionsRepository();
        $id   = $repo->create( [
            'category_id'   => 1,
            'name'          => 'Standing reach',
            'value_type'    => 'numeric',
            'unit'          => 'cm',
            'dimension'     => 'length',
            'entry_unit_id' => (int) $cm->id,
            'direction'     => 'higher',
        ] );
        $this->assertGreaterThan( 0, $id );

        $def   = $repo->find( $id );
        $units = UnitContext::forDefinition( $def );
        $base  = $units->parse( '213' )['value'];

        $wpdb->insert( $wpdb->prefix . 'tt_measurement_results', [
            'club_id'         => 1,
            'player_id'       => 1,
            'definition_id'   => $id,
            'recorded_date'   => '2026-04-01',
            'value_numeric'   => $base,
            'entered_unit_id' => (int) $cm->id,
            'entered_value'   => 213,
        ] );
        $result_id = (int) $wpdb->insert_id;

        // The operator decides the test should be recorded in metres.
        $repo->update( $id, [ 'unit' => 'm', 'dimension' => 'length', 'entry_unit_id' => (int) $m->id ] );

        $stored = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT value_numeric FROM {$wpdb->prefix}tt_measurement_results WHERE id = %d",
            $result_id
        ) );
        $entered_unit = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT entered_unit_id FROM {$wpdb->prefix}tt_measurement_results WHERE id = %d",
            $result_id
        ) );

        // Same physical quantity, still 2.13 metres, still stamped as having
        // been entered in centimetres.
        $this->assertEqualsWithDelta( 2.13, $stored, 0.00001 );
        $this->assertSame( (int) $cm->id, $entered_unit );

        // And it now reads as metres, because that is what the test asks for
        // today — the value did not move, the presentation did.
        $this->assertSame( '2.13 m', UnitContext::forDefinition( $repo->find( $id ) )->format( $stored ) );
    }

    public function test_classifying_an_unclassified_test_converts_its_history(): void {
        global $wpdb;

        $cm = ( new UnitRegistry() )->bySymbol( 'cm' );
        $this->assertNotNull( $cm );

        // What migration 0252 leaves behind when it cannot classify a test: a
        // dimensionless definition whose values are exactly as they were typed.
        $repo = new MeasurementDefinitionsRepository();
        $id   = $repo->create( [
            'category_id' => 1,
            'name'        => 'Reach (unclassified)',
            'value_type'  => 'numeric',
            'unit'        => 'lengte-eenheid',
            'dimension'   => 'dimensionless',
            'direction'   => 'higher',
        ] );

        $wpdb->insert( $wpdb->prefix . 'tt_measurement_results', [
            'club_id'       => 1,
            'player_id'     => 1,
            'definition_id' => $id,
            'recorded_date' => '2026-04-01',
            'value_numeric' => 213,
        ] );
        $result_id = (int) $wpdb->insert_id;

        // Left alone, the number means whatever it always meant.
        $this->assertSame( '213 lengte-eenheid', UnitContext::forDefinition( $repo->find( $id ) )->format( 213.0 ) );

        // The operator classifies it: this test measures centimetres.
        $repo->update( $id, [ 'unit' => 'cm', 'dimension' => 'length', 'entry_unit_id' => (int) $cm->id ] );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT value_numeric, entered_value, entered_unit_id
               FROM {$wpdb->prefix}tt_measurement_results WHERE id = %d",
            $result_id
        ) );

        // 213 was centimetres all along, so it is 2.13 metres now — and the
        // reading still displays as the 213 cm somebody wrote down.
        $this->assertEqualsWithDelta( 2.13, (float) $row->value_numeric, 0.00001 );
        $this->assertEqualsWithDelta( 213.0, (float) $row->entered_value, 0.00001 );
        $this->assertSame( (int) $cm->id, (int) $row->entered_unit_id );
        $this->assertSame( '213 cm', UnitContext::forDefinition( $repo->find( $id ) )->format( (float) $row->value_numeric ) );
    }

    public function test_reclassifying_twice_does_not_convert_twice(): void {
        global $wpdb;

        $registry = new UnitRegistry();
        $cm       = $registry->bySymbol( 'cm' );
        $mm       = $registry->bySymbol( 'mm' );
        $this->assertNotNull( $cm );
        $this->assertNotNull( $mm );

        $repo = new MeasurementDefinitionsRepository();
        $id   = $repo->create( [
            'category_id' => 1,
            'name'        => 'Reach (twice)',
            'value_type'  => 'numeric',
            'unit'        => 'iets',
            'dimension'   => 'dimensionless',
            'direction'   => 'higher',
        ] );

        $wpdb->insert( $wpdb->prefix . 'tt_measurement_results', [
            'club_id'       => 1,
            'player_id'     => 1,
            'definition_id' => $id,
            'recorded_date' => '2026-04-01',
            'value_numeric' => 213,
        ] );
        $result_id = (int) $wpdb->insert_id;

        $repo->update( $id, [ 'unit' => 'cm', 'dimension' => 'length', 'entry_unit_id' => (int) $cm->id ] );
        // A second edit is a change of display unit, not a reclassification.
        $repo->update( $id, [ 'unit' => 'mm', 'dimension' => 'length', 'entry_unit_id' => (int) $mm->id ] );

        $stored = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT value_numeric FROM {$wpdb->prefix}tt_measurement_results WHERE id = %d",
            $result_id
        ) );

        $this->assertEqualsWithDelta( 2.13, $stored, 0.00001 );
    }
}
