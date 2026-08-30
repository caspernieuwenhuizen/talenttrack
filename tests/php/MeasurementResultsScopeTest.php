<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\REST\MeasurementsRestController;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;
use TT\Modules\Export\Exporters\MeasurementResultsXlsxExporter;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\ScopeGatedExporter;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;

/**
 * #3155 — the Test results view scoped its rows to the reader's teams; the
 * REST route behind it and the XLSX export of the same data did not.
 *
 * `can_browse_results()` is `MatrixGate::canAnyScope( …, 'measurements',
 * 'read' )`, which head_coach, assistant_coach and team_manager all pass on
 * a *team*-scoped grant. With `team_id` omitted the response carried
 * `player_id`, full name, team, age group and the measured value for every
 * player in the academy with a result for that test. The export route was a
 * second door to the same rows, behind a `requiredCap()` of `''`.
 */
final class MeasurementResultsScopeTest extends WP_UnitTestCase {

    private int $mineTeamId    = 0;
    private int $otherTeamId   = 0;
    private int $definitionId  = 0;
    private int $coachUserId   = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Measure Mine', 'age_group' => 'U13' ] );
        $this->mineTeamId = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Measure Theirs', 'age_group' => 'U15' ] );
        $this->otherTeamId = (int) $wpdb->insert_id;

        $mine  = $this->makePlayer( 'Ilse', 'Verkuylen', $this->mineTeamId );
        $other = $this->makePlayer( 'Joris', 'Steenkamp', $this->otherTeamId );

        $wpdb->insert( $wpdb->prefix . 'tt_measurement_definitions', [
            'club_id'     => 1,
            'category_id' => 1,
            'name'        => 'Scope Sprint 10m',
            'value_type'  => 'numeric',
            'unit'        => 's',
            'frequency'   => 'adhoc',
            'direction'   => 'lower',
            'is_active'   => 1,
        ] );
        $this->definitionId = (int) $wpdb->insert_id;

        $this->makeResult( $mine,  '1.90' );
        $this->makeResult( $other, '2.10' );

