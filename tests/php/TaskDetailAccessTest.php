<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Workflow\Frontend\FrontendTaskDetailView;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\TaskAccess;
use TT\Modules\Workflow\Templates\InviteToTestTrainingTemplate;
use TT\Modules\Workflow\Templates\RecordTestTrainingOutcomeTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * One workflow task opens for its assignee, for whoever may complete it on
 * their behalf, for a global task reader, and — for a task about a
 * prospect — for whoever sees that prospect on the onboarding pipeline.
 * Anyone else gets the page a missing task gets.
 */
final class TaskDetailAccessTest extends WP_UnitTestCase {

    private int $assignee    = 0;
    private int $prospect_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        WorkflowModule::registerShippedTemplates();

        $this->assignee = $this->makeUser( 'tt_head_dev' );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'    => 1,
            'first_name' => 'Tycho',
            'last_name'  => 'Taskx',
        ] );
        $this->prospect_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_assignee_gets_the_form(): void {
        $task = $this->makeTask( RecordTestTrainingOutcomeTemplate::KEY );
        $html = $this->renderFor( $this->assignee, $task );

        $this->assertStringContainsString( 'tt_workflow_submit', $html );
        $this->assertStringNotContainsString( 'Task not found', $html );
    }

    public function test_a_player_is_refused_on_someone_elses_task(): void {
        $this->assertRefused( $this->makeUser( 'tt_player' ), $this->makeTask( RecordTestTrainingOutcomeTemplate::KEY ) );
    }

    public function test_a_parent_is_refused_on_someone_elses_task(): void {
        $this->assertRefused( $this->makeUser( 'tt_parent' ), $this->makeTask( RecordTestTrainingOutcomeTemplate::KEY ) );
    }

    public function test_an_unrelated_coach_is_refused_on_someone_elses_task(): void {
        $this->assertRefused( $this->makeUser( 'tt_coach' ), $this->makeTask( RecordTestTrainingOutcomeTemplate::KEY ) );
    }

    public function test_a_player_is_refused_on_a_prospect_task_too(): void {
        $this->assertRefused( $this->makeUser( 'tt_player' ), $this->makeTask( InviteToTestTrainingTemplate::KEY, $this->prospect_id ) );
    }

    public function test_the_club_admin_may_open_it_to_complete_on_behalf(): void {
        $admin = $this->makeUser( 'tt_club_admin' );
        $this->assertTrue( TaskAccess::canCompleteOnBehalf( $admin ), 'precondition: the club admin holds the takeover grant' );

        $html = $this->renderFor( $admin, $this->makeTask( RecordTestTrainingOutcomeTemplate::KEY ) );
        $this->assertStringNotContainsString( 'Task not found', $html );
    }

    public function test_a_scout_still_opens_a_pipeline_task_held_by_someone_else(): void {
        $scout = $this->makeUser( 'tt_scout' );
        $html  = $this->renderFor( $scout, $this->makeTask( InviteToTestTrainingTemplate::KEY, $this->prospect_id ) );

        $this->assertStringNotContainsString( 'Task not found', $html );
        $this->assertStringNotContainsString( 'tt_workflow_submit', $html, 'seeing it is not completing it' );
    }

    public function test_a_head_coach_is_refused_on_a_prospect_task_outside_their_scope(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Task-scope U14', 'age_group' => 'U14' ] );
        $coach = $this->makeHeadCoach( (int) $wpdb->insert_id );
        $this->assertTrue(
            AuthorizationService::userCanOrMatrix( $coach, 'tt_view_prospects' ),
            'precondition: the head coach reads prospects, so the refusal below comes from the scope'
        );

        // Not in their age group, not on their teams, not theirs: outside.
        $this->assertRefused( $coach, $this->makeTask( InviteToTestTrainingTemplate::KEY, $this->prospect_id ) );

        // One they logged themselves is inside, and opens.
        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'               => 1,
            'first_name'            => 'Own',
            'last_name'             => 'Findx',
            'discovered_by_user_id' => $coach,
        ] );
        $own  = $this->makeTask( InviteToTestTrainingTemplate::KEY, (int) $wpdb->insert_id );
        $html = $this->renderFor( $coach, $own );
        $this->assertStringNotContainsString( 'Task not found', $html );
    }

    public function test_a_refusal_reads_exactly_like_a_missing_task(): void {
        $player  = $this->makeUser( 'tt_player' );
        $task    = $this->makeTask( RecordTestTrainingOutcomeTemplate::KEY );
        $refused = $this->renderFor( $player, $task );
        $missing = $this->renderFor( $player, $task + 100000 );

        $this->assertSame( $missing, $refused, 'the page must not confirm that the id exists' );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function assertRefused( int $user_id, int $task_id ): void {
        $html = $this->renderFor( $user_id, $task_id );
        $this->assertStringContainsString( 'Task not found', $html );
        $this->assertStringNotContainsString( 'tt_workflow_submit', $html );
        $this->assertStringNotContainsString( 'Record test-training outcome', $html, 'not even the template name' );
        $this->assertFalse( TaskAccess::canView( $user_id, (array) ( new TasksRepository() )->find( $task_id ) ) );
    }

    private function makeUser( string $role ): int {
        $uid = self::factory()->user->create( [ 'role' => $role ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function makeTask( string $template_key, int $prospect_id = 0 ): int {
        $row = [
            'template_key'     => $template_key,
            'assignee_user_id' => $this->assignee,
            'due_at'           => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
        ];
        if ( $prospect_id > 0 ) $row['prospect_id'] = $prospect_id;
        $task_id = ( new TasksRepository() )->create( $row );
        $this->assertGreaterThan( 0, $task_id, 'the fixture must write a task' );
        return $task_id;
    }

    private function renderFor( int $user_id, int $task_id ): string {
        wp_set_current_user( $user_id );
        ob_start();
        FrontendTaskDetailView::render( $user_id, $task_id );
        return (string) ob_get_clean();
    }
}
