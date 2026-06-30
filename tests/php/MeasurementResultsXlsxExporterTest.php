<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\ExportService;
use TT\Modules\Export\Exporters\MeasurementResultsXlsxExporter;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementLevelsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;

/**
 * #2139 — measurement results XLSX export.
 *
 * Covers the contract the issue mandates:
 *   (a) the exporter registers in the shared ExporterRegistry (reuses the
 *       generic export dispatch — no new REST route);
 *   (b) it requires a `definition_id` filter;
 *   (c) it denies a caller without `measurements/read` (the matrix gate),
 *       surfaced as a `forbidden` ExportException → 403;
 *   (d) a granted caller gets a valid .xlsx, and a status-type result's value
 *       cell carries the matched level LABEL painted in the level's colour
 *       style — the .xlsx mirror of the profile status chip.
 */
final class MeasurementResultsXlsxExporterTest extends WP_UnitTestCase {

    private const KEY     = 'measurement_results_xlsx';
    private const PERSONA = 'scout';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        ( new MatrixRepository() )->removeRow( self::PERSONA, 'measurements', 'read', 'global' );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_exporter_is_registered(): void {
        $exporter = ExporterRegistry::get( self::KEY );
        $this->assertInstanceOf( MeasurementResultsXlsxExporter::class, $exporter );
        $this->assertSame( [ 'xlsx' ], $exporter->supportedFormats() );
    }

    public function test_filters_require_a_definition_id(): void {
        $exporter = new MeasurementResultsXlsxExporter();
        $this->assertNull( $exporter->validateFilters( [] ), 'missing definition_id is rejected' );
        $this->assertNull( $exporter->validateFilters( [ 'definition_id' => 0 ] ) );

        $clean = $exporter->validateFilters( [ 'definition_id' => '42', 'team_id' => '7', 'date_from' => '2026-06-01' ] );
        $this->assertIsArray( $clean );
        $this->assertSame( 42, $clean['definition_id'] );
        $this->assertSame( 7, $clean['team_id'] );
        $this->assertSame( '2026-06-01', $clean['date_from'] );
    }

    public function test_unprivileged_caller_is_denied(): void {
        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $uid );

        $def_id = $this->makeStatusDefinitionWithResult();

        $this->expectException( ExportException::class );
        try {
            ( new ExportService() )->run( $this->request( $def_id, $uid ) );
        } catch ( ExportException $e ) {
            $this->assertSame( 'forbidden', $e->errorKey );
            throw $e;
        }
    }

    public function test_granted_caller_gets_xlsx_with_status_level_value(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        wp_set_current_user( $uid );

        // Grant the scout persona a global read on the measurements entity so
        // MatrixGate::canAnyScope( …, 'measurements', 'read' ) passes.
        ( new MatrixRepository() )->setRow(
            self::PERSONA, 'measurements', 'read', 'global',
            'TT\\Modules\\Measurements\\MeasurementsModule'
        );
        MatrixRepository::clearCache();

        $def_id = $this->makeStatusDefinitionWithResult();

        // collect() shapes the status value cell — assert the level label +
        // its colour style ride along.
        $exporter = new MeasurementResultsXlsxExporter();
        $payload  = $exporter->collect( $this->request( $def_id, $uid ) );
        $this->assertArrayHasKey( 'styled_sheets', $payload );

        $sheet = reset( $payload['styled_sheets'] );
        $found_status_cell = false;
        foreach ( $sheet['rows'] as $row ) {
            foreach ( (array) $row as $cell ) {
                if ( is_array( $cell ) && ( $cell['v'] ?? '' ) === 'On track' ) {
                    $found_status_cell = true;
                    $this->assertSame( 'lvl_green', $cell['style'] ?? '', 'status value cell carries its level colour style' );
                }
            }
        }
        $this->assertTrue( $found_status_cell, 'the status result renders its level label in the value column' );

        // End-to-end through the service → a real .xlsx download result.
        $result = ( new ExportService() )->run( $this->request( $def_id, $uid ) );
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $result->mime
        );
        $this->assertGreaterThan( 0, $result->size, 'a non-empty workbook is produced' );
    }

    private function request( int $definition_id, int $uid ): ExportRequest {
        return new ExportRequest(
            self::KEY,
            'xlsx',
            1,
            $uid,
            null,
            [ 'definition_id' => $definition_id ],
            null,
            null
        );
    }

    /** A status definition with one green "On track" level and one result. */
    private function makeStatusDefinitionWithResult(): int {
        global $wpdb;

        $def_id = ( new MeasurementDefinitionsRepository() )->create( [
            'category_id' => 1,
            'name'        => 'Match readiness',
            'value_type'  => 'status',
            'direction'   => 'neutral',
            'frequency'   => 'adhoc',
        ] );

        ( new MeasurementLevelsRepository() )->replaceForDefinition( $def_id, [
            [ 'label' => 'At risk',  'color_token' => 'red' ],
            [ 'label' => 'On track', 'color_token' => 'green' ],
        ] );

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Tess',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;

        ( new MeasurementResultsRepository() )->create( [
            'player_id'     => $player_id,
            'definition_id' => $def_id,
            'recorded_date' => '2026-06-20',
            'value_text'    => 'On track',
        ] );

        return $def_id;
    }
}
