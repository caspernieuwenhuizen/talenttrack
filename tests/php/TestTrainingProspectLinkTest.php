<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Prospects\Domain\ArrangeTestTrainingService;
use TT\Modules\Prospects\Rest\TestTrainingsRestController;
use TT\Modules\Workflow\Templates\InviteToTestTrainingTemplate;
use TT\Modules\Workflow\WorkflowModule;
use TT\Shared\Frontend\FrontendTestTrainingsView;

/**
 * #3932 — the head of development's route into inviting a prospect opened
 * a form with no prospect on it.
 *
 * #3710 gave a `tt_invite_prospects` holder a deep link from the pipeline
 * card straight to the New test training form, on the reasoning that
 * somebody who may issue the invitation does not address a task to
 * themselves. The form had no prospect field, so the session they created
 * was linked to nobody and the child stayed where they were.
 *
 * The link is not a column: a prospect reaches a test training through a
 * completed `invite_to_test_training` task carrying the prospect id and,
 * in its response, the session id. These pin that the direct route writes
 * that same record rather than a second shape of it.
 */
final class TestTrainingProspectLinkTest extends WP_UnitTestCase {

    private int $prospect_id = 0;
    private int $hod         = 0;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        WorkflowModule::registerShippedTemplates();

        $this->hod         = $this->makeUser( 'tt_head_dev' );
        $this->prospect_id = $this->seedProspect( true );

        TestTrainingsRestController::init();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        $_GET = [];
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the form ───────────────────────────────────────────────────────

    public function test_the_deep_link_opens_the_form_with_that_prospect_selected(): void {
        $html = $this->renderForm( $this->hod, $this->prospect_id );

        $this->assertStringContainsString( 'name="prospect_id"', $html );
        $this->assertMatchesRegularExpression(
            '/<option value="' . $this->prospect_id . '"[^>]*selected/',
            $html,
            'arriving from a prospect card must preselect that child'
        );
    }

