<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Journey\InjuryReads;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Visibility\ReaderSet;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Frontend\TeamMonthlyReportSnapshotPage;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportSnapshots;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamReportSnapshotRepository;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\PlayerReportPdfExporter;
use TT\Modules\Export\Exporters\TeamMonthlyReportPdfExporter;
use TT\Modules\Pdp\EvidencePacket;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * The player report and the team monthly report log a read of a player's
 * injuries as the injuries screens do (`player.injuries_viewed`), and the
 * monthly report's medical and safeguarding entries as the journey timeline
 * does (`player.sensitive_timeline_viewed`). A reader who is shown none
 * writes none, and so does a composition for several readers.
 */
final class ReportInjuryReadAuditTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $club = 1;
    private int $team = 0;
    private int $player = 0;
    private int $teammate = 0;
    private int $hod = 0;
    private int $assistant = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Audit U15', 'age_group' => 'U15' ] );
        $this->team     = (int) $wpdb->insert_id;
        $this->player   = $this->player( 'Iris' );
        $this->teammate = $this->player( 'Jop' );

        $this->hod       = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->assistant = $this->assistantOf( $this->team );
        $this->seed();

        FeatureRegistry::setEnabled( 'journey_medical_visibility', true );
        $this->flush();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        $this->flush();
        parent::tear_down();
    }

    // ---- the player report -----------------------------------------------------------

    public function test_the_player_report_logs_one_read_on_each_surface(): void {
        $this->assertTrue( ReaderSet::of( [ $this->hod ] )->seesMedical(), 'Precondition: the head of development reads injuries.' );
        wp_set_current_user( $this->hod );

        $before = $this->rows( 'player.injuries_viewed', $this->player );
        $report = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'injuries' ], $this->hod );
        $this->assertNotEmpty( $report['data']['injuries']['items'] ?? [], 'Precondition: the report shows the injury.' );
        $this->assertSame( $before + 1, $this->rows( 'player.injuries_viewed', $this->player ), 'the page\'s composition' );
        $this->assertSame( 'player_report', $this->lastSurface( $this->player ) );

        $this->flush();
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/report' );
        $req->set_param( 'from', self::FROM );
        $req->set_param( 'to', self::TO );
        $req->set_param( 'blocks', 'injuries' );
        $this->assertSame( 200, rest_get_server()->dispatch( $req )->get_status() );
        $this->assertSame( $before + 2, $this->rows( 'player.injuries_viewed', $this->player ), 'the REST route' );

        $this->flush();
        ( new PlayerReportPdfExporter() )->collect( new ExportRequest( 'player_report_pdf', 'pdf', $this->club, $this->hod, null, [
            'player_id' => $this->player, 'from' => self::FROM, 'to' => self::TO, 'layout' => 'A', 'blocks' => [ 'injuries' ],
        ] ) );
        $this->assertSame( $before + 3, $this->rows( 'player.injuries_viewed', $this->player ), 'the PDF' );
    }

    public function test_a_reader_without_the_medical_right_sees_no_injury_and_writes_no_row(): void {
        $this->assertFalse( ReaderSet::of( [ $this->assistant ] )->seesMedical(), 'Precondition: the assistant coach does not read injuries.' );
        wp_set_current_user( $this->assistant );

        $before = $this->rows( 'player.injuries_viewed', $this->player );
        $report = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'injuries' ], $this->assistant );
        $this->assertIsArray( $report );
        $this->assertSame( [], $report['data']['injuries']['items'] ?? [], 'no injury details' );
        $this->assertSame( $before, $this->rows( 'player.injuries_viewed', $this->player ) );
    }

    public function test_a_composition_for_several_readers_writes_no_row(): void {
        wp_set_current_user( 0 );
        $other  = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $before = $this->rows( 'player.injuries_viewed', $this->player );

        ( new PlayerReport() )->forReaders( $this->player, self::FROM, self::TO, [ 'injuries' ], [ $this->hod, $other ] );
        $this->assertSame( $before, $this->rows( 'player.injuries_viewed', $this->player ) );
    }

    // ---- the team monthly report -----------------------------------------------------

    public function test_the_monthly_report_logs_one_row_per_player_shown(): void {
        wp_set_current_user( $this->hod );
        $before_player   = $this->rows( 'player.injuries_viewed', $this->player );
        $before_teammate = $this->rows( 'player.injuries_viewed', $this->teammate );
        $before_timeline = $this->rows( 'player.sensitive_timeline_viewed', $this->player );

        $report = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'changes' ], $this->hod );
        $types  = array_column( (array) ( $report['data']['changes']['events'] ?? [] ), 'event_type', 'player_id' );
        $this->assertSame( 'injury_started', $types[ $this->player ] ?? '', 'Precondition: the injury entry is shown.' );

        $this->assertSame( $before_player + 1, $this->rows( 'player.injuries_viewed', $this->player ) );
        $this->assertSame( $before_teammate, $this->rows( 'player.injuries_viewed', $this->teammate ), 'a player with no injury shown is not logged' );
        $this->assertSame( 'team_monthly_report', $this->lastSurface( $this->player ) );
        $this->assertSame( $before_timeline + 1, $this->rows( 'player.sensitive_timeline_viewed', $this->player ), 'the medical entry, as the timeline logs it' );
    }

    public function test_a_monthly_reader_without_the_medical_level_writes_no_row(): void {
        wp_set_current_user( $this->assistant );
        $before = $this->rows( 'player.injuries_viewed', $this->player );

        $report = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'changes' ], $this->assistant );
        $types  = array_column( (array) ( $report['data']['changes']['events'] ?? [] ), 'event_type' );
        $this->assertNotContains( 'injury_started', $types, 'Precondition: no injury entry for this reader.' );
        $this->assertSame( $before, $this->rows( 'player.injuries_viewed', $this->player ) );
    }

    public function test_a_scheduled_monthly_composition_writes_no_row(): void {
        wp_set_current_user( 0 );
        $before = $this->rows( 'player.injuries_viewed', $this->player );

        ( new TeamMonthlyReport() )->forReaders( $this->team, self::FROM, self::TO, [ 'changes' ], [ $this->hod ] );
        $this->assertSame( $before, $this->rows( 'player.injuries_viewed', $this->player ) );
    }

    // ---- snapshots -------------------------------------------------------------------

    public function test_opening_a_player_snapshot_that_lists_injuries_writes_one_row(): void {
        wp_set_current_user( $this->hod );
        $uuid = PlayerReportSnapshots::take( $this->player, [ 'from' => self::FROM, 'to' => self::TO, 'blocks' => 'injuries' ], $this->hod );
        $this->assertNotSame( '', $uuid );
        $before = $this->rowsFor( 'player_report_snapshot', $this->player );

        $this->flush();
        $res = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/player-report-snapshots/' . $uuid ) );
        $this->assertSame( 200, $res->get_status() );
        $this->assertNotEmpty( $res->get_data()['data']['report']['data']['injuries']['items'] ?? [], 'Precondition: the snapshot lists the injury.' );
        $this->assertSame( $before + 1, $this->rowsFor( 'player_report_snapshot', $this->player ), 'the route reads the snapshot twice, one read' );

        $this->flush();
        ( new PlayerReportPdfExporter() )->collect( new ExportRequest( 'player_report_pdf', 'pdf', $this->club, $this->hod, null, [ 'snapshot' => $uuid ] ) );
        $this->assertSame( $before + 2, $this->rowsFor( 'player_report_snapshot', $this->player ), 'the PDF' );
    }

    public function test_taking_and_sharing_a_snapshot_write_no_row(): void {
        wp_set_current_user( $this->hod );
        $before = $this->rows( 'player.injuries_viewed', $this->player );

        $this->assertNotSame( '', PlayerReportSnapshots::take( $this->player, [ 'from' => self::FROM, 'to' => self::TO, 'blocks' => 'injuries' ], $this->hod ) );
        $this->flush();
        $this->assertNotSame( '', PlayerReportSnapshots::share( $this->player, [ 'from' => self::FROM, 'to' => self::TO ], $this->hod ) );
        $this->assertSame( $before, $this->rows( 'player.injuries_viewed', $this->player ), 'a copy that is stored, not shown' );
    }

    public function test_the_rest_snapshot_create_writes_one_row_for_the_copy_it_returns(): void {
        wp_set_current_user( $this->hod );
        $before = $this->rows( 'player.injuries_viewed', $this->player );

        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/players/' . $this->player . '/report-snapshots' );
        $req->set_param( 'from', self::FROM );
        $req->set_param( 'to', self::TO );
        $req->set_param( 'blocks', 'injuries' );
        $res = rest_get_server()->dispatch( $req );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );

        $this->assertSame( $before + 1, $this->rows( 'player.injuries_viewed', $this->player ), 'one request, one read' );
        $this->assertSame( 1, $this->rowsFor( 'player_report_snapshot', $this->player ), 'the copy the route answers with' );
    }

    public function test_a_report_without_the_injuries_section_writes_no_row(): void {
        wp_set_current_user( $this->hod );
        $before = $this->rows( 'player.injuries_viewed', $this->player );

        ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'ratings' ], $this->hod );
        $this->flush();
        ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'injuries', 'ratings' ], $this->hod, PlayerReportAudience::FAMILY );
        $this->flush();
        ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'injuries', 'ratings' ], $this->hod, PlayerReportAudience::SCOUT );
        $this->assertSame( $before, $this->rows( 'player.injuries_viewed', $this->player ), 'ratings only, a family and a scout composition' );
    }

    public function test_a_team_composition_stored_not_shown_writes_no_row(): void {
        wp_set_current_user( $this->hod );
        $before = $this->rows( 'player.injuries_viewed', $this->player );

        InjuryReads::quietly( fn() => ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'changes' ], $this->hod ) );
        $this->assertSame( $before, $this->rows( 'player.injuries_viewed', $this->player ) );
        $this->assertSame( 0, $this->rowsFor( 'team_monthly_report', $this->player ) );
    }

    public function test_a_player_snapshot_without_injury_items_writes_no_row(): void {
        wp_set_current_user( $this->hod );
        $uuid   = PlayerReportSnapshots::take( $this->player, [ 'from' => self::FROM, 'to' => self::TO, 'blocks' => 'status' ], $this->hod );
        $before = $this->rowsFor( 'player_report_snapshot', $this->player );

        $this->assertNotNull( PlayerReportSnapshots::read( $uuid, $this->hod ) );
        $this->assertSame( $before, $this->rowsFor( 'player_report_snapshot', $this->player ) );
    }

    public function test_opening_a_team_snapshot_that_lists_injury_entries_writes_one_row_per_player(): void {
        wp_set_current_user( $this->hod );
        $frozen = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'changes' ], $this->hod );
        $uuid   = ( new TeamReportSnapshotRepository() )->create( $this->team, [ 'team_id' => $this->team, 'layout' => 'A', 'blocks' => [ 'changes' ] ], $frozen, 'Snapshot', $this->hod );
        $this->assertNotSame( '', $uuid );
        $before   = $this->rowsFor( 'team_monthly_snapshot', $this->player );
        $teammate = $this->rowsFor( 'team_monthly_snapshot', $this->teammate );

        $this->flush();
        ob_start();
        $this->assertTrue( TeamMonthlyReportSnapshotPage::render( $uuid ) );
        ob_end_clean();
        $this->assertSame( $before + 1, $this->rowsFor( 'team_monthly_snapshot', $this->player ), 'the page' );

        $this->flush();
        ( new TeamMonthlyReportPdfExporter() )->collect( new ExportRequest( 'team_monthly_report_pdf', 'pdf', $this->club, $this->hod, null, [ 'snapshot' => $uuid ] ) );
        $this->assertSame( $before + 2, $this->rowsFor( 'team_monthly_snapshot', $this->player ), 'the PDF' );
        $this->assertSame( $teammate, $this->rowsFor( 'team_monthly_snapshot', $this->teammate ), 'a player with no injury entry is not logged' );
    }

    // ---- the PDP path ----------------------------------------------------------------

    public function test_the_pdp_packet_logs_the_injury_read_for_the_signed_in_reader(): void {
        $file = $this->pdpFile();

        wp_set_current_user( 0 );
        $before = $this->rowsFor( 'pdp', $this->player );
        $this->assertNotEmpty( EvidencePacket::forFile( $file )['injuries'] ?? [], 'Precondition: the packet carries the injury.' );
        $this->assertSame( $before, $this->rowsFor( 'pdp', $this->player ), 'nobody signed in: no row' );

        wp_set_current_user( $this->hod );
        EvidencePacket::forFile( $file );
        $this->assertSame( $before + 1, $this->rowsFor( 'pdp', $this->player ) );
    }

    // ---- medical and safeguarding journey entries ------------------------------------

    public function test_the_player_report_journey_logs_its_medical_entry_on_each_surface(): void {
        wp_set_current_user( $this->hod );
        $before = $this->sensitiveFor( 'player_report', $this->player );

        $report = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'journey' ], $this->hod );
        $this->assertCount( 1, (array) ( $report['data']['journey']['items'] ?? [] ), 'Precondition: the medical entry is shown.' );
        $this->assertSame( $before + 1, $this->sensitiveFor( 'player_report', $this->player ), 'the page' );

        $this->flush();
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/report' );
        $req->set_param( 'from', self::FROM );
        $req->set_param( 'to', self::TO );
        $req->set_param( 'blocks', 'journey' );
        $this->assertSame( 200, rest_get_server()->dispatch( $req )->get_status() );
        $this->assertSame( $before + 2, $this->sensitiveFor( 'player_report', $this->player ), 'the REST route' );

        $this->flush();
        ( new PlayerReportPdfExporter() )->collect( new ExportRequest( 'player_report_pdf', 'pdf', $this->club, $this->hod, null, [
            'player_id' => $this->player, 'from' => self::FROM, 'to' => self::TO, 'layout' => 'A', 'blocks' => [ 'journey' ],
        ] ) );
        $this->assertSame( $before + 3, $this->sensitiveFor( 'player_report', $this->player ), 'the PDF' );
    }

    public function test_a_reader_without_the_medical_level_writes_no_journey_row(): void {
        wp_set_current_user( $this->assistant );
        $before = $this->rows( InjuryReads::SENSITIVE, $this->player );

        $report = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'journey' ], $this->assistant );
        $this->assertSame( [], (array) ( $report['data']['journey']['items'] ?? [] ), 'Precondition: no medical entry for this reader.' );
        $this->assertSame( $before, $this->rows( InjuryReads::SENSITIVE, $this->player ) );
    }

    public function test_opening_a_player_snapshot_with_a_medical_entry_writes_one_row(): void {
        wp_set_current_user( $this->hod );
        $uuid = PlayerReportSnapshots::take( $this->player, [ 'from' => self::FROM, 'to' => self::TO, 'blocks' => 'journey' ], $this->hod );
        $this->assertNotSame( '', $uuid );
        $before = $this->sensitiveFor( 'player_report_snapshot', $this->player );

        foreach ( [ [ $this->assistant, 0 ], [ $this->hod, 1 ] ] as [ $reader, $rows ] ) {
            $this->flush();
            wp_set_current_user( $reader );
            $this->assertNotNull( PlayerReportSnapshots::read( $uuid, $reader ) );
            $this->assertSame( $before + $rows, $this->sensitiveFor( 'player_report_snapshot', $this->player ), 'only where the cut keeps the entry' );
        }
    }

    public function test_opening_a_team_snapshot_with_a_medical_entry_writes_one_row_per_player(): void {
        wp_set_current_user( $this->hod );
        $frozen = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'changes' ], $this->hod );
        $uuid   = ( new TeamReportSnapshotRepository() )->create( $this->team, [ 'team_id' => $this->team, 'layout' => 'A', 'blocks' => [ 'changes' ] ], $frozen, 'Snapshot', $this->hod );
        $before   = $this->sensitiveFor( 'team_monthly_snapshot', $this->player );
        $teammate = $this->sensitiveFor( 'team_monthly_snapshot', $this->teammate );

        $this->flush();
        ob_start();
        $this->assertTrue( TeamMonthlyReportSnapshotPage::render( $uuid ) );
        ob_end_clean();
        $this->assertSame( $before + 1, $this->sensitiveFor( 'team_monthly_snapshot', $this->player ), 'the page' );

        $this->flush();
        ( new TeamMonthlyReportPdfExporter() )->collect( new ExportRequest( 'team_monthly_report_pdf', 'pdf', $this->club, $this->hod, null, [ 'snapshot' => $uuid ] ) );
        $this->assertSame( $before + 2, $this->sensitiveFor( 'team_monthly_snapshot', $this->player ), 'the PDF' );
        $this->assertSame( $teammate, $this->sensitiveFor( 'team_monthly_snapshot', $this->teammate ), 'a public entry is not logged' );
    }

    // ---- the batched write and the quiet mode ----------------------------------------

    public function test_a_read_of_many_players_costs_a_fixed_number_of_statements(): void {
        wp_set_current_user( $this->hod );
        $few  = $this->statementsFor( 2 );
        $many = $this->statementsFor( 12 );

        $this->assertSame( $few, $many, 'one table check and one insert, whatever the squad' );
        $this->assertLessThanOrEqual( 2, $many );
    }

    public function test_nothing_is_written_while_quiet(): void {
        wp_set_current_user( $this->hod );
        $before = $this->allRows();

        InjuryReads::quietly( function (): void {
            ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'injuries', 'journey' ], $this->hod );
            ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'changes' ], $this->hod );
            EvidencePacket::forFile( $this->pdpFile() );
            $uuid = PlayerReportSnapshots::take( $this->player, [ 'from' => self::FROM, 'to' => self::TO, 'blocks' => 'injuries,journey' ], $this->hod );
            PlayerReportSnapshots::read( $uuid, $this->hod );
        } );
        $this->assertSame( $before, $this->allRows() );
    }

    // ---- helpers ---------------------------------------------------------------------

    /** The audit statements one read of this many players costs. */
    private function statementsFor( int $players ): int {
        global $wpdb;
        $this->flush();
        $counts = [];
        for ( $i = 1; $i <= $players; $i++ ) $counts[ 900000 + $i ] = 1;
        $before = (int) $wpdb->num_queries;
        InjuryReads::record( InjuryReads::INJURIES, 'player_report', $counts, [ $this->hod ] );
        return (int) $wpdb->num_queries - $before;
    }

    private function sensitiveFor( string $surface, int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = 'player.sensitive_timeline_viewed' AND entity_type = 'player' AND entity_id = %d AND payload LIKE %s",
            $player_id,
            '%"surface":"' . $wpdb->esc_like( $surface ) . '"%'
        ) );
    }

    private function allRows(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action IN ('player.injuries_viewed', 'player.sensitive_timeline_viewed')" );
    }

    private function rowsFor( string $surface, int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = 'player.injuries_viewed' AND entity_type = 'player' AND entity_id = %d AND payload LIKE %s",
            $player_id,
            '%"surface":"' . $wpdb->esc_like( $surface ) . '"%'
        ) );
    }

    /** A PDP file for the player over a season that holds March 2020. */
    private function pdpFile(): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_seasons", [ 'club_id' => $this->club, 'name' => '2019/20', 'start_date' => '2019-08-01', 'end_date' => '2020-06-30' ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_pdp_files", [ 'club_id' => $this->club, 'player_id' => $this->player, 'season_id' => $season, 'status' => 'open' ] );
        return (int) $wpdb->insert_id;
    }

    private function rows( string $action, int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = %s AND entity_type = 'player' AND entity_id = %d",
            $action,
            $player_id
        ) );
    }

    private function lastSurface( int $player_id ): string {
        global $wpdb;
        $payload = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log WHERE action = 'player.injuries_viewed' AND entity_type = 'player' AND entity_id = %d ORDER BY id DESC LIMIT 1",
            $player_id
        ) );
        $decoded = json_decode( $payload, true );
        return is_array( $decoded ) ? (string) ( $decoded['surface'] ?? '' ) : '';
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        // Each surface below stands for a request of its own.
        InjuryReads::flush();
        wp_cache_flush();
    }

    private function player( string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => $first, 'last_name' => 'Audit', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** An assistant coach of one team, through the auth bridge: the person, the team seat and the scope. */
    private function assistantOf( int $team ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Assistant', 'last_name' => 'Audit',
            'role_type' => 'assistant_coach', 'wp_user_id' => $user, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'team_id' => $team, 'person_id' => $person, 'role_in_team' => 'assistant_coach', 'is_head_coach' => 0,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
        return $user;
    }

    /** March 2020: an injury for the player with its medical journey entry, and a public change for the teammate. */
    private function seed(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_player_injuries", [
            'club_id' => $this->club, 'player_id' => $this->player, 'started_on' => '2020-03-02', 'actual_return' => '2020-03-09', 'notes' => 'Hamstring strain.',
        ] );
        $events = [
            [ $this->player, 'injury_started', '2020-03-02 09:00:00', 'Injured: hamstring.', 'medical' ],
            [ $this->teammate, 'position_changed', '2020-03-12 09:00:00', 'Moved to centre back.', 'public' ],
        ];
        foreach ( $events as $i => [ $pid, $type, $when, $summary, $visibility ] ) {
            $wpdb->insert( "{$p}tt_player_events", [
                'club_id' => $this->club, 'uuid' => wp_generate_uuid4(), 'player_id' => $pid, 'event_type' => $type,
                'event_date' => $when, 'summary' => $summary, 'visibility' => $visibility,
                'source_module' => 'tests', 'source_entity_type' => 'test_event', 'source_entity_id' => $i + 1,
            ] );
        }
    }
}
