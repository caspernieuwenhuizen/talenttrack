<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Workflow\Frontend\FrontendTaskDetailView;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\Templates\ConfirmTestTrainingTemplate;
use TT\Modules\Workflow\Templates\InviteToTestTrainingTemplate;
use TT\Modules\Workflow\Templates\RecordTestTrainingOutcomeTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * #3869 — `tt_invite_prospects` was mapped into the matrix and named in
 * two docblocks as the gate on inviting a prospect, and was checked
 * nowhere. An administrator could grant or revoke it and nothing moved:
 * the only real gate was who the resolver had addressed the task to.
 *
 * What these pin is both halves of the fix. The capability decides who
 * may submit the invite task — and somebody who holds the task without
 * the capability is told so, on the task, rather than handed a form that
 * saves nothing. A silent no-op is the failure this issue is about, and
 * re-creating it in the fix would be worse than leaving it alone.
 *
 * The personas are read from the matrix rather than asserted against a
 * role name: head of development and academy admin hold
 * `test_trainings: change` and so hold the capability; scout holds
 * `test_trainings: read` and so does not. The read cap is asserted
 * alongside every refusal, because a persona that failed to resolve
 * would answer false to everything and pass for the wrong reason.
 */
final class InviteProspectCapabilityTest extends WP_UnitTestCase {

    private int $prospect_id = 0;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        WorkflowModule::registerShippedTemplates();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'    => 1,
            'first_name' => 'Tycho',
            'last_name'  => 'Prospect',
        ] );
        $this->prospect_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the templates declare the gate their docblocks always claimed ──

    public function test_the_invite_pair_declares_the_capability(): void {
        $this->assertSame(
            'tt_invite_prospects',
            ( new InviteToTestTrainingTemplate() )->requiredCapability()
        );
        $this->assertSame(
            'tt_invite_prospects',
            ( new ConfirmTestTrainingTemplate() )->requiredCapability()
        );
    }

    /** Assignment stays the only gate everywhere else — the coach's outcome task included. */
    public function test_an_ordinary_template_names_no_capability(): void {
        $this->assertNull( ( new RecordTestTrainingOutcomeTemplate() )->requiredCapability() );
    }

    // ── who holds it ───────────────────────────────────────────────────

    public function test_the_head_of_development_holds_the_capability(): void {
        $uid = $this->makeUser( 'tt_head_dev' );
        $this->assertTrue(
            AuthorizationService::userCanOrMatrix( $uid, 'tt_invite_prospects' ),
            'head of development holds test_trainings: change in the seed'
        );
    }

    public function test_the_academy_admin_holds_the_capability(): void {
        $uid = $this->makeUser( 'tt_club_admin' );
        $this->assertTrue(
            AuthorizationService::userCanOrMatrix( $uid, 'tt_invite_prospects' ),
            'academy admin holds test_trainings: change in the seed'
        );
    }

    public function test_a_scout_reads_test_trainings_but_does_not_hold_the_capability(): void {
        $uid = $this->makeUser( 'tt_scout' );

        $this->assertTrue(
            AuthorizationService::userCanOrMatrix( $uid, 'tt_view_test_trainings' ),
            'the persona resolved and the matrix answered — without this the refusal below proves nothing'
        );
        $this->assertFalse(
            AuthorizationService::userCanOrMatrix( $uid, 'tt_invite_prospects' ),
            'scout holds test_trainings: read only'
        );
    }

    // ── and what that means on the task ────────────────────────────────

    /** The holder's task is unchanged: the form submits. */
    public function test_the_holder_gets_the_submit_button(): void {
        $uid  = $this->makeUser( 'tt_head_dev' );
        $html = $this->renderTaskFor( $uid, $this->makeInviteTask( $uid ) );

        $this->assertStringContainsString( 'tt_workflow_submit', $html );
        $this->assertStringNotContainsString( 'tt-workflow-task-refusal', $html );
    }

    /**
     * The reported failure mode, inverted: an assignee without the
     * capability gets no submit button AND an explanation. Either half
     * alone would be worse than the bug.
     */
    public function test_an_assignee_without_the_capability_is_refused_and_told_why(): void {
        $uid  = $this->makeUser( 'tt_scout' );
        $html = $this->renderTaskFor( $uid, $this->makeInviteTask( $uid ) );

        $this->assertStringNotContainsString(
            'tt_workflow_submit',
            $html,
            'no dead button: the commit control is not rendered at all'
        );
        $this->assertStringContainsString(
            'tt-workflow-task-refusal',
            $html,
            'and the refusal says what is missing and who to ask'
        );
        $this->assertStringContainsString( 'academy administrator', $html );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'CLAUDE.md §5 — the refusal renders the chain' );
    }

    /** Direct URL, not just the inbox link: the same task, opened cold. */
    public function test_a_direct_post_from_a_non_holder_does_not_complete_the_task(): void {
        $uid     = $this->makeUser( 'tt_scout' );
        $task_id = $this->makeInviteTask( $uid );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tt_workflow_submit'              => '1',
            FrontendTaskDetailView::NONCE_FIELD => wp_create_nonce( FrontendTaskDetailView::NONCE_ACTION ),
            'new_date'                        => '2026-10-01T18:30',
            'invitation_message'              => 'Come along.',
        ];

        $this->renderTaskFor( $uid, $task_id );

        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $task = ( new TasksRepository() )->find( $task_id );
        $this->assertIsArray( $task );
        $this->assertNotSame( 'completed', (string) $task['status'], 'the POST must not slip past the gate' );
    }

    /** The task is still readable — the capability takes away acting, not seeing. */
    public function test_a_non_holder_still_sees_what_the_task_is(): void {
        $uid  = $this->makeUser( 'tt_scout' );
        $html = $this->renderTaskFor( $uid, $this->makeInviteTask( $uid ) );

        $this->assertStringContainsString(
            'Invite prospect to test training',
            $html,
            'the template name still renders — a blank page would be its own bug'
        );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function makeUser( string $wp_role ): int {
        if ( get_role( $wp_role ) === null ) {
            add_role( $wp_role, $wp_role, [ 'read' => true ] );
        }
        $uid = self::factory()->user->create( [ 'role' => $wp_role ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function makeInviteTask( int $assignee_user_id ): int {
        $task_id = ( new TasksRepository() )->create( [
            'template_key'     => InviteToTestTrainingTemplate::KEY,
            'assignee_user_id' => $assignee_user_id,
            'due_at'           => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
            'prospect_id'      => $this->prospect_id,
        ] );
        $this->assertGreaterThan( 0, $task_id, 'the fixture must write a task, or every assertion below is vacuous' );
        return $task_id;
    }

    private function renderTaskFor( int $user_id, int $task_id ): string {
        wp_set_current_user( $user_id );
        ob_start();
        FrontendTaskDetailView::render( $user_id, $task_id );
        return (string) ob_get_clean();
    }
}