    public function test_arriving_with_no_prospect_leaves_the_field_empty(): void {
        $html = $this->renderForm( $this->hod, 0 );

        $this->assertStringContainsString( 'name="prospect_id"', $html, 'the picker is still offered' );
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="\d+"[^>]*selected/',
            $html,
            'scheduling an open session is an ordinary thing to do'
        );
    }

    /**
     * An id that does not resolve leaves the field empty and says nothing
     * about whether it exists — the two answers a viewer may not have are
     * "no such prospect" and "not yours", and telling them apart is a leak.
     */
    public function test_an_unreadable_prospect_id_leaves_the_field_empty_without_erroring(): void {
        $html = $this->renderForm( $this->hod, 999999 );

        $this->assertStringContainsString( 'name="prospect_id"', $html );
        $this->assertDoesNotMatchRegularExpression( '/<option value="\d+"[^>]*selected/', $html );
        $this->assertStringNotContainsString( 'value="999999"', $html, 'the id is not echoed back as an option' );
    }

    // ── the save ───────────────────────────────────────────────────────

    public function test_saving_with_a_prospect_links_the_training_the_way_the_task_path_does(): void {
        $res = $this->postTestTraining( $this->hod, [
            'date'        => '2026-11-14',
            'location'    => 'Hoofdveld',
            'prospect_id' => $this->prospect_id,
        ] );

        $this->assertSame( 200, $res->get_status(), 'the fixture must write, or nothing below means anything' );
        $data = $res->get_data();
        $this->assertNotEmpty( $data['data']['id'] ?? 0 );
        $training_id = (int) $data['data']['id'];

        $task = $this->inviteTaskFor( $this->prospect_id );
        $this->assertIsArray( $task, 'the link is a completed invite task, not a column' );
        $this->assertSame( 'completed', (string) $task['status'] );

        $response = json_decode( (string) $task['response_json'], true );
        $this->assertIsArray( $response );
        $this->assertSame(
            $training_id,
            (int) $response['test_training_id'],
            'the same response shape the workflow form writes'
        );
    }

    /** An open invite task is reused, not doubled: one child, one invitation. */
    public function test_an_open_invite_task_is_completed_rather_than_duplicated(): void {
        // Written straight through the repository rather than dispatched,
        // so the assertion does not quietly depend on the pipeline feature
        // being switched on in the test install.
        $existing = ( new \TT\Modules\Workflow\Repositories\TasksRepository() )->create( [
            'template_key'     => InviteToTestTrainingTemplate::KEY,
            'assignee_user_id' => $this->hod,
            'due_at'           => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
            'prospect_id'      => $this->prospect_id,
        ] );
        $this->assertGreaterThan( 0, $existing, 'the fixture must write the task it is about' );

        $res = $this->postTestTraining( $this->hod, [ 'date' => '2026-11-14', 'prospect_id' => $this->prospect_id ] );
        $this->assertSame( 200, $res->get_status() );

        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_workflow_tasks
              WHERE prospect_id = %d AND template_key = %s",
            $this->prospect_id,
            InviteToTestTrainingTemplate::KEY
        ) );
        $this->assertSame( 1, $count );
        $this->assertSame( $existing, (int) $this->inviteTaskFor( $this->prospect_id )['id'] );
    }

    public function test_saving_without_a_prospect_creates_an_unattached_session(): void {
        $res = $this->postTestTraining( $this->hod, [ 'date' => '2026-11-14' ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertNull( $this->inviteTaskFor( $this->prospect_id ) );
    }

    // ── and what it refuses ────────────────────────────────────────────

    /** #3869's capability decides who may take this route at all. */
    public function test_a_caller_without_the_invite_capability_cannot_attach_a_prospect(): void {
        $scout = $this->makeUser( 'tt_scout' );
        $this->assertFalse( AuthorizationService::userCanOrMatrix( $scout, 'tt_invite_prospects' ), 'precondition' );

        $before = $this->testTrainingCount();
        $res    = $this->postTestTraining( $scout, [ 'date' => '2026-11-14', 'prospect_id' => $this->prospect_id ] );

        $this->assertSame( 403, $res->get_status() );
        $this->assertSame( $before, $this->testTrainingCount(), 'refused before the row is written, not after' );
        $this->assertNull( $this->inviteTaskFor( $this->prospect_id ) );
    }

    /** The picker only offers what the caller could actually act on. */
    public function test_the_picker_is_empty_for_somebody_without_the_capability(): void {
        $this->assertSame( [], ArrangeTestTrainingService::pickableFor( $this->makeUser( 'tt_scout' ) ) );
    }

    /** #3812's consent block holds on this route too — same seam, same answer. */
    public function test_a_prospect_without_consent_cannot_be_attached(): void {
        $no_consent = $this->seedProspect( false );

        $before = $this->testTrainingCount();
        $res    = $this->postTestTraining( $this->hod, [ 'date' => '2026-11-14', 'prospect_id' => $no_consent ] );

        $this->assertSame( 409, $res->get_status() );
        $this->assertSame( $before, $this->testTrainingCount() );
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

    private function seedProspect( bool $with_consent ): int {
        global $wpdb;
        $row = [
            'club_id'    => 1,
            'first_name' => 'Tycho',
            'last_name'  => 'Prospect',
        ];
        if ( $with_consent ) $row['consent_given_at'] = '2026-09-01 00:00:00';
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_prospects", $row );
        $this->assertNotFalse( $ok, 'prospect insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    private function renderForm( int $user_id, int $prospect_id ): string {
        wp_set_current_user( $user_id );
        $_GET = [ 'tt_view' => 'test-trainings', 'action' => 'new' ];
        if ( $prospect_id > 0 ) $_GET['prospect_id'] = (string) $prospect_id;

        ob_start();
        FrontendTestTrainingsView::render( $user_id, false );
        $html = (string) ob_get_clean();
        $_GET = [];
        return $html;
    }

    /** @param array<string,mixed> $body */
    private function postTestTraining( int $user_id, array $body ): \WP_REST_Response {
        wp_set_current_user( $user_id );
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/test-trainings' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( $body ) );
        return rest_get_server()->dispatch( $req );
    }

    /** @return array<string,mixed>|null */
    private function inviteTaskFor( int $prospect_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_workflow_tasks
              WHERE prospect_id = %d AND template_key = %s ORDER BY id ASC LIMIT 1",
            $prospect_id,
            InviteToTestTrainingTemplate::KEY
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    private function testTrainingCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_test_trainings" );
    }
}
