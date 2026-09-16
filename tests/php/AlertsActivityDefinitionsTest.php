<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\Definitions\AttendanceUnrecordedAlert;
use TT\Modules\Alerts\Definitions\PastStillPlannedAlert;
use TT\Modules\Alerts\Definitions\NoCoachAssignedAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * #2631 — the three wave 1 Activities definitions.
 *
 * `AlertsReconcileTest` covers what the evaluator does with what a
 * definition returns. This covers the other half: whether each definition's
 * query actually describes the condition it claims to.
 *
 * The negative cases carry most of the weight. A definition that fires on
 * the right rows but ALSO on some wrong ones is worse than one that never
 * fires — an alert nobody believes gets muted, and then the ones that
 * matter go with it.
 */
final class AlertsActivityDefinitionsTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $coach;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p     = $wpdb->prefix;
        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    // ── activities.past_still_planned ──────────────────────────────────

    public function test_past_planned_activity_produces_an_occurrence_for_its_coach(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->insertActivity( $team, $this->daysAgo( 3 ), 'planned', $this->coach );

        $out = ( new PastStillPlannedAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $this->coach, $out[0]->recipientUserId );
        $this->assertSame( 'activity', $out[0]->subjectType );
        $this->assertNotSame( '', $out[0]->title() );
    }

    public function test_completed_activity_produces_nothing(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->insertActivity( $team, $this->daysAgo( 3 ), 'completed', $this->coach );

        $this->assertSame( [], ( new PastStillPlannedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_future_planned_activity_produces_nothing(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->insertActivity( $team, $this->daysAhead( 3 ), 'planned', $this->coach );

        $this->assertSame( [], ( new PastStillPlannedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * An activity happening tonight has not finished yet. Telling a coach at
     * 09:00 that it is unmarked is the kind of wrongness that teaches people
     * to ignore the whole feature.
     */
    public function test_activity_dated_today_produces_nothing(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->insertActivity( $team, current_time( 'Y-m-d' ), 'planned', $this->coach );

        $this->assertSame( [], ( new PastStillPlannedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_archived_activity_produces_nothing(): void {
        global $wpdb;
        $team = $this->insertTeam( 'U14 alerts' );
        $id   = $this->insertActivity( $team, $this->daysAgo( 3 ), 'planned', $this->coach );
        $wpdb->update( "{$this->p}tt_activities", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        $this->assertSame( [], ( new PastStillPlannedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_severity_ages_up_after_a_week(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->insertActivity( $team, $this->daysAgo( 2 ), 'planned', $this->coach );

        $fresh = ( new PastStillPlannedAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertSame( Severity::ATTENTION, $fresh[0]->severity );

        global $wpdb;
        $wpdb->query( "DELETE FROM {$this->p}tt_activities" );
        $this->insertActivity( $team, $this->daysAgo( 10 ), 'planned', $this->coach );

        $stale = ( new PastStillPlannedAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertSame( Severity::URGENT, $stale[0]->severity );
    }

    public function test_activity_with_no_resolvable_recipient_produces_nothing(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        // No coach on the activity and no head coach on the team: there is
        // genuinely nobody to tell, and inventing a recipient would route a
        // squad's problem to someone who cannot fix it.
        $this->insertActivity( $team, $this->daysAgo( 3 ), 'planned', 0 );

        $this->assertSame( [], ( new PastStillPlannedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_scope_narrows_the_query(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $a    = $this->insertActivity( $team, $this->daysAgo( 3 ), 'planned', $this->coach );
        $this->insertActivity( $team, $this->daysAgo( 4 ), 'planned', $this->coach );

        $all = ( new PastStillPlannedAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertCount( 2, $all );

        $narrowed = ( new PastStillPlannedAlert() )->evaluate(
            new AlertContext( $this->club, 'activity', [ $a ] )
        );
        $this->assertCount( 1, $narrowed );
        $this->assertSame( $a, $narrowed[0]->subjectId );
    }

    // ── activities.attendance_unrecorded ───────────────────────────────

    public function test_completed_activity_without_attendance_alerts_after_the_grace_period(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->insertActivity( $team, $this->daysAgo( 5 ), 'completed', $this->coach );

        $out = ( new AttendanceUnrecordedAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $this->coach, $out[0]->recipientUserId );
    }

    public function test_completed_activity_with_attendance_produces_nothing(): void {
        $team   = $this->insertTeam( 'U14 alerts' );
        $id     = $this->insertActivity( $team, $this->daysAgo( 5 ), 'completed', $this->coach );
        $player = $this->insertPlayer( $team );
        $this->insertAttendance( $id, $player, 'present' );

        $this->assertSame( [], ( new AttendanceUnrecordedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * A roster row with no status is a placeholder, not an observation. A
     * activity full of blanks is still an unrecorded activity.
     */
    public function test_blank_attendance_rows_do_not_count_as_recorded(): void {
        $team   = $this->insertTeam( 'U14 alerts' );
        $id     = $this->insertActivity( $team, $this->daysAgo( 5 ), 'completed', $this->coach );
        $player = $this->insertPlayer( $team );
        $this->insertAttendance( $id, $player, '' );

        $this->assertCount( 1, ( new AttendanceUnrecordedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_activity_inside_the_grace_period_produces_nothing(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->insertActivity( $team, current_time( 'Y-m-d' ), 'completed', $this->coach );

        $this->assertSame( [], ( new AttendanceUnrecordedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    // ── #3444: the plan is not the register ────────────────────────────

    /**
     * The case the alert was built for and could not see. A coach ticks the
     * expected roster when the activity is created, never takes the register,
     * and marks the activity completed. Planned rows carry real statuses —
     * Expected is stored as Present — so before #3444 the `NOT EXISTS` was
     * satisfied by the plan and the alert stayed silent.
     */
    public function test_expected_roster_alone_does_not_count_as_recorded(): void {
        $team   = $this->insertTeam( 'U14 alerts' );
        $id     = $this->insertActivity( $team, $this->daysAgo( 5 ), 'completed', $this->coach );
        $player = $this->insertPlayer( $team );
        $this->insertAttendance( $id, $player, 'present', 'expected' );

        $out = ( new AttendanceUnrecordedAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $id, $out[0]->subjectId );
    }

    /**
     * A guest row is somebody else's player turning out. It is not this
     * squad's register, so it cannot stand in for one.
     */
    public function test_guest_rows_alone_do_not_count_as_recorded(): void {
        $team   = $this->insertTeam( 'U14 alerts' );
        $id     = $this->insertActivity( $team, $this->daysAgo( 5 ), 'completed', $this->coach );
        $player = $this->insertPlayer( $team );
        $this->insertAttendance( $id, $player, 'present', 'actual', 1 );

        $this->assertCount( 1, ( new AttendanceUnrecordedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * The other direction: once a real register exists alongside the plan,
     * the condition is gone. A definition that fired on both would be an
     * alert nobody could clear.
     */
    public function test_an_actual_row_beside_the_expected_roster_clears_it(): void {
        $team   = $this->insertTeam( 'U14 alerts' );
        $id     = $this->insertActivity( $team, $this->daysAgo( 5 ), 'completed', $this->coach );
        $player = $this->insertPlayer( $team );
        $this->insertAttendance( $id, $player, 'present', 'expected' );
        $this->insertAttendance( $id, $player, 'absent', 'actual' );

        $this->assertSame( [], ( new AttendanceUnrecordedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * The whole point of a state-derived alert: nobody tells the engine the
     * problem is fixed, the definition simply stops seeing it and the
     * reconcile resolves the open row. Worth asserting end-to-end here
     * because #3444 changed the predicate that decides "still true".
     */
    public function test_recording_the_register_resolves_an_open_occurrence(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$this->p}tt_alert_occurrences" );

        $user = new \WP_User( $this->coach );
        $user->add_cap( 'tt_edit_activities' );
        clean_user_cache( $this->coach );

        $team   = $this->insertTeam( 'U14 alerts' );
        $id     = $this->insertActivity( $team, $this->daysAgo( 5 ), 'completed', $this->coach );
        $player = $this->insertPlayer( $team );
        $this->insertAttendance( $id, $player, 'present', 'expected' );

        $alert = new AttendanceUnrecordedAlert();
        $ev    = new AlertEvaluator();
        $repo  = new AlertOccurrencesRepository();

        $ev->run( $alert, new AlertContext( $this->club ) );
        $this->assertSame( 1, $repo->openCountForUser( $this->coach ), 'the plan-only activity raises it' );

        $this->insertAttendance( $id, $player, 'present', 'actual' );
        $stat = $ev->run( $alert, new AlertContext( $this->club ) );

        $this->assertSame( 1, $stat['resolved'] );
        $this->assertSame( 0, $repo->openCountForUser( $this->coach ) );
    }

    // ── #3444: the lifecycle gate ──────────────────────────────────────

    /**
     * The default-value case the whole of #2521 was about. `plan_state`
     * defaults to `completed` on every create path but the team planner, so
     * an activity the coach never completed carried it anyway — and this
     * alert fired on activities that had not happened yet, doubling up on
     * `PastStillPlannedAlert`.
     */
    public function test_activity_still_planned_does_not_alert_whatever_plan_state_says(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->insertActivity( $team, $this->daysAgo( 5 ), 'completed', $this->coach, 'planned' );

        $this->assertSame( [], ( new AttendanceUnrecordedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * And the converse: the coach completed it on the axis the UI shows,
     * while `plan_state` was left at whatever its create path wrote. The
     * status the user set is the status the alert honours.
     */
    public function test_activity_completed_on_the_status_axis_alerts_whatever_plan_state_says(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->insertActivity( $team, $this->daysAgo( 5 ), 'scheduled', $this->coach, 'completed' );

        $this->assertCount( 1, ( new AttendanceUnrecordedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /** Cancelled is not completed, and a cancelled activity has no register. */
    public function test_cancelled_activity_produces_nothing(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->insertActivity( $team, $this->daysAgo( 5 ), 'cancelled', $this->coach );

        $this->assertSame( [], ( new AttendanceUnrecordedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    // ── activities.no_coach_assigned ───────────────────────────────────

    public function test_upcoming_activity_without_a_coach_alerts_the_team_head_coach(): void {
        $head = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team = $this->insertTeam( 'U14 alerts' );
        $this->assignHeadCoach( $team, $head );
        $this->insertActivity( $team, $this->daysAhead( 3 ), 'planned', 0 );

        $out = ( new NoCoachAssignedAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $head, $out[0]->recipientUserId );
    }

    public function test_upcoming_activity_with_a_coach_produces_nothing(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->assignHeadCoach( $team, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->insertActivity( $team, $this->daysAhead( 3 ), 'planned', $this->coach );

        $this->assertSame( [], ( new NoCoachAssignedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_activity_beyond_the_lookahead_produces_nothing(): void {
        $team = $this->insertTeam( 'U14 alerts' );
        $this->assignHeadCoach( $team, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->insertActivity( $team, $this->daysAhead( 30 ), 'planned', 0 );

        $this->assertSame( [], ( new NoCoachAssignedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) - $n * DAY_IN_SECONDS );
    }

    private function daysAhead( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) + $n * DAY_IN_SECONDS );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Alert',
            'last_name'  => 'Fixture',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * `$status_key` defaults to mirroring `$plan_state`, which is the only
     * state a well-behaved row is ever in. Pass it explicitly to build the
     * divergence #2521 was about — `plan_state = 'completed'` by default on a
     * row the coach never completed.
     */
    private function insertActivity( int $team_id, string $date, string $plan_state, int $coach_id, ?string $status_key = null ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Training ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'plan_state'          => $plan_state,
            'activity_status_key' => $status_key ?? $plan_state,
            'coach_id'            => $coach_id,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status, string $record_type = 'actual', int $is_guest = 0 ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'is_guest'    => $is_guest,
            'record_type' => $record_type,
        ] );
    }

    /**
     * Head-coach assignment through `tt_team_people`, the single source of
     * truth since #1315 retired `tt_teams.head_coach_id`.
     */
    private function assignHeadCoach( int $team_id, int $user_id ): void {
        global $wpdb;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_functional_roles WHERE role_key = %s LIMIT 1",
            'head_coach'
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [
                'club_id'  => $this->club,
                'role_key' => 'head_coach',
                'label'    => 'Head Coach',
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Head',
            'last_name'  => 'Coach',
            'wp_user_id' => $user_id,
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
        ] );
    }
}
