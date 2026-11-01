<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\ActivityAccess;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Wizards\Evaluation\ActivityPickerStep;
use TT\Modules\Wizards\Evaluation\AttendanceStep;
use TT\Modules\Wizards\Evaluation\NewEvaluationWizard;
use TT\Modules\Wizards\Evaluation\RateConfirmStep;
use TT\Modules\Wizards\MarkAttendance\MarkAttendanceWizard;

/**
 * The evaluation and attendance wizards take an activity id from the URL or
 * a posted pick. They act on it — list its roster, write its attendance,
 * complete it — only for an activity the reader may write, the question the
 * activities write routes ask. A coach of one team gets none of that for
 * another team's activity.
 */
final class WizardActivityScopeTest extends WP_UnitTestCase {

    private int $own_activity   = 0;
    private int $other_activity = 0;
    private int $own_player     = 0;
    private int $other_player   = 0;
    private int $coach          = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Wizscope Own U14', 'age_group' => 'U14' ] );
        $own_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Wizscope Other U14', 'age_group' => 'U14' ] );
        $other_team = (int) $wpdb->insert_id;

        $this->own_player   = $this->player( $own_team, 'Ownwiz' );
        $this->other_player = $this->player( $other_team, 'Otherwiz' );
        $this->own_activity   = $this->activity( $own_team );
        $this->other_activity = $this->activity( $other_team );

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Wizscope', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $club, 'team_id' => $own_team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $own_team,
        ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $this->coach );

        $this->assertTrue( ActivityAccess::canWriteActivity( $this->coach, $this->own_activity ), 'precondition: the coach writes their own team\'s activity' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_evaluation_wizard_seeds_only_an_activity_the_coach_may_write(): void {
        $wizard = new NewEvaluationWizard();
        $this->assertSame( $this->own_activity, $wizard->initialState( [ 'mode' => 'activity', 'activity_id' => $this->own_activity ] )['activity_id'] ?? 0 );
        $this->assertArrayNotHasKey( 'activity_id', $wizard->initialState( [ 'mode' => 'activity', 'activity_id' => $this->other_activity ] ) );
    }

    public function test_the_attendance_wizard_seeds_only_an_activity_the_coach_may_write(): void {
        $wizard = new MarkAttendanceWizard();
        $this->assertSame( $this->own_activity, $wizard->initialState( [ 'activity_id' => $this->own_activity ] )['activity_id'] ?? 0 );
        $this->assertArrayNotHasKey( 'activity_id', $wizard->initialState( [ 'activity_id' => $this->other_activity ] ) );
    }

    public function test_the_picker_refuses_another_teams_activity(): void {
        $step = new ActivityPickerStep();
        $this->assertIsArray( $step->validate( [ 'activity_id' => $this->own_activity ], [] ), 'precondition: the own activity is a pick' );
        $this->assertInstanceOf( \WP_Error::class, $step->validate( [ 'activity_id' => $this->other_activity ], [] ) );
    }

    public function test_the_roster_of_another_teams_activity_is_not_shown(): void {
        $this->assertStringContainsString( 'Ownwiz', $this->roster( $this->own_activity ), 'precondition: the own roster is listed' );
        $this->assertStringNotContainsString( 'Otherwiz', $this->roster( $this->other_activity ) );
    }

    public function test_attendance_is_not_written_for_another_teams_activity(): void {
        $result = ( new AttendanceStep() )->validate(
            [ 'attendance' => [ (string) $this->other_player => 'present' ] ],
            [ '_path' => 'activity-first', 'activity_id' => $this->other_activity ]
        );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( [], $this->actualPlayers( $this->other_activity ) );
    }

    public function test_attendance_is_written_only_for_players_on_the_roster(): void {
        ( new AttendanceStep() )->validate(
            [ 'attendance' => [ (string) $this->own_player => 'present', (string) $this->other_player => 'present' ] ],
            [ '_path' => 'activity-first', 'activity_id' => $this->own_activity ]
        );
        $this->assertSame( [ $this->own_player ], $this->actualPlayers( $this->own_activity ) );
    }

    public function test_an_activity_without_a_team_lists_and_writes_no_players(): void {
        $loose    = $this->player( 0, 'Loosewiz' );
        $teamless = $this->activity( 0 );
        $this->assertTrue( ActivityAccess::canWriteActivity( $this->coach, $teamless ), 'precondition: the activity itself is open to the coach' );

        $this->assertStringNotContainsString( 'Loosewiz', $this->roster( $teamless ) );
        ( new AttendanceStep() )->validate(
            [ 'attendance' => [ (string) $loose => 'present' ] ],
            [ '_path' => 'activity-first', 'activity_id' => $teamless ]
        );
        $this->assertSame( [], $this->actualPlayers( $teamless ) );
    }

    public function test_another_teams_activity_is_not_completed_or_marked_skipped(): void {
        ( new RateConfirmStep() )->submit( [
            '_path' => 'activity-first', 'activity_id' => $this->other_activity, '_skip_closes_rating' => 1,
        ] );
        AttendanceStep::completeActivityIfNotTerminal( $this->other_activity );

        $row = $this->activityRow( $this->other_activity );
        $this->assertSame( 'planned', (string) $row->activity_status_key );
        $this->assertSame( 0, (int) $row->evaluation_skipped );
    }

    public function test_the_coachs_own_activity_still_completes(): void {
        AttendanceStep::completeActivityIfNotTerminal( $this->own_activity );
        $this->assertSame( 'completed', (string) $this->activityRow( $this->own_activity )->activity_status_key );
    }

    private function roster( int $activity_id ): string {
        ob_start();
        ( new AttendanceStep() )->render( [ '_path' => 'activity-first', 'activity_id' => $activity_id ] );
        return (string) ob_get_clean();
    }

    /** @return list<int> */
    private function actualPlayers( int $activity_id ): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT player_id FROM {$wpdb->prefix}tt_attendance WHERE activity_id = %d AND record_type = 'actual' ORDER BY player_id",
            $activity_id
        ) ) );
    }

    private function activityRow( int $activity_id ): object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_activities WHERE id = %d", $activity_id ) );
    }

    private function player( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team_id,
            'first_name' => $first, 'last_name' => 'Kidx', 'status' => 'active',
            'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the player row was written' );
        return $id;
    }

    private function activity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team_id, 'title' => 'Wizscope training',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '-2 days' ) ),
            'activity_type_key' => 'training', 'activity_status_key' => 'planned', 'plan_state' => 'scheduled',
        ] );
        return (int) $wpdb->insert_id;
    }
}
