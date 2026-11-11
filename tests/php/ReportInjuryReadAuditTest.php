<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Visibility\ReaderSet;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\PlayerReportPdfExporter;
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

    // ---- helpers ---------------------------------------------------------------------

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
