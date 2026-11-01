<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Wizards\Evaluation\ActivityPickerStep;
use TT\Modules\Wizards\Evaluation\AttendanceStep;
use TT\Modules\Wizards\Evaluation\RateConfirmStep;
use TT\Modules\Wizards\MarkAttendance\MarkAttendanceWizard;
use TT\Shared\Wizards\WizardRegistry;

/**
 * The Mark attendance wizard opens for who may take the register, a team
 * manager included, through the real entry gate. The manager completes the
 * register for their own team, cannot pick another team's activity, and is
 * not offered the rating steps.
 */
final class MarkAttendanceRegisterGateTest extends WP_UnitTestCase {

    private const SLUG = 'mark-attendance';

    private int $own_team       = 0;
    private int $other_team     = 0;
    private int $own_activity   = 0;
    private int $other_activity = 0;
    private int $own_player     = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        WizardRegistry::register( new MarkAttendanceWizard() );
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Regate Own U9', 'age_group' => 'U9' ] );
        $this->own_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Regate Other U9', 'age_group' => 'U9' ] );
        $this->other_team = (int) $wpdb->insert_id;

        $this->own_player     = $this->player( $this->own_team, 'Regateown' );
        $this->own_activity   = $this->activity( $this->own_team );
        $this->other_activity = $this->activity( $this->other_team );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_manager_opens_the_wizard_through_the_entry_gate(): void {
        $manager = $this->functionalRoleManager();
        $this->assertFalse(
            AuthorizationService::userCanOrMatrix( $manager, 'tt_edit_evaluations' ),
            'precondition: the old requiredCap gate refuses them'
        );
        $this->assertTrue( WizardRegistry::isAvailable( self::SLUG, $manager ) );
    }

    public function test_a_user_who_cannot_take_any_register_is_refused(): void {
        $player_user = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->assertFalse( WizardRegistry::isAvailable( self::SLUG, $player_user ) );
    }

    public function test_a_coach_still_opens_the_wizard_and_is_offered_rating(): void {
        $coach = $this->headCoachOf( $this->own_team );
        $this->assertTrue( WizardRegistry::isAvailable( self::SLUG, $coach ) );

        $state = [ '_path' => 'activity-first', 'activity_id' => $this->own_activity ];
        $this->assertFalse( RateConfirmStep::registerOnly( $state ) );
        $this->assertStringContainsString( 'Rate players', $this->renderRateConfirm( $state ) );
        $this->assertSame( 0, ( new RateConfirmStep() )->validate( [ '_rate_choice' => 'yes' ], $state )['_skip_rating'] );
    }

    public function test_a_manager_completes_the_register_for_their_own_team_without_rating(): void {
        $manager = $this->functionalRoleManager();
        wp_set_current_user( $manager );

        $seed = ( new MarkAttendanceWizard() )->initialState( [ 'activity_id' => $this->own_activity ] );
        $this->assertSame( $this->own_activity, $seed['activity_id'] ?? 0 );

        $state = array_merge( $seed, [ '_path' => 'activity-first' ] );
        ( new AttendanceStep() )->validate( [ 'attendance' => [ (string) $this->own_player => 'present' ] ], $state );
        $this->assertSame( 1, $this->actualRows( $this->own_activity ), 'the register is saved' );

        $step = new RateConfirmStep();
        $html = $this->renderRateConfirm( $state );
        $this->assertStringNotContainsString( 'Rate players', $html, 'the rating fork is not offered' );
        $this->assertStringContainsString( 'Attendance saved', $html );

        // A crafted "yes" still finishes instead of reaching the rating steps.
        $state = array_merge( $state, $step->validate( [ '_rate_choice' => 'yes' ], $state ) );
        $this->assertNull( $step->nextStep( $state ) );

        $result = $step->submit( $state );
        $this->assertIsArray( $result );
        $this->assertSame( 'completed', $this->planState( $this->own_activity ) );
    }

    public function test_a_manager_cannot_pick_another_teams_activity(): void {
        $manager = $this->functionalRoleManager();
        wp_set_current_user( $manager );

        $seed = ( new MarkAttendanceWizard() )->initialState( [ 'activity_id' => $this->other_activity ] );
        $this->assertArrayNotHasKey( 'activity_id', $seed );
        $this->assertInstanceOf(
            \WP_Error::class,
            ( new ActivityPickerStep() )->validate( [ 'activity_id' => $this->other_activity ], [] )
        );
    }

    /** @param array<string,mixed> $state */
    private function renderRateConfirm( array $state ): string {
        ob_start();
        ( new RateConfirmStep() )->render( $state );
        return (string) ob_get_clean();
    }

    private function functionalRoleManager(): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Regate', 'last_name' => 'Managerx',
            'role_type' => 'staff', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s AND club_id = %d", 'manager', 1
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$p}tt_functional_roles", [
                'club_id' => 1, 'role_key' => 'manager', 'label' => 'Manager',
                'description' => 'Created by MarkAttendanceRegisterGateTest.', 'is_system' => 1, 'sort_order' => 90,
            ] );
            $role_id = (int) $wpdb->insert_id;
        }
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $this->own_team, 'person_id' => $person,
            'functional_role_id' => $role_id, 'role_in_team' => 'manager',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->own_team,
        ] );
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
        MatrixRepository::clearCache();
        return $uid;
    }

    private function headCoachOf( int $team_id ): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Regate', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $team_id, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $coach );
        return $coach;
    }

    private function actualRows( int $activity_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_attendance WHERE activity_id = %d AND record_type = 'actual'",
            $activity_id
        ) );
    }

    private function planState( int $activity_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT plan_state FROM {$wpdb->prefix}tt_activities WHERE id = %d", $activity_id
        ) );
    }

    private function player( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => 1, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Kidx',
            'status' => 'active', 'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the player row was written' );
        return $id;
    }

    private function activity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => 1, 'team_id' => $team_id, 'title' => 'Regate training',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '-2 days' ) ),
            'activity_type_key' => 'training', 'activity_status_key' => 'planned', 'plan_state' => 'scheduled',
        ] );
        return (int) $wpdb->insert_id;
    }
}
