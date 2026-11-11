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
use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportSnapshots;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\PlayerReportPdfExporter;
use TT\Modules\ReportStudio\Blocks\PlayerSectionBlock;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * A player report snapshot is shown under the reader's current section
 * rights, as a Report Studio snapshot is: for every section, the copy the
 * head of development froze reads for each reader as the live report reads
 * for them, on the REST route, the snapshot read and the PDF alike. A copy
 * for staff opens only for a reader who reads as staff.
 *
 * Note writes require `body`: leaving it out changes nothing.
 */
final class PlayerReportSnapshotSectionsTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private const INJURY  = 'Hamstring strain, left leg.';
    private const MEDICAL = 'Medical: scan booked.';
    private const PUBLIC  = 'Captain for the cup match.';
    private const NOTE    = 'Agreed on two extra sessions.';

    private int $club = 1;
    private int $team = 0;
    private int $player = 0;
    private int $hod = 0;
    private int $assistant = 0;
    private int $scout = 0;
    private int $observer = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Frozen U15', 'age_group' => 'U15' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Sven', 'last_name' => 'Frozen', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->hod       = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->assistant = $this->assistantOf( $this->team );
        $this->scout     = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        update_user_meta( $this->scout, 'tt_scout_player_ids', wp_json_encode( [ $this->player ] ) );
        $this->observer  = $this->persona( 'tt_readonly_observer', 'other' );
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

    public function test_each_reader_reads_the_snapshot_as_the_live_report(): void {
        $uuid = $this->snapshot();

        foreach ( [ 'head of development' => $this->hod, 'assistant coach' => $this->assistant, 'scout' => $this->scout, 'observer' => $this->observer ] as $who => $reader ) {
            $this->flush();
            $read  = PlayerReportSnapshots::read( $uuid, $reader );
            $opens = PlayerReportAccess::canRead( $reader, $this->player ) && PlayerReportAudience::forReader( $reader ) === PlayerReportAudience::INTERNAL;
            if ( ! $opens ) {
                $this->assertNull( $read, "{$who}: a copy for staff opens only for a reader who reads as staff" );
                continue;
            }
            $this->assertIsArray( $read, $who );

            $this->flush();
            $live = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, $this->sections(), $reader );
            $this->assertIsArray( $live, $who );
            foreach ( $this->sections() as $section ) {
                $this->assertSame(
                    $this->json( $live['data'][ $section ] ?? null ),
                    $this->json( $read['report']['data'][ $section ] ?? null ),
                    "{$section} for the {$who}: the snapshot reads as the live report"
                );
            }
        }
    }

    public function test_an_assistant_without_the_medical_rung_gets_no_injury_on_any_surface(): void {
        $this->assertTrue( ReaderSet::of( [ $this->hod ] )->seesMedical(), 'Precondition: the head of development reads injuries.' );
        $this->assertFalse( ReaderSet::of( [ $this->assistant ] )->seesMedical(), 'Precondition: the assistant coach does not.' );
        $uuid = $this->snapshot();

        foreach ( [ [ $this->assistant, false ], [ $this->hod, true ] ] as [ $reader, $sees ] ) {
            $this->flush();
            wp_set_current_user( $reader );

            $res = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/player-report-snapshots/' . $uuid ) );
            $this->assertSame( 200, $res->get_status() );
            $body = (string) wp_json_encode( $res->get_data() );
            $this->assertSame( $sees, str_contains( $body, self::INJURY ), 'REST: the injury' );
            $this->assertSame( $sees, str_contains( $body, self::MEDICAL ), 'REST: the medical journey entry' );
            $this->assertStringContainsString( self::PUBLIC, $body, 'REST: the public journey entry stays' );

            $pdf  = ( new PlayerReportPdfExporter() )->collect( new ExportRequest( 'player_report_pdf', 'pdf', $this->club, $reader, null, [ 'snapshot' => $uuid ] ) );
            $html = (string) ( $pdf['html'] ?? '' );
            $this->assertNotSame( '', $html );
            $this->assertSame( $sees, str_contains( $html, self::INJURY ), 'the PDF: the injury' );
            $this->assertSame( $sees, str_contains( $html, self::MEDICAL ), 'the PDF: the medical journey entry' );
        }
    }

    public function test_the_linked_scout_is_refused_the_copy_for_staff(): void {
        $this->assertSame( PlayerReportAudience::SCOUT, PlayerReportAudience::forReader( $this->scout ), 'Precondition: the scout reads as a scout.' );
        $uuid = $this->snapshot();

        $this->flush();
        wp_set_current_user( $this->scout );
        $this->assertNull( PlayerReportSnapshots::read( $uuid, $this->scout ) );
        $this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/player-report-snapshots/' . $uuid ) )->get_status() );
    }

    public function test_the_snapshot_list_leaves_out_copies_for_staff_for_a_scout(): void {
        $staff  = $this->snapshot();
        $family = PlayerReportSnapshots::share( $this->player, [ 'from' => self::FROM, 'to' => self::TO ], $this->hod );
        $this->assertNotSame( '', $family, 'Precondition: a copy shared with the family.' );
        $this->flush();
        $this->assertTrue( PlayerReportAccess::canRead( $this->scout, $this->player ), 'Precondition: the linked scout reads the player\'s reports.' );

        foreach ( [ 'head of development' => [ $this->hod, true ], 'observer' => [ $this->observer, true ], 'scout' => [ $this->scout, false ] ] as $who => [ $reader, $staff_listed ] ) {
            $this->flush();
            wp_set_current_user( $reader );
            if ( ! PlayerReportAccess::canRead( $reader, $this->player ) ) continue;
            $staff_listed = $staff_listed && PlayerReportAudience::forReader( $reader ) === PlayerReportAudience::INTERNAL;

            $uuids = array_column( PlayerReportSnapshots::listForStaffReader( $this->player, $reader ), 'uuid' );
            $this->assertSame( $staff_listed, in_array( $staff, $uuids, true ), "{$who}: the copy for staff in the list" );
            $this->assertContains( $family, $uuids, "{$who}: the family copy stays listed" );

            $res  = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/report-snapshots' ) );
            $this->assertSame( 200, $res->get_status(), $who );
            $rest = array_column( (array) ( $res->get_data()['data']['snapshots'] ?? [] ), 'uuid' );
            $this->assertSame( $staff_listed, in_array( $staff, $rest, true ), "{$who}: REST lists the copy for staff" );

            ob_start();
            \TT\Modules\Analytics\Frontend\PlayerReportSnapshotPage::renderTakeAndList( $this->player, [ 'from' => self::FROM, 'to' => self::TO, 'period' => '' ], 'A', [ 'status' ] );
            $html = (string) ob_get_clean();
            $this->assertSame( $staff_listed, str_contains( $html, $staff ), "{$who}: the page lists the copy for staff" );
            $this->assertStringContainsString( $family, $html, "{$who}: the page lists the family copy" );
        }
    }

    public function test_a_note_put_without_a_body_changes_nothing(): void {
        $uuid = $this->snapshot();
        wp_set_current_user( $this->hod );

        $put = new WP_REST_Request( 'PUT', '/talenttrack/v1/player-report-snapshots/' . $uuid . '/notes/status' );
        $put->set_param( 'body', self::NOTE );
        $this->assertSame( 200, rest_get_server()->dispatch( $put )->get_status() );

        $omitted = rest_get_server()->dispatch( new WP_REST_Request( 'PUT', '/talenttrack/v1/player-report-snapshots/' . $uuid . '/notes/status' ) );
        $this->assertSame( 400, $omitted->get_status() );
        $this->assertSame( 'missing_fields', (string) ( $omitted->get_data()['errors'][0]['code'] ?? '' ) );

        $notes = PlayerReportSnapshots::read( $uuid, $this->hod )['notes'] ?? [];
        $this->assertSame( self::NOTE, $notes['status']['body'] ?? null, 'the note is left alone' );
    }

    // ---- helpers ---------------------------------------------------------------------

    /** @return list<string> */
    private function sections(): array {
        return array_keys( PlayerSectionBlock::KEYS );
    }

    private function snapshot(): string {
        $this->flush();
        wp_set_current_user( $this->hod );
        $uuid = PlayerReportSnapshots::take( $this->player, [ 'from' => self::FROM, 'to' => self::TO, 'blocks' => implode( ',', $this->sections() ) ], $this->hod );
        $this->assertNotSame( '', $uuid );

        global $wpdb;
        $stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT data_json FROM {$wpdb->prefix}tt_player_report_snapshots WHERE uuid = %s", $uuid ) );
        $this->assertStringContainsString( self::INJURY, $stored, 'Precondition: the taker froze the injury.' );
        return $uuid;
    }

    private function json( mixed $payload ): string {
        return (string) wp_json_encode( $this->level( $payload ) );
    }

    private function level( mixed $payload ): mixed {
        if ( ! is_array( $payload ) ) return $payload;
        foreach ( $payload as $key => $value ) {
            $payload[ $key ] = $key === 'generated_at' || $key === 'as_of' ? '' : $this->level( $value );
        }
        return $payload;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    private function persona( string $role, string $role_type ): int {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Frozen', 'last_name' => 'Persona' . $uid,
            'role_type' => $role_type, 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        return $uid;
    }

    /** An assistant coach of one team, through the auth bridge: the person, the team seat and the scope. */
    private function assistantOf( int $team ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Assistant', 'last_name' => 'Frozen',
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

    /** March 2020 for the player: an injury, a public and a medical journey entry, attendance and one test reading. */
    private function seed(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_player_injuries", [
            'club_id' => $this->club, 'player_id' => $this->player, 'started_on' => '2020-03-02', 'actual_return' => '2020-03-09', 'notes' => self::INJURY,
        ] );
        foreach ( [ [ 'milestone', '2020-03-20 09:00:00', self::PUBLIC, 'public' ], [ 'injury_started', '2020-03-02 09:00:00', self::MEDICAL, 'medical' ] ] as $i => [ $type, $when, $summary, $visibility ] ) {
            $wpdb->insert( "{$p}tt_player_events", [
                'club_id' => $this->club, 'uuid' => wp_generate_uuid4(), 'player_id' => $this->player, 'event_type' => $type,
                'event_date' => $when, 'summary' => $summary, 'visibility' => $visibility,
                'source_module' => 'tests', 'source_entity_type' => 'test_event', 'source_entity_id' => $i + 1,
            ] );
        }

        foreach ( [ '2020-03-03', '2020-03-10' ] as $date ) {
            $wpdb->insert( "{$p}tt_activities", [
                'club_id' => $this->club, 'team_id' => $this->team, 'title' => 'Training ' . $date, 'session_date' => $date,
                'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
            ] );
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id' => $this->club, 'activity_id' => (int) $wpdb->insert_id, 'player_id' => $this->player,
                'status' => 'Present', 'record_type' => 'actual', 'is_guest' => 0,
            ] );
        }

        $wpdb->insert( "{$p}tt_measurement_definitions", [
            'club_id' => $this->club, 'category_id' => 0, 'name' => 'Frozen sprint', 'value_type' => 'numeric',
            'unit' => 's', 'direction' => 'lower', 'is_active' => 1, 'show_on_profile' => 1, 'visibility' => 'public',
        ] );
        $wpdb->insert( "{$p}tt_measurement_results", [
            'club_id' => $this->club, 'definition_id' => (int) $wpdb->insert_id, 'player_id' => $this->player,
            'recorded_date' => '2020-03-11', 'value_numeric' => 4.2,
        ] );
    }
}
