<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Services\ActivityCoachAssignment;
use TT\Modules\Alerts\Definitions\AttendanceUnrecordedAlert;
use TT\Modules\Alerts\Definitions\NoCoachAssignedAlert;
use TT\Modules\Alerts\Domain\AlertContext;

/**
 * #3745 — `tt_activities.coach_id` is the coach, not the creator.
 *
 * Every write path stamped `get_current_user_id()`, so the administrator
 * typing a season schedule became the coach of every activity in it and
 * collected the register reminders for all of them. `POST` and `PUT` also
 * accepted a `coach_id`, answered 200 and threw it away, which left the
 * caller with no way to learn the value had been discarded.
 *
 * The weight here sits on the three things that were actually wrong:
 * the derived default, the refusal that replaced the silent 200, and the
 * alert routing that reads the column — plus the §6 partial-update
 * contract, because an omitted `coach_id` overwriting a stored coach would
 * be the same bug pointing the other way.
 */
final class ActivityCoachAssignmentTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $admin;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();
        // The TT roles install on activation, which the wp-env bootstrap does
        // not fire; without them `tt_coach` holds no `tt_edit_activities` and
        // the scope assertions below would pass on a flat permission refusal.
        ( new RolesService() )->installRoles();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the derived default ────────────────────────────────────────────

    public function test_a_created_activity_gets_the_teams_head_coach_not_its_creator(): void {
        $head = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team = $this->insertTeam( 'U11' );
        $this->assignRole( $team, $head, 'head_coach' );

        $id = $this->createActivity( $team );

        $this->assertSame( $head, (int) $this->row( $id )['coach_id'] );
        $this->assertNotSame( $this->admin, (int) $this->row( $id )['coach_id'] );
    }

    /** The creator keeps a column of their own. The two facts stop sharing one. */
    public function test_the_creator_is_recorded_separately(): void {
        $head = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team = $this->insertTeam( 'U11' );
        $this->assignRole( $team, $head, 'head_coach' );

        $id = $this->createActivity( $team );

        $this->assertSame( $this->admin, (int) $this->row( $id )['created_by'] );
    }

    /**
     * Guessing between two heads reintroduces exactly the class of wrong
     * recipient this issue is about, so the column is left empty and the
     * "no coach assigned" alert says so instead.
     */
    public function test_two_head_coaches_leave_the_activity_unassigned(): void {
        $team = $this->insertTeam( 'U12 double-staffed' );
        $this->assignRole( $team, self::factory()->user->create( [ 'role' => 'administrator' ] ), 'head_coach' );
        $this->assignRole( $team, self::factory()->user->create( [ 'role' => 'administrator' ] ), 'head_coach' );

        $this->assertNull( ActivityCoachAssignment::derivedForTeam( $team ) );
        $this->assertUnassigned( $this->createActivity( $team ) );
    }

    public function test_no_head_coach_leaves_the_activity_unassigned(): void {
        $team = $this->insertTeam( 'U13 unstaffed' );

        $this->assertNull( ActivityCoachAssignment::derivedForTeam( $team ) );
        $this->assertUnassigned( $this->createActivity( $team ) );
    }

    public function test_a_head_coach_with_no_wp_account_leaves_the_activity_unassigned(): void {
        $team = $this->insertTeam( 'U13 volunteer' );
        $this->assignRole( $team, 0, 'head_coach' );

        $this->assertNull( ActivityCoachAssignment::derivedForTeam( $team ) );
    }

    // ── the override ───────────────────────────────────────────────────

    public function test_a_submitted_coach_is_stored_rather_than_discarded(): void {
        $head      = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $assistant = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team      = $this->insertTeam( 'U11' );
        $this->assignRole( $team, $head, 'head_coach' );
        $this->assignRole( $team, $assistant, 'assistant_coach' );

        $id = $this->createActivity( $team, [ 'coach_id' => $assistant ] );

        $this->assertSame( $assistant, (int) $this->row( $id )['coach_id'] );
    }

    public function test_an_update_stores_a_submitted_coach(): void {
        $head      = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $assistant = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team      = $this->insertTeam( 'U11' );
        $this->assignRole( $team, $head, 'head_coach' );
        $this->assignRole( $team, $assistant, 'assistant_coach' );

        $id = $this->createActivity( $team );
        $this->assertSame( 200, $this->put( $id, [ 'coach_id' => $assistant ] )->get_status() );

        $this->assertSame( $assistant, (int) $this->row( $id )['coach_id'] );
    }

    public function test_an_explicit_zero_clears_the_coach(): void {
        $head = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team = $this->insertTeam( 'U11' );
        $this->assignRole( $team, $head, 'head_coach' );

        $id = $this->createActivity( $team );
        $this->assertSame( 200, $this->put( $id, [ 'coach_id' => 0 ] )->get_status() );

        $this->assertUnassigned( $id );
    }

    /**
     * CLAUDE.md §6 — the field an update does not send is the field an
     * update does not touch. The previous `unset()` achieved this by never
     * writing the column at all; the new branch has to do it deliberately.
     */
    public function test_an_update_that_omits_the_coach_leaves_it_alone(): void {
        $head      = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $assistant = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team      = $this->insertTeam( 'U11' );
        $this->assignRole( $team, $head, 'head_coach' );
        $this->assignRole( $team, $assistant, 'assistant_coach' );

        $id = $this->createActivity( $team, [ 'coach_id' => $assistant ] );
        $this->assertSame( 200, $this->put( $id, [ 'location' => 'Veld 3' ] )->get_status() );

        $this->assertSame( $assistant, (int) $this->row( $id )['coach_id'] );
        $this->assertSame( 'Veld 3', (string) $this->row( $id )['location'] );
    }

    // ── the refusal that replaced the silent 200 ───────────────────────

    public function test_a_coach_outside_the_actors_scope_is_refused_by_name(): void {
        $my_team    = $this->insertTeam( 'U11 mine' );
        $other_team = $this->insertTeam( 'U15 theirs' );
        $coach      = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $outsider   = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        // A coach, not an administrator: the actor's scope is the one team
        // they are granted, and `$outsider` works on a different one.
        $this->grantTeamScope( $this->assignRole( $my_team, $coach, 'head_coach' ), $my_team );
        $this->assignRole( $other_team, $outsider, 'head_coach' );

        wp_set_current_user( $coach );
        $response = $this->post( [
            'title'        => 'Training',
            'session_date' => '2026-11-09',
            'team_id'      => $my_team,
            'coach_id'     => $outsider,
        ] );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'coach_out_of_scope', $data['errors'][0]['code'] );
        $this->assertSame( 'coach_id', $data['errors'][0]['details']['field'] );
    }

    /**
     * The scope check is the guard, not the role name: an assistant may
     * name a colleague on a team they work with.
     */
    public function test_an_assistant_coach_may_name_another_coach_on_their_own_team(): void {
        $assistant = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $head      = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $team      = $this->insertTeam( 'U11' );
        $this->assignRole( $team, $head, 'head_coach' );
        $this->grantTeamScope( $this->assignRole( $team, $assistant, 'assistant_coach' ), $team );

        wp_set_current_user( $assistant );

        $this->assertTrue( ActivityCoachAssignment::mayAssign( $head, $assistant ) );
        $this->assertSame( 200, $this->post( [
            'title'        => 'Training',
            'session_date' => '2026-11-09',
            'team_id'      => $team,
            'coach_id'     => $head,
        ] )->get_status() );
    }

    // ── what the alerts engine then reads ──────────────────────────────

    /**
     * The whole point. An administrator schedules a session for a team she
     * does not coach; the register reminder goes to the coach, and she is
     * not on the list.
     */
    public function test_an_admin_who_schedules_a_session_is_not_alerted_about_its_register(): void {
        $head = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team = $this->insertTeam( 'U11' );
        $this->assignRole( $team, $head, 'head_coach' );

        $this->createActivity( $team, [ 'session_date' => $this->daysAgo( 5 ), 'activity_status_key' => 'completed' ] );

        $recipients = array_map(
            static function ( $occurrence ) { return $occurrence->recipientUserId; },
            ( new AttendanceUnrecordedAlert() )->evaluate( new AlertContext( $this->club ) )
        );

        $this->assertContains( $head, $recipients );
        $this->assertNotContains( $this->admin, $recipients, 'The person who typed the schedule is not the person who runs the session.' );
    }

    /**
     * `NoCoachAssignedAlert` was dead code: it fires on `coach_id IS NULL
     * OR = 0`, and no REST- or wizard-created row was ever either. An
     * unstaffed team's upcoming session now reaches it.
     */
    public function test_an_unstaffed_teams_upcoming_activity_raises_the_no_coach_alert(): void {
        $team = $this->insertTeam( 'U13 unstaffed' );
        // Somebody to tell: the alert routes to the team head coach, and a
        // team with neither an assigned coach nor a head coach raises
        // nothing by design. Two heads is the case that leaves the column
        // empty AND still has a recipient.
        $first  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $second = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assignRole( $team, $first, 'head_coach' );
        $this->assignRole( $team, $second, 'head_coach' );

        $this->assertUnassigned( $this->createActivity( $team, [ 'session_date' => $this->daysAhead( 3 ) ] ) );

        $out = ( new NoCoachAssignedAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertNotEmpty( $out, 'An upcoming activity nobody is down to run has to be sayable.' );
        $this->assertSame( 'activity', $out[0]->subjectType );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @param array<string,mixed> $extra */
    private function createActivity( int $team_id, array $extra = [] ): int {
        $response = $this->post( array_merge( [
            'title'        => 'Triage test training',
            'session_date' => '2026-11-09',
            'team_id'      => $team_id,
        ], $extra ) );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        return (int) $data['data']['id'];
    }

    /** @param array<string,mixed> $body */
    private function post( array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/activities' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    /** @param array<string,mixed> $body */
    private function put( int $id, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $id );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    /**
     * "Nobody is down to run this." The column is `BIGINT UNSIGNED DEFAULT
     * 0` and nullable, and `NoCoachAssignedAlert` reads both shapes as
     * unassigned, so the tests do too rather than pinning one of them.
     */
    private function assertUnassigned( int $id ): void {
        $this->assertSame( 0, (int) $this->row( $id )['coach_id'] );
    }

    /** @return array<string,mixed> */
    private function row( int $id ): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_activities WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function assignRole( int $team_id, int $user_id, string $role_key ): int {
        global $wpdb;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_functional_roles WHERE role_key = %s LIMIT 1",
            $role_key
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [
                'club_id'  => $this->club,
                'role_key' => $role_key,
                'label'    => ucwords( str_replace( '_', ' ', $role_key ) ),
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Test',
            'last_name'  => ucfirst( str_replace( '_', ' ', $role_key ) ),
            'wp_user_id' => $user_id,
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
        ] );

        return $person_id;
    }

    /**
     * The team grant `QueryHelpers::get_teams_for_coach()` reads. Written
     * live by `PeopleRepository::syncTeamScopeRow()`; inserted directly
     * here so the fixture does not depend on the People write path.
     */
    private function grantTeamScope( int $person_id, int $team_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => 0,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
            'start_date' => null,
            'end_date'   => null,
        ] );
    }

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) - $n * DAY_IN_SECONDS );
    }

    private function daysAhead( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) + $n * DAY_IN_SECONDS );
    }
}