        $this->coachUserId = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Measure',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $this->coachUserId,
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->mineTeamId,
        ] );

        wp_set_current_user( $this->coachUserId );
    }

    private function makePlayer( string $first, string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => $first,
            'last_name'  => $last,
            'team_id'    => $team_id,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeResult( int $player_id, string $value ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_measurement_results', [
            'club_id'       => 1,
            'definition_id' => $this->definitionId,
            'player_id'     => $player_id,
            'recorded_date' => '2026-02-01',
            'value_numeric' => $value,
        ] );
    }

    private function browse( int $team_id = 0 ) {
        $r = new \WP_REST_Request();
        $r->set_param( 'definition_id', $this->definitionId );
        if ( $team_id > 0 ) $r->set_param( 'team_id', $team_id );
        return MeasurementsRestController::browse_results( $r );
    }

    /** @param mixed $response */
    private function namesIn( $response ): array {
        $data = $response->get_data();
        return array_map( static fn ( $row ): string => (string) $row['name'], (array) $data['rows'] );
    }

    // ---------------------------------------------------------------
    // the REST route
    // ---------------------------------------------------------------

    public function test_browse_with_no_team_returns_only_the_callers_teams(): void {
        $names = $this->namesIn( $this->browse() );
        $this->assertContains( 'Ilse Verkuylen', $names );
        $this->assertNotContains(
            'Joris Steenkamp', $names,
            'omitting team_id must not widen the answer to the whole academy'
        );
    }

    public function test_browse_with_an_out_of_scope_team_is_403(): void {
        $out = $this->browse( $this->otherTeamId );
        $this->assertInstanceOf( \WP_Error::class, $out );
        $this->assertSame( 403, $out->get_error_data()['status'] ?? 0 );
    }

    public function test_browse_with_the_callers_own_team_still_works(): void {
        $names = $this->namesIn( $this->browse( $this->mineTeamId ) );
        $this->assertSame( [ 'Ilse Verkuylen' ], $names );
    }

    public function test_a_coach_with_no_teams_gets_nothing_not_the_club(): void {
        $orphan = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        wp_set_current_user( $orphan );

        $this->assertSame( [], $this->namesIn( $this->browse() ) );
    }

    public function test_a_global_reader_still_sees_the_academy(): void {
        $admin = self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        wp_set_current_user( $admin );

        $names = $this->namesIn( $this->browse() );
        $this->assertContains( 'Ilse Verkuylen', $names );
        $this->assertContains( 'Joris Steenkamp', $names );
    }

    // ---------------------------------------------------------------
    // the repository key both doors use
    // ---------------------------------------------------------------

    public function test_an_empty_team_ids_list_means_nothing_not_no_filter(): void {
        $repo = new MeasurementResultsRepository();

        $this->assertSame( [], $repo->listLatestWithPreviousForDefinition(
            $this->definitionId, [ 'team_ids' => [] ]
        ) );
        $this->assertCount( 1, $repo->listLatestWithPreviousForDefinition(
            $this->definitionId, [ 'team_ids' => [ $this->mineTeamId ] ]
        ) );
    }

    public function test_team_ids_applies_alongside_team_id_never_instead_of_it(): void {
        // A chosen team outside the reader's scope returns nothing rather
        // than silently widening to everything they can see.
        $rows = ( new MeasurementResultsRepository() )->listLatestWithPreviousForDefinition(
            $this->definitionId,
            [ 'team_id' => $this->otherTeamId, 'team_ids' => [ $this->mineTeamId ] ]
        );
        $this->assertSame( [], $rows );
    }

    // ---------------------------------------------------------------
    // the export, the second door
    // ---------------------------------------------------------------

    public function test_the_export_returns_the_same_rows_the_endpoint_would(): void {
        wp_set_current_user( $this->coachUserId );

        $payload = ( new MeasurementResultsXlsxExporter() )->collect( new ExportRequest(
            exporterKey: 'measurement_results_xlsx',
            format: 'xlsx',
            clubId: 1,
            requesterUserId: $this->coachUserId,
            filters: [ 'definition_id' => $this->definitionId ]
        ) );

        $flat = wp_json_encode( $payload );
        $this->assertStringContainsString( 'Verkuylen', (string) $flat );
        $this->assertStringNotContainsString(
            'Steenkamp', (string) $flat,
            'the workbook must not carry a child from a team the caller does not coach'
        );
    }

    public function test_the_export_refuses_an_out_of_scope_team(): void {
        wp_set_current_user( $this->coachUserId );

        $this->expectException( ExportException::class );
        ( new MeasurementResultsXlsxExporter() )->collect( new ExportRequest(
            exporterKey: 'measurement_results_xlsx',
            format: 'xlsx',
            clubId: 1,
            requesterUserId: $this->coachUserId,
            filters: [ 'definition_id' => $this->definitionId, 'team_id' => $this->otherTeamId ]
        ) );
    }

    public function test_the_exporter_declares_a_real_coarse_gate(): void {
        $exporter = new MeasurementResultsXlsxExporter();
        $this->assertInstanceOf(
            ScopeGatedExporter::class, $exporter,
            'the measurements entity is matrix-only, so the coarse gate is a scope question, not a capability string'
        );
        $this->assertTrue( $exporter->isAvailableFor( $this->coachUserId ) );
        $this->assertFalse(
            $exporter->isAvailableFor( self::factory()->user->create( [ 'role' => 'subscriber' ] ) )
        );
    }

    /**
     * The pipeline's coarse gate is a capability string, which cannot express
     * a matrix question — so an exporter over a matrix-only entity has to say
     * so through the interface rather than by returning ''. Assert nobody
     * else is quietly ungated.
     */
    public function test_no_exporter_is_left_with_an_empty_gate(): void {
        foreach ( ExporterRegistry::all() as $key => $exporter ) {
            if ( $exporter instanceof ScopeGatedExporter ) continue;
            $this->assertNotSame(
                '', $exporter->requiredCap(),
                "exporter {$key} has neither a capability nor a scope gate"
            );
        }
    }
}
