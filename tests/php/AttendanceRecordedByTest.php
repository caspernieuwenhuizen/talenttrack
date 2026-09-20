<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\AttendanceWriter;
use TT\Modules\Activities\Services\ActivityRegisterProgress;

/**
 * #3655 — who saved the register, and when.
 *
 * A head coach opened a completed trial training carrying one attendance
 * mark neither they nor their assistant had entered, and neither the page
 * nor the API could say where it came from. Attendance feeds minutes,
 * exposure and evaluation eligibility, so a mark a coach cannot trace is a
 * mark they can neither trust nor correct.
 *
 * The stamp is per **save**, not per mark: saving a register deletes and
 * re-inserts its recorded rows, so every row of one save carries the same
 * author and time. Everything below asserts that reading, and the two
 * places it must NOT reach — the planned squad, and an update that is not
 * somebody taking a register.
 */
final class AttendanceRecordedByTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 0;
    private int $team = 0;
    private int $player = 0;
    private int $activity = 0;
    private AttendanceWriter $writer;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $this->p      = $wpdb->prefix;
        $this->club   = (int) CurrentClub::id();
        $this->writer = new AttendanceWriter();
        ActivityRegisterProgress::forget();

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O11-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Bas',
            'last_name'  => 'Willems',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->activity = $this->insertActivity();
    }

    public function tear_down(): void {
        ActivityRegisterProgress::forget();
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /* ---- the writer stamps, and nothing else does ------------------ */

    public function test_a_recorded_row_carries_the_author_and_the_time(): void {
        $author = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $author );

        $id = (int) $this->writer->recordActual( [
            'activity_id' => $this->activity,
            'player_id'   => $this->player,
            'status'      => 'Present',
        ] );

        $this->assertSame( $author, (int) $this->cell( $id, 'recorded_by' ) );
        $this->assertNotSame( '', (string) $this->cell( $id, 'recorded_at' ) );
    }

    /**
     * A squad somebody selected is not a register somebody took, and the
     * line the coach reads says "saved". Stamping the plan would put an
     * author on a register nobody has taken yet — the exact confusion
     * `record_type` exists to prevent.
     */
    public function test_a_planned_row_is_never_stamped(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $id = (int) $this->writer->planExpected( [
            'activity_id' => $this->activity,
            'player_id'   => $this->player,
            'status'      => 'Present',
        ] );

        $this->assertNull( $this->cell( $id, 'recorded_by' ) );
        $this->assertNull( $this->cell( $id, 'recorded_at' ) );
    }

    /**
     * A job with nobody behind it (WP-CLI, cron, an import run by the
     * scheduler) leaves the author blank rather than writing user 0, which
     * is an id nobody has and would render as an author who does not
     * exist.
     */
    public function test_a_write_with_no_current_user_leaves_the_author_blank(): void {
        wp_set_current_user( 0 );

        $id = (int) $this->writer->recordActual( [
            'activity_id' => $this->activity,
            'player_id'   => $this->player,
            'status'      => 'Present',
        ] );

        $this->assertNull( $this->cell( $id, 'recorded_by' ) );
        $this->assertNotSame( '', (string) $this->cell( $id, 'recorded_at' ) );
    }

    /**
     * The demo generator and the Excel importer know better than the
     * writer does: the register was saved by the team's coach on the
     * evening of the activity, not by whoever ran the job.
     */
    public function test_a_caller_that_knows_better_supplies_its_own_stamp(): void {
        $runner = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $coach  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $runner );

        $id = (int) $this->writer->recordActual( [
            'activity_id' => $this->activity,
            'player_id'   => $this->player,
            'status'      => 'Present',
            'recorded_by' => $coach,
            'recorded_at' => '2026-09-14 19:30:00',
        ] );

        $this->assertSame( $coach, (int) $this->cell( $id, 'recorded_by' ) );
        $this->assertSame( '2026-09-14 19:30:00', (string) $this->cell( $id, 'recorded_at' ) );
    }

    /* ---- which updates count as a register save -------------------- */

    public function test_changing_a_status_restamps_to_whoever_changed_it(): void {
        $first  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $second = self::factory()->user->create( [ 'role' => 'administrator' ] );

        wp_set_current_user( $first );
        $id = (int) $this->writer->recordActual( [
            'activity_id' => $this->activity,
            'player_id'   => $this->player,
            'status'      => 'Present',
        ] );

        wp_set_current_user( $second );
        $this->writer->updateRow( $id, [ 'status' => 'Absent' ] );

        $this->assertSame( $second, (int) $this->cell( $id, 'recorded_by' ) );
    }

    /**
     * Minutes, the line-up projection and a notes-only edit are not
     * somebody taking a register. Restamping on those would move the
     * author of the register onto whoever last typed a minute.
     */
    public function test_a_minutes_only_update_leaves_the_stamp_alone(): void {
        $coach = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $id = (int) $this->writer->recordActual( [
            'activity_id' => $this->activity,
            'player_id'   => $this->player,
            'status'      => 'Present',
            'recorded_by' => $coach,
            'recorded_at' => '2026-09-14 19:30:00',
        ] );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->writer->updateRow( $id, [ 'minutes_played' => 60 ] );

        $this->assertSame( $coach, (int) $this->cell( $id, 'recorded_by' ) );
        $this->assertSame( '2026-09-14 19:30:00', (string) $this->cell( $id, 'recorded_at' ) );
    }

    public function test_the_lineup_projection_is_not_a_register_save(): void {
        $coach = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $id = (int) $this->writer->recordActual( [
            'activity_id' => $this->activity,
            'player_id'   => $this->player,
            'status'      => 'Present',
            'recorded_by' => $coach,
            'recorded_at' => '2026-09-14 19:30:00',
        ] );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->writer->upsertLineupProjection( $this->activity, $this->player, 'starter', 'CM' );

        $this->assertSame( $coach, (int) $this->cell( $id, 'recorded_by' ) );
        $this->assertSame( '2026-09-14 19:30:00', (string) $this->cell( $id, 'recorded_at' ) );
    }

    /* ---- the read model -------------------------------------------- */

    public function test_the_newest_stamped_row_is_the_answer(): void {
        $early = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Eerste Trainer' ] );
        $late  = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Tweede Trainer' ] );

        $this->stampedRow( $this->player, $early, '2026-09-14 19:30:00' );
        $this->stampedRow( $this->insertPlayer( 'Noor' ), $late, '2026-09-15 08:05:00' );

        $out = ActivityRegisterProgress::lastSavedFor( $this->activity );

        $this->assertNotNull( $out );
        $this->assertSame( $late, $out['user_id'] );
        $this->assertSame( 'Tweede Trainer', $out['name'] );
        $this->assertSame( '2026-09-15 08:05:00', $out['at'] );
    }

    /**
     * Rows written before migration 0275 have no author and stay blank —
     * the line renders nothing rather than inventing one.
     */
    public function test_a_register_recorded_before_the_columns_existed_says_nothing(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $this->activity,
            'player_id'   => $this->player,
            'is_guest'    => 0,
            'status'      => 'Present',
            'record_type' => 'actual',
        ] );

        $this->assertNull( ActivityRegisterProgress::lastSavedFor( $this->activity ) );
    }

    public function test_a_guest_row_is_not_the_register_being_saved(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $this->activity,
            'player_id'   => null,
            'is_guest'    => 1,
            'guest_name'  => 'Gast',
            'status'      => 'Present',
            'record_type' => 'actual',
            'recorded_by' => self::factory()->user->create( [ 'role' => 'administrator' ] ),
            'recorded_at' => '2026-09-16 10:00:00',
        ] );

        $this->assertNull( ActivityRegisterProgress::lastSavedFor( $this->activity ) );
    }

    /* ---- the REST payload ------------------------------------------ */

    /**
     * The acceptance criterion, end to end: user A saves the register,
     * user B reads the activity and sees A; B then changes one status and
     * the line follows B. Rides on the `register` object the activity
     * payload already carries, so a non-WordPress client gets it with no
     * new route.
     */
    public function test_the_activity_payload_says_who_saved_the_register(): void {
        $a = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Anna Bakker' ] );
        $b = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Bram de Vries' ] );

        wp_set_current_user( $a );
        [ , $status ] = $this->send( 'POST', 'attendance/bulk', [ 'changes' => [
            [ 'activity_id' => $this->activity, 'player_id' => $this->player, 'status' => 'present' ],
        ] ] );
        $this->assertSame( 200, $status );

        wp_set_current_user( $b );
        ActivityRegisterProgress::forget();
        $saved = $this->lastSavedFromRest();

        $this->assertNotNull( $saved );
        $this->assertSame( $a, (int) $saved['user_id'] );
        $this->assertSame( 'Anna Bakker', (string) $saved['name'] );

        [ , $status ] = $this->send( 'POST', 'attendance/bulk', [ 'changes' => [
            [ 'activity_id' => $this->activity, 'player_id' => $this->player, 'status' => 'absent' ],
        ] ] );
        $this->assertSame( 200, $status );

        ActivityRegisterProgress::forget();
        $saved = $this->lastSavedFromRest();

        $this->assertNotNull( $saved );
        $this->assertSame( $b, (int) $saved['user_id'] );
        $this->assertSame( 'Bram de Vries', (string) $saved['name'] );
    }

    /* ---- fixtures --------------------------------------------------- */

    /** @return array<string,mixed>|null */
    private function lastSavedFromRest(): ?array {
        [ $data, $status ] = $this->send( 'GET', 'activities?filter[team_id]=' . $this->team . '&per_page=50' );
        $this->assertSame( 200, $status );

        foreach ( (array) ( $data['data']['rows'] ?? [] ) as $row ) {
            $row = (array) $row;
            if ( (int) ( $row['id'] ?? 0 ) !== $this->activity ) continue;
            $register = (array) ( $row['register'] ?? [] );
            $att      = (array) ( $register['attendance'] ?? [] );
            $saved    = $att['last_saved'] ?? null;
            return is_array( $saved ) ? $saved : null;
        }
        $this->fail( 'The activity is missing from the list.' );
    }

    private function insertActivity(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Proeftraining',
            'session_date'        => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -3 days' ) ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => $first,
            'last_name'  => 'Jansen',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function stampedRow( int $player_id, int $user_id, string $at ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $this->activity,
            'player_id'   => $player_id,
            'is_guest'    => 0,
            'status'      => 'Present',
            'record_type' => 'actual',
            'recorded_by' => $user_id,
            'recorded_at' => $at,
        ] );
    }

    private function cell( int $row_id, string $column ): ?string {
        global $wpdb;
        $value = $column === 'recorded_by'
            ? $wpdb->get_var( $wpdb->prepare(
                "SELECT recorded_by FROM {$this->p}tt_attendance WHERE id = %d", $row_id
            ) )
            : $wpdb->get_var( $wpdb->prepare(
                "SELECT recorded_at FROM {$this->p}tt_attendance WHERE id = %d", $row_id
            ) );

        return $value === null ? null : (string) $value;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body = [] ): array {
        $path  = '/talenttrack/v1/' . $route;
        $query = [];
        if ( strpos( $path, '?' ) !== false ) {
            $cut  = (int) strpos( $path, '?' );
            $qs   = substr( $path, $cut + 1 );
            $path = substr( $path, 0, $cut );
            parse_str( $qs, $query );
        }
        $request = new WP_REST_Request( $method, $path );
        if ( $query ) $request->set_query_params( $query );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_do_request( $request );
        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }
}
