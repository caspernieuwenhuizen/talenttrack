<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;

/**
 * #4692 — why a child missed or will miss an activity follows the
 * sensitive-field registry: the register's note, the note beside a planned
 * status and the coach's reason in match prep go to staff for the player and
 * the family; any other reader, the read-only observer first, keeps the
 * status and gets no reason.
 *
 * Every refusal is paired with a reader who keeps the text, and the
 * observer's read of the activity is asserted first.
 */
final class AbsenceReasonMaskingTest extends WP_UnitTestCase {

    private const PLAN_NOTE   = 'Plan note 4692: stomach bug since Sunday';
    private const REG_NOTE    = 'Register note 4692: family funeral';
    private const PREP_REASON = 'Prep reason 4692: hamstring check';

    private int $club     = 0;
    private int $team     = 0;
    private int $player   = 0;
    private int $activity = 0;
    private int $observer = 0;
    private int $hod      = 0;
    private int $parent   = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO11-1', 'football_form' => '8v8' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Mila', 'last_name' => 'Kok', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Uitwedstrijd 4692',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
            'activity_type_key'   => 'match',
            'activity_status_key' => 'completed',
            'plan_state'          => 'scheduled',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        foreach ( [ 'expected' => self::PLAN_NOTE, 'actual' => self::REG_NOTE ] as $type => $note ) {
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id'     => $this->club,
                'activity_id' => $this->activity,
                'player_id'   => $this->player,
                'is_guest'    => 0,
                'status'      => 'Absent',
                'notes'       => $note,
                'record_type' => $type,
            ] );
        }

        $this->observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->hod      = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->parent   = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id' => $this->club, 'player_id' => $this->player, 'parent_user_id' => $this->parent,
        ] );
        AuthorizationService::flushCache();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->assertTrue( AuthorizationService::canViewPlayer( $this->observer, $this->player ), 'the observer reads the player' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_the_registry_names_the_reason_fields(): void {
        $fields = RecordRedactor::fields( 'attendance' );
        $this->assertSame( 'staff_or_family', $fields['notes'] ?? null );
        $this->assertSame( 'staff_or_family', $fields['reason'] ?? null );
        $this->assertArrayNotHasKey( 'status', $fields, 'the status stays for every reader' );
        $this->assertArrayNotHasKey( 'plan_status', $fields );

        $this->assertTrue( RecordRedactor::isMasked( 'attendance', 'notes', $this->observer, $this->player ) );
        $this->assertFalse( RecordRedactor::isMasked( 'attendance', 'notes', $this->hod, $this->player ) );
        $this->assertFalse( RecordRedactor::isMasked( 'attendance', 'notes', $this->parent, $this->player ), 'the family keeps it' );
    }

    public function test_planned_attendance_keeps_the_status_and_leaves_the_note_out(): void {
        $observer = $this->plannedRow( $this->observer );
        $this->assertSame( 'not_coming', $observer['plan_status'] ?? null, 'the observer keeps the planned status' );
        $this->assertArrayNotHasKey( 'notes', $observer );

        $this->assertSame( self::PLAN_NOTE, $this->plannedRow( $this->hod )['notes'] ?? null, 'HoD keeps the note' );
    }

    public function test_the_match_prep_reason_is_left_out_for_the_observer(): void {
        wp_set_current_user( $this->hod );
        AuthorizationService::flushCache();
        $save = new WP_REST_Request( 'PUT', '/talenttrack/v1/match-prep/' . $this->activity );
        $save->set_header( 'content-type', 'application/json' );
        $save->set_body( (string) wp_json_encode( [
            'availability' => [ $this->player => [ 'status' => 'Injured', 'reason' => self::PREP_REASON ] ],
        ] ) );
        $this->assertSame( 200, $this->serve( $save )->get_status(), 'HoD saves the prep' );

        $hod = (string) wp_json_encode( $this->prepState( $this->hod ) );
        $this->assertStringContainsString( self::PREP_REASON, $hod, 'HoD keeps the reason' );

        $observer = $this->prepState( $this->observer );
        $body     = (string) wp_json_encode( $observer );
        $this->assertStringContainsString( 'Injured', $body, 'the observer keeps the status' );
        $this->assertStringNotContainsString( 'hamstring', $body );
    }

    public function test_the_attendance_register_file_leaves_the_note_out_for_the_observer(): void {
        $this->assertStringContainsString( 'family funeral', $this->registerBody( $this->hod ) );
        $observer = $this->registerBody( $this->observer );
        $this->assertStringContainsString( 'Mila', $observer, 'the observer gets the rows' );
        $this->assertStringNotContainsString( 'family funeral', $observer );
    }

    // ---- fixtures ------------------------------------------------------

    private function serve( WP_REST_Request $request ): WP_REST_Response {
        $server   = rest_get_server();
        $response = rest_ensure_response( $server->dispatch( $request ) );
        $response = apply_filters( 'rest_post_dispatch', $response, $server, $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        return $response;
    }

    /** @return array<string, mixed> */
    private function plannedRow( int $user_id ): array {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $response = $this->serve( new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $this->activity . '/planned-attendance' ) );
        $this->assertSame( 200, $response->get_status(), 'user ' . $user_id . ' reads the plan' );
        $data = (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
        foreach ( (array) ( $data['data']['roster'] ?? [] ) as $row ) {
            if ( (int) ( $row['player_id'] ?? 0 ) === $this->player ) return (array) $row;
        }
        $this->fail( 'no planned row for the player' );
    }

    /** @return array<string, mixed> */
    private function prepState( int $user_id ): array {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $response = $this->serve( new WP_REST_Request( 'GET', '/talenttrack/v1/match-prep/' . $this->activity ) );
        $this->assertSame( 200, $response->get_status(), 'user ' . $user_id . ' reads the prep' );
        return (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
    }

    private function registerBody( int $user_id ): string {
        AuthorizationService::flushCache();
        $exporter = ExporterRegistry::get( 'attendance_register' );
        $this->assertNotNull( $exporter );
        $filters = (array) $exporter->validateFilters( [
            'team_id'   => $this->team,
            'date_from' => gmdate( 'Y-m-d', strtotime( '-10 days' ) ),
            'date_to'   => gmdate( 'Y-m-d' ),
        ] );
        $result = $exporter->collect( new ExportRequest( 'attendance_register', 'csv', $this->club, $user_id, null, $filters ) );
        return (string) wp_json_encode( $result['rows'] ?? [] );
    }
}
