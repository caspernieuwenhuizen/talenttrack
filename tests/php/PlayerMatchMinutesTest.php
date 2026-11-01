<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Reports\MinutesGridQuery;
use TT\Modules\Activities\Repositories\AttendanceWriter;
use TT\Modules\Activities\Repositories\PlayerMatchMinutes;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;
use TT\Modules\Analytics\Reports\MinutesQuery;

/**
 * #4526 — one player, one match, one minutes figure, on every screen.
 *
 * Nothing in the schema stops two recorded rows for the same player and
 * match. The grid took the MAX of them and the audit and the reports the
 * SUM, so two rows of 70 read 70 beside 140. The newest recorded row wins
 * now, through `PlayerMatchMinutes`, and the writer updates the existing row
 * rather than adding a second.
 */
final class PlayerMatchMinutesTest extends WP_UnitTestCase {

    private const FROM = '2026-10-01';
    private const TO   = '2026-10-31';

    private string $p = '';
    private int $club = 0;
    private int $team = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Hedel O14-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $this->team,
            'first_name'    => 'Morris',
            'last_name'     => 'Speler',
            'jersey_number' => 9,
            'status'        => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the read ───────────────────────────────────────────────────────

    public function test_a_duplicate_row_reads_the_newest_on_the_grid_the_audit_and_the_report(): void {
        $match = $this->insertMatch( '2026-10-11' );
        $this->rawActual( $match, 70 );
        $this->rawActual( $match, 60 );

        $grid = ( new MinutesGridQuery() )->matrix( $this->team, self::FROM, self::TO );
        $this->assertSame( 60, (int) $grid['cells'][ $this->player ][ $match ]['minutes'], 'grid: not the MAX' );

        $audit = $this->auditRow( $match );
        $this->assertSame( 60, (int) $audit['minutes'][ $this->player ], 'audit: not the SUM' );
        $this->assertSame( 60, (int) $audit['total_minutes'] );

        $this->assertSame( 60, $this->reportTotal(), 'minutes report: not the SUM' );
        $this->assertSame( 60, ( new MinutesQuery() )->seasonTotalsForPlayer( $this->player, self::FROM, self::TO )['minutes'] );
    }

    public function test_a_correction_to_zero_on_the_newest_row_counts_as_zero(): void {
        $match = $this->insertMatch( '2026-10-12' );
        $this->rawActual( $match, 70 );
        $this->rawActual( $match, 0 );

        $this->assertSame( [ $match => [ $this->player => 0 ] ], PlayerMatchMinutes::forActivities( [ $match ] ) );
        $this->assertSame( 0, $this->reportTotal(), 'MAX would have kept the 70' );
    }

    /**
     * Hidde's case: a status-only register row written after match execution
     * recorded the minutes. The newer row carries no figure, so the older
     * figure stands: the newest row *with a figure* wins.
     */
    public function test_a_newer_row_without_a_figure_leaves_the_older_figure_standing(): void {
        $match = $this->insertMatch( '2026-10-19' );
        $this->rawActual( $match, 70 );
        $this->rawActual( $match, null );

        $this->assertSame( [ $match => [ $this->player => 70 ] ], PlayerMatchMinutes::forActivities( [ $match ] ) );

        $grid = ( new MinutesGridQuery() )->matrix( $this->team, self::FROM, self::TO );
        $this->assertSame( 70, (int) $grid['cells'][ $this->player ][ $match ]['minutes'], 'grid' );
        $this->assertSame( 70, (int) $this->auditRow( $match )['minutes'][ $this->player ], 'audit' );
        $this->assertSame( 70, $this->reportTotal(), 'minutes report' );
    }

    public function test_with_no_figure_anywhere_the_answer_is_null(): void {
        $match = $this->insertMatch( '2026-10-20' );
        $this->rawActual( $match, null );
        $this->rawActual( $match, null );

        $this->assertSame( [ $match => [ $this->player => null ] ], PlayerMatchMinutes::forActivities( [ $match ] ) );
    }

    /**
     * A coach clearing the box on a legacy duplicate: the minutes write drops
     * the older rows, so the clear is what every screen reads rather than the
     * older row's figure.
     */
    public function test_clearing_minutes_on_a_duplicate_drops_the_older_rows(): void {
        $match = $this->insertMatch( '2026-10-21' );
        $this->rawActual( $match, 70 );
        $this->rawActual( $match, 60 );

        $this->assertTrue( ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )->updateActualAttendanceMinutes( $match, $this->player, null ) );

