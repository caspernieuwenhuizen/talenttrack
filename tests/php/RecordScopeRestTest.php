<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\REST\ActivitiesRestController;
use TT\Infrastructure\REST\EvaluationsRestController;
use TT\Infrastructure\REST\PeopleRestController;
use TT\Infrastructure\REST\RecycleBinRestController;
use TT\Infrastructure\REST\TrainingPlansRestController;
use TT\Infrastructure\REST\TrainingRunsRestController;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Comms\Rest\CommsRestController;
use TT\Modules\MatchAnalysis\Rest\MatchAnalysisRestController;

/**
 * #4002 — a route that takes a record id checks that record.
 *
 * The capabilities these routes gate on are club-wide: `tt_edit_activities`,
 * `tt_training_plan`, `tt_view_people`, the comms log's read cap. They
 * answer "does this user do this kind of thing?", never "to whose record?".
 * Their siblings had already learned that — `update_session` resolves the
 * activity's team, `GoalsRestController::goalRefusal()` resolves the goal's
 * player — and this is the rest of the family.
 *
 * Every case is asserted in both directions in the same test: a record
 * outside the caller's scope is refused, one inside it is served. A guard
 * that refused the caller's own record would be the worse bug.
 *
 * The refusal code follows the sibling: the activity routes answer
 * `403 forbidden_team`, as `update_session` does; the rest answer
 * `404 not_found`, the same answer a missing record gets, so the route
 * cannot be walked to learn what exists.
 */
final class RecordScopeRestTest extends WP_UnitTestCase {

    private int $mineTeam     = 0;
    private int $otherTeam    = 0;
    private int $mineActivity = 0;
    private int $otherActivity = 0;
    private int $minePlayer   = 0;
    private int $otherPlayer  = 0;
    private int $coach        = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Scope Mine' ] );
        $this->mineTeam = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Scope Theirs' ] );
        $this->otherTeam = (int) $wpdb->insert_id;

