<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Reports\AttendanceGridQuery;

/**
 * #4009 — last season's register survives the age-group conveyor.
 *
 * The grid built its rows from the team's CURRENT active roster and its
 * cells from every recorded mark in the window. Those are two different
 * player sets once a squad has moved up, so every row rendered empty and
 * the marks were unreachable — keys in `cells` that no row could ever
 * display. The activities list had the mirror image: its counts narrowed to
 * players on the activity's team *today*, so one row could read
 * `register.attendance: recorded 20 / 20, complete` next to
 * `attendance_count 0` and `attendance_pct 0`.
 *
 * Former-squad rows are read-only by decision: the bulk write refuses a
 * mark for a player off the activity's team and counts it as `skipped`
 * without saying so, so the payload marks them rather than offering an
 * edit that quietly does nothing.
 */
final class AttendanceFormerSquadTest extends WP_UnitTestCase {

    private int $club     = 0;
    private int $team     = 0;
    private int $upTeam   = 0;
    private int $activity = 0;

    /** @var list<int> players still on the team */
    private array $current = [];

    /** @var list<int> players who have since moved up */
    private array $moved = [];

    private const FROM = '2026-06-01';
    private const TO   = '2026-06-05';
    private const DATE = '2026-06-03';

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO7-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO12-1' ] );
        $this->upTeam = (int) $wpdb->insert_id;

        // Two players on the team as it stands now.
        foreach ( [ 'Aaronson', 'Bakker' ] as $i => $last ) {
            $this->current[] = $this->player( $last, $this->team, $i + 2 );
        }
        // Two who played last season's sessions and have since moved up.
        foreach ( [ 'Cruijff', 'Dekker' ] as $i => $last ) {
            $this->moved[] = $this->player( $last, $this->upTeam, $i + 8 );
        }

        $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Training',
            'session_date'        => self::DATE,
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        // The register is the squad that was there: only the movers.
        $this->attendance( $this->moved[0], 'Present' );
        $this->attendance( $this->moved[1], 'Absent' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- the grid --------------------------------------------------

    public function test_every_cell_belongs_to_a_row(): void {
        $matrix = $this->matrix();

        $row_ids = array_map( static fn( array $r ): int => (int) $r['player_id'], $matrix['players'] );
        $this->assertNotSame( [], $matrix['cells'], 'the window has recorded marks' );
        foreach ( array_keys( $matrix['cells'] ) as $pid ) {
            $this->assertContains( (int) $pid, $row_ids, 'a recorded mark with no row is unreachable' );
        }
    }

    public function test_a_player_who_moved_up_is_still_a_row(): void {
        $rows = $this->rowsByPlayer();

        foreach ( $this->moved as $pid ) {
            $this->assertArrayHasKey( $pid, $rows, 'the squad that played the session is listed' );
            $this->assertFalse( $rows[ $pid ]['on_current_roster'] );
            $this->assertFalse( $rows[ $pid ]['editable'], 'the write guard refuses these, so the row says so' );
            $this->assertSame( $this->upTeam, (int) $rows[ $pid ]['current_team_id'] );
            $this->assertSame( 'JO12-1', (string) $rows[ $pid ]['current_team_name'], 'the row says where they went' );
        }
    }

    public function test_the_current_roster_is_unchanged_and_editable(): void {
        $rows = $this->rowsByPlayer();

        foreach ( $this->current as $pid ) {
            $this->assertArrayHasKey( $pid, $rows );
            $this->assertTrue( $rows[ $pid ]['on_current_roster'] );
            $this->assertTrue( $rows[ $pid ]['editable'] );
        }
        $this->assertSame( 2, (int) $this->matrix()['summary']['former_squad_players'] );
        $this->assertSame( 4, (int) $this->matrix()['summary']['total_players'] );
    }

    public function test_the_recorded_marks_land_in_the_cells(): void {
        $cells = $this->matrix()['cells'];

        $this->assertSame( 'present', $cells[ $this->moved[0] ][ $this->activity ] ?? '' );
        $this->assertSame( 'absent', $cells[ $this->moved[1] ][ $this->activity ] ?? '' );
    }

    /** Roster-only rows still show for a window whose activity has no register. */
    public function test_a_window_with_nothing_recorded_is_roster_only(): void {
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}tt_attendance", [ 'activity_id' => $this->activity ] );

        $matrix = $this->matrix();

        $this->assertSame( 2, count( $matrix['players'] ) );
        $this->assertSame( 0, (int) $matrix['summary']['former_squad_players'] );
        $this->assertSame( [], $matrix['cells'] );
        foreach ( $matrix['players'] as $row ) {
            $this->assertTrue( $row['editable'] );
        }
    }

    // ---- the list --------------------------------------------------

    public function test_the_list_counts_the_recorded_register(): void {
        $row = $this->listRow();

        $this->assertSame( 2, (int) $row['attendance_count'], 'two marks were recorded on this activity' );
        $this->assertSame( 1, (int) $row['present_count'] );
        $this->assertSame( 50, (int) $row['attendance_pct'], 'one of the two recorded players was present' );
    }

    public function test_the_attendance_filter_buckets_agree_with_the_counts(): void {
        $this->assertContains( $this->activity, $this->listIds( 'complete' ) );
        $this->assertNotContains( $this->activity, $this->listIds( 'none' ) );
    }

    // ---- fixtures --------------------------------------------------

    private function player( string $last, int $team_id, int $jersey ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'first_name'    => 'Speler',
            'last_name'     => $last,
            'jersey_number' => $jersey,
            'status'        => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attendance( int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $this->activity,
            'player_id'   => $player_id,
            'is_guest'    => 0,
            'status'      => $status,
            'record_type' => 'actual',
        ] );
    }

    /** @return array<string, mixed> */
    private function matrix(): array {
        return ( new AttendanceGridQuery() )->matrix(
            $this->team,
            self::FROM,
            self::TO,
            'all',
            '2026-09-01'
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsByPlayer(): array {
        $out = [];
        foreach ( $this->matrix()['players'] as $row ) {
            $out[ (int) $row['player_id'] ] = (array) $row;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function listRow(): array {
        foreach ( $this->listRows( [] ) as $row ) {
            if ( (int) ( $row['id'] ?? 0 ) === $this->activity ) return (array) $row;
        }
        $this->fail( 'The activity is missing from the list.' );
    }

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    private function listRows( array $filter ): array {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( [
            'filter'   => [ 'team_id' => $this->team ] + $filter,
            'per_page' => 100,
        ] );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );

        return array_values( (array) ( $res->get_data()['data']['rows'] ?? [] ) );
    }

    /** @return list<int> */
    private function listIds( string $bucket ): array {
        return array_map(
            static fn( array $row ): int => (int) ( $row['id'] ?? 0 ),
            $this->listRows( [ 'attendance' => $bucket ] )
        );
    }
}