        $this->assertSame( 1, $this->actualRows( $match ) );
        $this->assertSame( [ $match => [ $this->player => null ] ], PlayerMatchMinutes::forActivities( [ $match ] ) );
        $this->assertSame( 0, $this->reportTotal() );
    }

    public function test_the_minutes_share_report_reads_the_newest_figure(): void {
        $match = $this->insertMatch( '2026-10-22' );
        $this->rawActual( $match, 70 );
        $this->rawActual( $match, 60 );

        $share = ( new \TT\Modules\Analytics\Reports\MinutesShareQuery() )->forPlayer( $this->team, $this->player, self::FROM, self::TO );

        $this->assertNotNull( $share );
        $this->assertSame( 60, $share['minutes'], 'share report: not the SUM' );
    }

    public function test_the_audit_counts_duplicate_pairs_for_an_admin(): void {
        $match = $this->insertMatch( '2026-10-13' );
        $this->rawActual( $match, 70 );
        $this->rawActual( $match, 60 );
        $clean = $this->insertMatch( '2026-10-14' );
        $this->rawActual( $clean, 70 );

        $summary = ( new MinutesAuditQuery() )->matrix( $this->team, self::FROM, self::TO )['summary'];

        $this->assertSame( 1, $summary['duplicate_pairs'] );
        $this->assertSame( 1, PlayerMatchMinutes::duplicatePairs( [ $match, $clean ] ) );
    }

    // ── the writers ────────────────────────────────────────────────────

    public function test_recording_twice_through_the_writer_leaves_one_row_with_the_newest_figure(): void {
        $match  = $this->insertMatch( '2026-10-15' );
        $writer = new AttendanceWriter();

        $first  = $writer->recordActual( [ 'activity_id' => $match, 'player_id' => $this->player, 'status' => 'present', 'minutes_played' => 70 ] );
        $second = $writer->recordActual( [ 'activity_id' => $match, 'player_id' => $this->player, 'status' => 'present', 'minutes_played' => 60 ] );

        $this->assertNotNull( $first );
        $this->assertSame( $first, $second, 'the existing row is updated' );
        $this->assertSame( 1, $this->actualRows( $match ) );
        $this->assertSame( 60, $this->reportTotal() );
    }

    public function test_a_guest_visit_is_still_its_own_row(): void {
        $match  = $this->insertMatch( '2026-10-16' );
        $writer = new AttendanceWriter();

        $writer->recordActual( [ 'activity_id' => $match, 'player_id' => 0, 'is_guest' => 1, 'guest_name' => 'Gast A', 'status' => 'present' ] );
        $writer->recordActual( [ 'activity_id' => $match, 'player_id' => 0, 'is_guest' => 1, 'guest_name' => 'Gast B', 'status' => 'present' ] );

        global $wpdb;
        $guests = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_attendance WHERE activity_id = %d AND is_guest = 1 AND record_type = 'actual'",
            $match
        ) );
        $this->assertSame( 2, $guests );
    }

    public function test_saving_minutes_twice_through_the_minutes_grid_leaves_one_row(): void {
        $match = $this->insertMatch( '2026-10-17' );
        $this->rawActual( $match, 0 );

        foreach ( [ 70, 60 ] as $minutes ) {
            $res = $this->post( '/talenttrack/v1/minutes/bulk', [ 'changes' => [
                [ 'activity_id' => $match, 'player_id' => $this->player, 'minutes' => $minutes ],
            ] ] );
            $this->assertSame( 200, $res->get_status() );
        }

        $this->assertSame( 1, $this->actualRows( $match ) );
        $this->assertSame( 60, $this->reportTotal() );
    }

    public function test_saving_attendance_twice_through_the_attendance_grid_leaves_one_row(): void {
        $match = $this->insertMatch( '2026-10-18' );

        foreach ( [ 'present', 'absent' ] as $status ) {
            $res = $this->post( '/talenttrack/v1/attendance/bulk', [ 'changes' => [
                [ 'activity_id' => $match, 'player_id' => $this->player, 'status' => $status ],
            ] ] );
            $this->assertSame( 200, $res->get_status() );
        }

        $this->assertSame( 1, $this->actualRows( $match ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function post( string $route, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', $route );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    private function insertMatch( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'              => $this->club,
            'team_id'              => $this->team,
            'title'                => 'Baronie O14-1',
            'session_date'         => $date,
            'activity_type_key'    => 'match',
            'game_subtype_key'     => 'League',
            'activity_status_key'  => 'completed',
            'plan_state'           => 'completed',
            'match_length_minutes' => 70,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A recorded row written straight to the table, as the old duplicates were. */
    private function rawActual( int $activity_id, ?int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $this->player,
            'status'         => 'present',
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => $minutes,
        ] );
    }

    private function actualRows( int $activity_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_attendance
              WHERE activity_id = %d AND player_id = %d AND is_guest = 0 AND record_type = 'actual'",
            $activity_id, $this->player
        ) );
    }

    private function reportTotal(): int {
        foreach ( ( new MinutesQuery() )->forTeam( $this->team, self::FROM, self::TO ) as $row ) {
            if ( (int) $row['player_id'] === $this->player ) return (int) $row['total_minutes'];
        }
        return 0;
    }

    /** @return array<string, mixed> */
    private function auditRow( int $activity_id ): array {
        foreach ( ( new MinutesAuditQuery() )->matrix( $this->team, self::FROM, self::TO )['games'] as $g ) {
            if ( (int) $g['activity_id'] === $activity_id ) return $g;
        }
        $this->fail( 'the audit lists the match' );
    }
}
