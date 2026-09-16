<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * #3451 — the other door onto #3456's data loss.
 *
 * `PUT /activities/{id}` with an `attendance` map called
 * `ActivitiesRepository::deleteRosterAttendance()`, which deleted every
 * non-guest row for the activity regardless of `record_type`, and then
 * rewrote them through `write_attendance()` as `actual`. So the REST
 * update path destroyed the coach's planned squad exactly the way the
 * wp-admin form did before #3456 — a coach fixing a kick-off time lost
 * Saturday's selection.
 *
 * Narrowing the delete on its own was not safe, which is why #3456 left
 * it: the caller snapshotted the line-up with
 * `lineupProjectionFor( id, null )` — widened precisely because the
 * delete was wide — and `lineupForActivity()` reads both kinds. Keep the
 * plan and re-apply its projection onto the new `actual` rows and every
 * starter is listed twice.
 *
 * Both halves are asserted here, because the duplication test is what
 * makes the narrowing safe to do at all.
 */
final class RestActivityUpdateKeepsPlanTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    /**
     * The bug, at the REST boundary: a coach plans a squad, somebody
     * records the register through `PUT /activities/{id}`, and the plan is
     * still there — same rows, same statuses, same notes, same line-up.
     */
    public function test_rest_update_with_attendance_leaves_the_plan_untouched(): void {
        $team     = $this->insertTeam();
        $keeper   = $this->insertPlayer( $team, 'Keeper' );
        $striker  = $this->insertPlayer( $team, 'Striker' );
        $activity = $this->insertActivity( $team );

        $planned_keeper  = $this->insertAttendance( $activity, $keeper, 'expected', 'Present', 'Plans to play', 'start' );
        $planned_striker = $this->insertAttendance( $activity, $striker, 'expected', 'Excused', 'Away with family', 'bench' );

        $res = $this->putActivity( $activity, $team, [
            $keeper  => [ 'status' => 'Present', 'notes' => '' ],
            $striker => [ 'status' => 'Absent',  'notes' => 'Still away' ],
        ] );
        $this->assertSame( 200, $res->get_status(), 'the update itself succeeds' );

        $planned = $this->rowsOfType( $activity, 'expected' );
        $this->assertCount( 2, $planned, 'the planned squad survives a REST update that records attendance' );

        $by_id = [];
        foreach ( $planned as $row ) {
            $by_id[ (int) $row->id ] = $row;
        }

        $this->assertArrayHasKey( $planned_keeper, $by_id, 'the planned row is the same row, not a rewritten one' );
        $this->assertArrayHasKey( $planned_striker, $by_id );

        $this->assertSame( 'Excused', (string) $by_id[ $planned_striker ]->status, 'the plan keeps its own status' );
        $this->assertSame( 'Away with family', (string) $by_id[ $planned_striker ]->notes, 'the plan keeps its own notes' );
        $this->assertSame( 'bench', (string) $by_id[ $planned_striker ]->lineup_role, 'the plan keeps its line-up' );
        $this->assertSame( 'start', (string) $by_id[ $planned_keeper ]->lineup_role );
    }

    /**
     * The converse: the register the request carried is written, as
     * `actual`, so the reports still see what happened.
     */
    public function test_rest_update_still_records_the_register(): void {
        $team     = $this->insertTeam();
        $player   = $this->insertPlayer( $team, 'Recorded' );
        $activity = $this->insertActivity( $team );

        $this->insertAttendance( $activity, $player, 'expected', 'Present', '' );

        $this->putActivity( $activity, $team, [
            $player => [ 'status' => 'Late', 'notes' => 'Bus' ],
        ] );

        $recorded = $this->rowsOfType( $activity, 'actual' );
        $this->assertCount( 1, $recorded, 'the register is recorded' );
        $this->assertSame( 'Late', (string) $recorded[0]->status );
        $this->assertSame( 'Bus', (string) $recorded[0]->notes );
    }

    /**
     * A second REST update replaces the register rather than appending to
     * it — the narrowed delete still does its job.
     */
    public function test_a_second_rest_update_replaces_the_register(): void {
        $team     = $this->insertTeam();
        $player   = $this->insertPlayer( $team, 'Twice' );
        $activity = $this->insertActivity( $team );

        $this->putActivity( $activity, $team, [ $player => [ 'status' => 'Absent', 'notes' => '' ] ] );
        $this->putActivity( $activity, $team, [ $player => [ 'status' => 'Present', 'notes' => '' ] ] );

        $recorded = $this->rowsOfType( $activity, 'actual' );
        $this->assertCount( 1, $recorded, 'the recorded register is replaced, not appended to' );
        $this->assertSame( 'Present', (string) $recorded[0]->status );
    }

    /**
     * The criterion that makes the two-file fix safe: with the plan now
     * surviving, a player carrying both an `expected` and an `actual` row
     * appears in the Starting XI exactly once.
     */
    public function test_starting_xi_lists_each_starter_once(): void {
        $team     = $this->insertTeam();
        $player   = $this->insertPlayer( $team, 'Both' );
        $activity = $this->insertActivity( $team );

        $this->insertAttendance( $activity, $player, 'expected', 'Present', '', 'start' );
        $this->insertAttendance( $activity, $player, 'actual', 'Present', '', 'start' );

        $lineup = ( new ActivitiesRepository() )->lineupForActivity( $activity );

        $this->assertCount( 1, $lineup->starting, 'a starter with both kinds of row is listed once' );
        $this->assertSame( $player, (int) $lineup->starting[0]->player_id );
        $this->assertCount( 0, $lineup->bench );
    }

    /**
     * ...and the de-duplication prefers the plan, which is where match
     * prep writes the projection. A stale `actual` row saying "bench" must
     * not demote a player the coach has since named as a starter.
     */
    public function test_the_plan_wins_when_both_kinds_carry_a_role(): void {
        $team     = $this->insertTeam();
        $player   = $this->insertPlayer( $team, 'Promoted' );
        $activity = $this->insertActivity( $team );

        $this->insertAttendance( $activity, $player, 'actual', 'Present', '', 'bench' );
        $this->insertAttendance( $activity, $player, 'expected', 'Present', '', 'start' );

        $lineup = ( new ActivitiesRepository() )->lineupForActivity( $activity );

        $this->assertCount( 1, $lineup->starting );
        $this->assertCount( 0, $lineup->bench, 'the stale recorded row does not add a second entry' );
    }

    /**
     * An install that ran the old save already has the projection on an
     * `actual` row with no plan behind it. Scoping the reader to
     * `expected` would have emptied its Line-up card.
     */
    public function test_a_lineup_that_only_exists_on_a_recorded_row_is_still_read(): void {
        $team     = $this->insertTeam();
        $player   = $this->insertPlayer( $team, 'Legacy' );
        $activity = $this->insertActivity( $team );

        $this->insertAttendance( $activity, $player, 'actual', 'Present', '', 'start' );

        $lineup = ( new ActivitiesRepository() )->lineupForActivity( $activity );

        $this->assertCount( 1, $lineup->starting, 'a pre-#3451 install keeps its Line-up card' );
    }

    /**
     * The repository half on its own: the delete spares the plan and still
     * clears the register.
     */
    public function test_delete_roster_attendance_clears_only_the_register(): void {
        $team     = $this->insertTeam();
        $player   = $this->insertPlayer( $team, 'Scoped' );
        $activity = $this->insertActivity( $team );

        $planned = $this->insertAttendance( $activity, $player, 'expected', 'Present', '' );
        $this->insertAttendance( $activity, $player, 'actual', 'Absent', '' );

        ( new ActivitiesRepository() )->deleteRosterAttendance( $activity );

        $this->assertCount( 0, $this->rowsOfType( $activity, 'actual' ), 'the register goes' );
        $remaining = $this->rowsOfType( $activity, 'expected' );
        $this->assertCount( 1, $remaining, 'the plan stays' );
        $this->assertSame( $planned, (int) $remaining[0]->id );
    }

    /**
     * #0026's contract, pinned because this narrows the same delete.
     */
    public function test_guest_rows_survive_a_rest_update(): void {
        $team     = $this->insertTeam();
        $player   = $this->insertPlayer( $team, 'Home' );
        $activity = $this->insertActivity( $team );

        $this->insertAttendance( $activity, 0, 'actual', 'Present', '', null, 1 );

        $this->putActivity( $activity, $team, [ $player => [ 'status' => 'Present', 'notes' => '' ] ] );

        global $wpdb;
        $guests = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_attendance WHERE activity_id = %d AND club_id = %d AND is_guest = 1",
            $activity, $this->club
        ) );
        $this->assertSame( 1, $guests, 'a guest visit is not this endpoint\'s to delete' );
    }

    /**
     * @param array<int, array{status:string, notes:string}> $attendance
     */
    private function putActivity( int $activity_id, int $team_id, array $attendance ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $activity_id );
        $req->set_param( 'id', $activity_id );
        $req->set_param( 'team_id', $team_id );
        $req->set_param( 'title', 'Thuis tegen Hedel' );
        $req->set_param( 'session_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
        $req->set_param( 'activity_type_key', 'game' );
        $req->set_param( 'attendance', $attendance );

        return rest_do_request( $req );
    }

    /**
     * @return array<int, object>
     */
    private function rowsOfType( int $activity_id, string $record_type ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_attendance
              WHERE activity_id = %d AND club_id = %d AND is_guest = 0 AND record_type = %s
              ORDER BY id ASC",
            $activity_id, $this->club, $record_type
        ) );
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO14-1' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Luuk',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Thuis tegen Hedel',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
            'activity_type_key'   => 'game',
            'plan_state'          => 'scheduled',
            'activity_status_key' => 'planned',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance(
        int $activity_id,
        int $player_id,
        string $record_type,
        string $status,
        string $notes = '',
        ?string $lineup_role = null,
        int $is_guest = 0
    ): int {
        global $wpdb;
        $row = [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'notes'       => $notes,
            'is_guest'    => $is_guest,
            'record_type' => $record_type,
        ];
        if ( $lineup_role !== null ) {
            $row['lineup_role'] = $lineup_role;
        }
        if ( $is_guest === 1 ) {
            $row['guest_name'] = 'Bezoeker';
        }
        $wpdb->insert( "{$this->p}tt_attendance", $row );
        return (int) $wpdb->insert_id;
    }
}