        $this->mineActivity  = $this->activity( $this->mineTeam, 'My match' );
        $this->otherActivity = $this->activity( $this->otherTeam, 'Their match' );
        $this->minePlayer    = $this->player( $this->mineTeam, 'Mine' );
        $this->otherPlayer   = $this->player( $this->otherTeam, 'Theirs' );

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Scope',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $this->coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->mineTeam,
        ] );
        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- activities: 403 forbidden_team, matching update_session ----

    public function test_adding_a_guest_to_another_teams_activity_is_refused(): void {
        $refused = ActivitiesRestController::add_guest( $this->req( [
            'id'         => $this->otherActivity,
            'guest_name' => 'A stranger',
        ] ) );
        $this->assertForbiddenTeam( $refused );
        $this->assertSame( 0, $this->attendanceCount( $this->otherActivity ), 'nothing was written' );

        $served = ActivitiesRestController::add_guest( $this->req( [
            'id'         => $this->mineActivity,
            'guest_name' => 'A trialist',
        ] ) );
        $this->assertSame( 200, $served->get_status(), 'their own activity still takes a guest' );
    }

    public function test_setting_the_status_of_another_teams_activity_is_refused(): void {
        $refused = ActivitiesRestController::set_status( $this->req( [
            'id'     => $this->otherActivity,
            'status' => 'cancelled',
        ] ) );
        $this->assertForbiddenTeam( $refused );
        $this->assertNotSame(
            'cancelled',
            (string) ( $this->activityRow( $this->otherActivity )->activity_status_key ?? '' ),
            'a refused transition writes nothing'
        );

        $served = ActivitiesRestController::set_status( $this->req( [
            'id'     => $this->mineActivity,
            'status' => 'cancelled',
        ] ) );
        $this->assertNotSame( 403, $served->get_status(), 'their own activity still cancels' );
    }

    public function test_toggling_another_teams_evaluation_skipped_flag_is_refused(): void {
        $refused = ActivitiesRestController::patch_evaluation_skipped( $this->req( [
            'id'      => $this->otherActivity,
            'skipped' => 1,
        ] ) );
        $this->assertForbiddenTeam( $refused );

        $served = ActivitiesRestController::patch_evaluation_skipped( $this->req( [
            'id'      => $this->mineActivity,
            'skipped' => 1,
        ] ) );
        $this->assertNotSame( 403, $served->get_status() );
    }

    public function test_editing_and_deleting_another_teams_attendance_row_is_refused(): void {
        $theirs = $this->attendanceRow( $this->otherActivity, $this->otherPlayer );
        $mine   = $this->attendanceRow( $this->mineActivity, $this->minePlayer );

        $this->assertForbiddenTeam( ActivitiesRestController::patch_attendance( $this->req( [
            'id'    => $theirs,
            'notes' => 'not mine to write',
        ] ) ) );
        $this->assertForbiddenTeam(
            ActivitiesRestController::delete_attendance( $this->req( [ 'id' => $theirs ] ) )
        );
        $this->assertSame( 1, $this->attendanceCount( $this->otherActivity ), 'the row is still there' );

        $served = ActivitiesRestController::patch_attendance( $this->req( [
            'id'    => $mine,
            'notes' => 'mine to write',
        ] ) );
        $this->assertNotSame( 403, $served->get_status(), 'their own register is still editable' );
    }

    // ---- match analysis: the sibling match-day controllers answer 403 --

    public function test_reading_and_writing_another_teams_match_analysis_is_refused(): void {
        $read = MatchAnalysisRestController::get( $this->req( [ 'activity_id' => $this->otherActivity ] ) );
        $this->assertForbiddenTeam( $read );

        $write = MatchAnalysisRestController::put( $this->req( [
            'activity_id' => $this->otherActivity,
            'summary'     => 'Not my match to write up.',
        ] ) );
        $this->assertForbiddenTeam( $write );
        $this->assertSame( 0, $this->analysisCount(), 'a refused write leaves no analysis row behind' );

        $own = MatchAnalysisRestController::get( $this->req( [ 'activity_id' => $this->mineActivity ] ) );
        $this->assertNotSame( 403, $own->get_status(), 'their own match still opens' );
    }

    public function test_another_teams_match_analysis_trends_are_refused(): void {
        $refused = MatchAnalysisRestController::team_trends( $this->req( [ 'team_id' => $this->otherTeam ] ) );
        $this->assertSame( 403, $refused->get_status() );

        $served = MatchAnalysisRestController::team_trends( $this->req( [ 'team_id' => $this->mineTeam ] ) );
        $this->assertSame( 200, $served->get_status() );
    }

    // ---- training: 404 not_found -------------------------------------

    public function test_another_teams_training_plan_is_not_found(): void {
        $theirs = $this->plan( $this->otherTeam );
        $mine   = $this->plan( $this->mineTeam );

        $this->assertNotFound( TrainingPlansRestController::get_plan( $this->req( [ 'id' => $theirs ] ) ) );
        $this->assertNotFound( TrainingPlansRestController::archive_plan( $this->req( [ 'id' => $theirs ] ) ) );
        $this->assertNull( $this->planArchivedAt( $theirs ), 'a refused archive writes nothing' );

        $served = TrainingPlansRestController::get_plan( $this->req( [ 'id' => $mine ] ) );
        $this->assertSame( 200, $served->get_status(), 'their own plan still opens' );
    }

    /** A club-wide plan belongs to no team, so every plan-holder keeps it. */
    public function test_a_club_wide_plan_is_still_readable(): void {
        $club_wide = $this->plan( 0 );

        $this->assertSame(
            200,
            TrainingPlansRestController::get_plan( $this->req( [ 'id' => $club_wide ] ) )->get_status()
        );
    }

    public function test_another_teams_training_run_is_not_found(): void {
        $theirs = $this->trainingRun( $this->otherTeam, $this->otherActivity );
        $mine   = $this->trainingRun( $this->mineTeam, $this->mineActivity );

        $this->assertNotFound( TrainingRunsRestController::get_run( $this->req( [ 'id' => $theirs ] ) ) );
        $this->assertNotFound( TrainingRunsRestController::detach( $this->req( [ 'id' => $theirs ] ) ) );
        $this->assertNotNull( $this->runRow( $theirs ), 'a refused detach deletes nothing' );

        $this->assertSame(
            200,
            TrainingRunsRestController::get_run( $this->req( [ 'id' => $mine ] ) )->get_status()
        );
    }

    // ---- evaluations lifecycle ---------------------------------------

    public function test_restoring_another_teams_evaluation_is_refused(): void {
        $theirs = $this->evaluation( $this->otherPlayer );
        $mine   = $this->evaluation( $this->minePlayer );

        $refused = EvaluationsRestController::restore_eval( $this->req( [ 'id' => $theirs ] ) );
        $this->assertSame( 403, $refused->get_status() );
        $this->assertSame(
            'forbidden_player',
            (string) ( ( (array) $refused->get_data() )['errors'][0]['code'] ?? '' )
        );

        $served = EvaluationsRestController::restore_eval( $this->req( [ 'id' => $mine ] ) );
        $this->assertNotSame( 403, $served->get_status(), 'their own player is still restorable' );
    }

    // ---- people -------------------------------------------------------

    public function test_updating_a_person_that_does_not_exist_is_not_found(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $refused = PeopleRestController::update_person( $this->req( [
            'id'    => 999999,
            'phone' => '0612345678',
        ] ) );
        $this->assertNotFound( $refused );
    }

    // ---- comms --------------------------------------------------------

    public function test_another_teams_player_message_log_is_not_found(): void {
        $refused = CommsRestController::listPlayerMessages( $this->req( [ 'id' => $this->otherPlayer ] ) );
        $this->assertNotFound( $refused );

        // A caller whose scope does cover the player reads it as before.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $served = CommsRestController::listPlayerMessages( $this->req( [ 'id' => $this->otherPlayer ] ) );
        $this->assertSame( 200, $served->get_status(), 'a global-scope caller still reads the log' );
    }

    // ---- recycle bin ---------------------------------------------------

    public function test_the_bin_preview_refuses_a_live_record(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $live = RecycleBinRestController::preview( $this->req( [
            'entity' => 'team',
            'id'     => $this->otherTeam,
        ] ) );
        $this->assertNotFound( $live, 'an active record is not a bin preview' );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_teams',
            [ 'archived_at' => current_time( 'mysql' ) ],
            [ 'id' => $this->otherTeam ]
        );

        $archived = RecycleBinRestController::preview( $this->req( [
            'entity' => 'team',
            'id'     => $this->otherTeam,
        ] ) );
        $this->assertSame(
            200,
            $archived->get_status(),
            'the impact statement before moving an archived row to the bin still answers'
        );
    }

    // ---- helpers -------------------------------------------------------

    /** @param array<string, mixed> $params */
    private function req( array $params ): \WP_REST_Request {
        $r = new \WP_REST_Request();
        foreach ( $params as $k => $v ) $r->set_param( $k, $v );
        return $r;
    }

    private function assertForbiddenTeam( $res ): void {
        $this->assertInstanceOf( \WP_REST_Response::class, $res );
        $this->assertSame( 403, $res->get_status() );
        $this->assertSame(
            'forbidden_team',
            (string) ( ( (array) $res->get_data() )['errors'][0]['code'] ?? '' )
        );
    }

    private function assertNotFound( $res ): void {
        $this->assertInstanceOf( \WP_REST_Response::class, $res );
        $this->assertSame( 404, $res->get_status() );
        $this->assertSame(
            'not_found',
            (string) ( ( (array) $res->get_data() )['errors'][0]['code'] ?? '' )
        );
    }

    private function activity( int $team_id, string $title ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'team_id'           => $team_id,
            'title'             => $title,
            'session_date'      => '2026-03-01',
            'activity_type_key' => 'match',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function player( int $team_id, string $last ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => $team_id,
            'first_name' => 'Speler',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attendanceRow( int $activity_id, int $player_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'     => 1,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'is_guest'    => 0,
            'status'      => 'Present',
            'record_type' => 'actual',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attendanceCount( int $activity_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_attendance WHERE activity_id = %d",
            $activity_id
        ) );
    }

    private function analysisCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_match_analyses WHERE activity_id = %d",
            $this->otherActivity
        ) );
    }

    private function activityRow( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $id
        ) );
    }

    private function plan( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_training_plans', [
            'club_id' => 1,
            'uuid'    => wp_generate_uuid4(),
            'title'   => 'Plan ' . $team_id,
            'team_id' => $team_id > 0 ? $team_id : null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function planArchivedAt( int $id ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT archived_at FROM {$wpdb->prefix}tt_training_plans WHERE id = %d",
            $id
        ) );
    }

    /** Named `trainingRun`, not `run`: `TestCase::run()` is public and final-ish. */
    private function trainingRun( int $team_id, int $activity_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_training_plan_runs', [
            'club_id'     => 1,
            'uuid'        => wp_generate_uuid4(),
            'plan_id'     => $this->plan( $team_id ),
            'activity_id' => $activity_id,
            'team_id'     => $team_id,
            'run_date'    => '2026-03-01',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function runRow( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_training_plan_runs WHERE id = %d",
            $id
        ) );
    }

    private function evaluation( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_evaluations', [
            'club_id'     => 1,
            'player_id'   => $player_id,
            'coach_id'    => $this->coach,
            'eval_date'   => '2026-03-01',
            'archived_at' => current_time( 'mysql' ),
        ] );
        return (int) $wpdb->insert_id;
    }
}
