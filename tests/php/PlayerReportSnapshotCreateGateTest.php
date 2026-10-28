<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Frontend\PlayerReportSnapshotPage;
use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\PlayerReportSnapshots;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * Taking a snapshot, sharing one with the family and writing a note on one
 * make records, so they need `reports` create_delete, not only the read that
 * opens the report. The read-only observer and a linked scout read the report
 * and its snapshots, and are refused all three. Take and note are audited.
 */
final class PlayerReportSnapshotCreateGateTest extends WP_UnitTestCase {

    private int $admin  = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->admin = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Gate U15' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $team,
            'first_name' => 'Gated', 'last_name' => 'Player', 'status' => 'active', 'wp_user_id' => null,
        ] );
        $this->player = (int) $wpdb->insert_id;

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @return array<string, array{0:string}> */
    public function readOnlySeats(): array {
        return [
            'read-only observer' => [ 'observer' ],
            'linked scout'       => [ 'scout' ],
        ];
    }

    /** @dataProvider readOnlySeats */
    public function test_a_read_only_seat_reads_but_cannot_take_share_or_note( string $seat ): void {
        $reader = $this->seat( $seat );
        $this->assertTrue( PlayerReportAccess::canRead( $reader, $this->player ), "precondition: the {$seat} reads the report" );
        $this->assertFalse( PlayerReportAccess::canCreate( $reader, $this->player ) );

        $uuid = PlayerReportSnapshots::take( $this->player, [], $this->admin );
        $this->assertNotSame( '', $uuid, 'precondition: an admin takes one' );
        $this->assertNotNull( PlayerReportSnapshots::read( $uuid, $reader ), "the {$seat} still reads a snapshot" );

        $this->assertSame( '', PlayerReportSnapshots::take( $this->player, [], $reader ) );
        $this->assertSame( '', PlayerReportSnapshots::share( $this->player, [], $reader ) );
        $this->assertFalse( PlayerReportSnapshots::canNote( $uuid, $reader ) );
        $this->assertFalse( PlayerReportSnapshots::note( $uuid, 'status', 'x', $reader ) );

        wp_set_current_user( $reader );
        $this->assertSame( 403, $this->dispatch( 'POST', '/talenttrack/v1/players/' . $this->player . '/report-snapshots' ) );
        $this->assertSame( 403, $this->dispatch( 'POST', '/talenttrack/v1/players/' . $this->player . '/report-snapshots', [ 'audience' => 'family' ] ) );
        $this->assertSame( 403, $this->dispatch( 'PUT', '/talenttrack/v1/player-report-snapshots/' . $uuid . '/notes/status', [ 'body' => 'x' ] ) );
        $this->assertSame( 200, $this->dispatch( 'GET', '/talenttrack/v1/player-report-snapshots/' . $uuid ) );

        $html = $this->renderTakeAndList();
        $this->assertStringNotContainsString( 'tt-mr-snapshots__take', $html );
        $this->assertStringNotContainsString( 'tt-pr-share', $html );
        $this->assertStringContainsString( 'tt-mr-snapshots__list', $html, 'the earlier snapshots stay listed' );
    }

    public function test_a_generating_seat_takes_and_notes_and_both_are_audited(): void {
        $this->assertTrue( PlayerReportAccess::canCreate( $this->admin, $this->player ) );

        $taken_before = $this->auditRows( 'player_report.snapshot_taken' );
        $uuid = PlayerReportSnapshots::take( $this->player, [], $this->admin );
        $this->assertNotSame( '', $uuid );
        $this->assertSame( $taken_before + 1, $this->auditRows( 'player_report.snapshot_taken' ) );

        $noted_before = $this->auditRows( 'player_report.snapshot_noted' );
        $this->assertTrue( PlayerReportSnapshots::note( $uuid, 'status', 'Discussed.', $this->admin ) );
        $this->assertSame( $noted_before + 1, $this->auditRows( 'player_report.snapshot_noted' ) );

        wp_set_current_user( $this->admin );
        $html = $this->renderTakeAndList();
        $this->assertStringContainsString( 'tt-mr-snapshots__take', $html );
        $this->assertStringContainsString( 'tt-pr-share', $html );
    }

    private function seat( string $seat ): int {
        if ( $seat === 'observer' ) {
            $uid = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        } else {
            $uid = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
            update_user_meta( $uid, 'tt_scout_player_ids', wp_json_encode( [ $this->player ] ) );
        }
        AuthorizationService::flushCache();
        return $uid;
    }

    /** @param array<string, mixed> $params */
    private function dispatch( string $method, string $route, array $params = [] ): int {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return rest_get_server()->dispatch( $request )->get_status();
    }

    private function renderTakeAndList(): string {
        ob_start();
        PlayerReportSnapshotPage::renderTakeAndList(
            $this->player,
            [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'period' => '' ],
            'A',
            [ 'status' ]
        );
        return (string) ob_get_clean();
    }

    private function auditRows( string $action ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = %s AND entity_type = 'player' AND entity_id = %d",
            $action,
            $this->player
        ) );
    }
}
