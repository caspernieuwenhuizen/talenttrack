<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\PlayerReportSnapshots;
use TT\Modules\DemoData\DemoCoverage;
use TT\Modules\DemoData\Generators\TeamReportSnapshotGenerator;

/**
 * #3890 (epic #3871) — frozen player reports.
 *
 * Pinned: a snapshot stores the composed report and the dates it covered, so
 * data changing afterwards does not change it; notes are per section and
 * refuse a section that is not a block; every operation answers to the
 * snapshot's own player, so a reader without access can neither read, note nor
 * take one; the REST routes follow the same rule and do not disclose whether an
 * unknown uuid exists; and the demo academy seeds one, wiped by its cascade.
 *
 * The caller is a `tt_club_admin`; see `PlayerReportTest` for why.
 */
final class PlayerReportSnapshotTest extends WP_UnitTestCase {

    private int $admin  = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->admin = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        wp_set_current_user( $this->admin );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Snap U16' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $team,
            'first_name' => 'Frozen', 'last_name' => 'Player', 'status' => 'active', 'wp_user_id' => null,
        ] );
        $this->player = (int) $wpdb->insert_id;

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_snapshot_stores_the_report_and_the_dates_it_covered(): void {
        $uuid = PlayerReportSnapshots::take( $this->player, [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'blocks' => 'attendance' ], $this->admin );
        $this->assertNotSame( '', $uuid );

        $snap = PlayerReportSnapshots::read( $uuid, $this->admin );
        $this->assertNotNull( $snap );
        $this->assertSame( [ 'letterhead', 'attendance' ], $snap['report']['blocks'] );
        $this->assertSame( '2020-03-01', $snap['composition']['from'] );
        $this->assertSame( '', $snap['composition']['period'], 'a snapshot names its dates rather than a period that moves' );
    }

    public function test_later_data_does_not_change_the_snapshot(): void {
        $uuid = PlayerReportSnapshots::take( $this->player, [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'blocks' => 'attendance' ], $this->admin );
        $this->assertSame( 0, (int) ( PlayerReportSnapshots::read( $uuid, $this->admin )['report']['data']['attendance']['activities'] ?? -1 ) );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => CurrentClub::id(), 'team_id' => 0, 'title' => 'Late entry', 'session_date' => '2020-03-10',
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => CurrentClub::id(), 'activity_id' => (int) $wpdb->insert_id, 'player_id' => $this->player,
            'status' => 'Present', 'record_type' => 'actual', 'is_guest' => 0,
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'the fixture wrote' );

        $this->assertSame( 0, (int) ( PlayerReportSnapshots::read( $uuid, $this->admin )['report']['data']['attendance']['activities'] ?? -1 ), 'the frozen number does not move' );
    }

    public function test_notes_are_per_section_and_refuse_a_section_that_is_not_a_block(): void {
        $uuid = PlayerReportSnapshots::take( $this->player, [], $this->admin );

        $this->assertTrue( PlayerReportSnapshots::note( $uuid, 'talking_points', 'Agreed on two extra sessions.', $this->admin ) );
        $this->assertFalse( PlayerReportSnapshots::note( $uuid, 'not_a_block', 'x', $this->admin ) );

        $notes = PlayerReportSnapshots::read( $uuid, $this->admin )['notes'] ?? [];
        $this->assertSame( [ 'talking_points' ], array_keys( $notes ) );
        $this->assertSame( $this->admin, $notes['talking_points']['author'] );

        $this->assertTrue( PlayerReportSnapshots::note( $uuid, 'talking_points', '', $this->admin ) );
        $this->assertSame( [], PlayerReportSnapshots::read( $uuid, $this->admin )['notes'] ?? null, 'clearing a note removes it' );
    }

    public function test_a_reader_without_the_player_can_neither_read_note_nor_take(): void {
        $uuid     = PlayerReportSnapshots::take( $this->player, [], $this->admin );
        $outsider = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertNotNull( PlayerReportSnapshots::read( $uuid, $this->admin ), 'precondition: staff can read it' );
        $this->assertNull( PlayerReportSnapshots::read( $uuid, $outsider ) );
        $this->assertFalse( PlayerReportSnapshots::note( $uuid, 'status', 'x', $outsider ) );
        $this->assertSame( '', PlayerReportSnapshots::take( $this->player, [], $outsider ) );
    }

    public function test_the_rest_routes_follow_the_same_rule(): void {
        $create = new WP_REST_Request( 'POST', '/talenttrack/v1/players/' . $this->player . '/report-snapshots' );
        $create->set_param( 'blocks', 'status' );
        $response = rest_get_server()->dispatch( $create );
        $this->assertSame( 201, $response->get_status() );
        $uuid = (string) ( $response->get_data()['data']['uuid'] ?? '' );
        $this->assertNotSame( '', $uuid );

        $list = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/report-snapshots' ) );
        $this->assertSame( 200, $list->get_status() );
        $this->assertCount( 1, $list->get_data()['data']['snapshots'] );

        $note = new WP_REST_Request( 'PUT', '/talenttrack/v1/player-report-snapshots/' . $uuid . '/notes/status' );
        $note->set_param( 'body', 'Discussed.' );
        $this->assertSame( 200, rest_get_server()->dispatch( $note )->get_status() );

        $this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/player-report-snapshots/00000000-0000-4000-8000-000000000000' ) )->get_status() );

        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/player-report-snapshots/' . $uuid ) )->get_status() );
    }

    public function test_the_demo_academy_seeds_one_and_can_wipe_it(): void {
        $entry = DemoCoverage::MANIFEST['tt_player_report_snapshots'] ?? [];
        $this->assertSame( TeamReportSnapshotGenerator::class, $entry['written_by'] ?? '' );
        $this->assertSame( 'player_report_snapshot', $entry['entity_type'] ?? '' );

        $cascade = DemoCoverage::CATEGORIES[ $entry['category'] ?? '' ]['cascade'] ?? [];
        $this->assertContains( 'player_report_snapshot', $cascade, 'a generated table no cascade removes survives a demo wipe' );
    }
}
