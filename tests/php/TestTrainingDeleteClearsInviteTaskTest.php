<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Prospects\Rest\TestTrainingsRestController;
use TT\Modules\Workflow\Templates\InviteToTestTrainingTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * #3940 — permanently deleting a test training must clear the link on the
 * invite task that arranged it, and keep the task.
 *
 * `CascadeRegistry` declared a `set_null` on `tt_workflow_tasks.test_training_id`,
 * a column no migration had created. The UPDATE failed, the cascade rolled
 * back, and the delete failed with it. Migration 0288 adds the column and
 * backfills it; the invite template writes it on completion.
 *
 * #3986 — the purge case observes the state a real purge leaves, so it cannot
 * read through `preview()`. The cascade's own COMMIT ends the suite's per-test
 * transaction, so this test cleans up after itself and commits that too. See
 * `CommitsAfterCascade`.
 */
final class TestTrainingDeleteClearsInviteTaskTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private int $hod         = 0;
    private int $prospect_id = 0;

    public function set_up(): void {
        parent::set_up();

        // Before a single fixture row exists, so the cleanup knows what this
        // test added.
        $this->markFixtureFloor();

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        WorkflowModule::registerShippedTemplates();

        $this->hod         = $this->makeUser( 'tt_head_dev' );
        $this->prospect_id = $this->seedProspect();

        TestTrainingsRestController::init();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_column_exists(): void {
        global $wpdb;
        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$wpdb->prefix}tt_workflow_tasks LIKE %s",
            'test_training_id'
        ) );
        $this->assertSame( 'test_training_id', $col );
    }

    public function test_arranging_a_test_training_writes_the_link_column_and_the_response(): void {
        $training_id = $this->arrange();
        $task        = $this->inviteTask();

        $this->assertSame( $training_id, (int) $task['test_training_id'], 'the column carries the link from the moment the task completes' );
        $response = json_decode( (string) $task['response_json'], true );
        $this->assertIsArray( $response );
        $this->assertSame( $training_id, (int) $response['test_training_id'], 'the response keeps the key for everything reading it there' );
    }

    public function test_permanently_deleting_the_test_training_clears_the_link_and_keeps_the_task(): void {
        $training_id = $this->arrange();
        $before      = $this->inviteTask();
        $this->assertSame( $training_id, (int) $before['test_training_id'], 'the fixture must link the task, or the delete proves nothing' );

        $deleted = ( new ArchiveRepository() )->deletePermanently( 'test_training', [ $training_id ] );
        $this->assertSame( 1, $deleted, 'the delete must go through, not roll back on a missing column' );

        global $wpdb;
        $gone = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_test_trainings WHERE id = %d",
            $training_id
        ) );
        $this->assertSame( 0, $gone );

        $after = $this->inviteTask();
        $this->assertNotNull( $after, 'the task that arranged it is a fact and outlives it' );
        $this->assertSame( (int) $before['id'], (int) $after['id'] );
        $this->assertNull( $after['test_training_id'], 'no task points at a session that is gone' );
        $this->assertSame( (string) $before['status'], (string) $after['status'] );
        $this->assertSame( (string) $before['prospect_id'], (string) $after['prospect_id'] );
        $this->assertSame( (string) $before['completed_at'], (string) $after['completed_at'] );

        // #3986 — everything above is read after the cascade's COMMIT, which
        // is the point of this case and also what put these fixtures beyond
        // the suite's rollback. Take them out again, for good.
        $this->cleanUpAndCommit();
        $this->assertSame(
            [],
            $this->rowsAboveFixtureFloor(),
            'a purge test must not hand its fixtures to the rest of the suite'
        );
    }

    /**
     * #3986 — the guard on the case above. This runs in its own transaction,
     * so anything the purge test committed is visible here. Before the fix it
     * saw two prospects and a leftover invite task.
     */
    public function test_the_purge_case_left_nothing_for_the_rest_of_the_suite(): void {
        global $wpdb;

        $prospects = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_prospects WHERE first_name = %s",
            'Tycho'
        ) );
        $this->assertSame( 1, $prospects, "only this case's own prospect is in the table" );

        $tasks = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_workflow_tasks WHERE template_key = %s",
            InviteToTestTrainingTemplate::KEY
        ) );
        $this->assertSame( 0, $tasks, 'no invite task outlived the case that arranged one' );

        $trainings = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_test_trainings WHERE date LIKE %s",
            '2026-11-14%'
        ) );
        $this->assertSame( 0, $trainings );
    }

    public function test_the_migration_backfills_completed_invites_and_leaves_dangling_ones_null(): void {
        $live     = $this->seedTestTraining();
        $linked   = $this->seedCompletedInvite( (string) wp_json_encode( [ 'test_training_id' => $live ] ) );
        $dangling = $this->seedCompletedInvite( (string) wp_json_encode( [ 'test_training_id' => 987654 ] ) );
        $garbled  = $this->seedCompletedInvite( '{not json' );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0288_workflow_tasks_test_training_id.php';
        $migration->up();

        $this->assertSame( (string) $live, (string) $this->column( $linked ) );
        $this->assertNull( $this->column( $dangling ), 'a response naming a session that is gone is not turned into a link' );
        $this->assertNull( $this->column( $garbled ), 'one malformed response is skipped, not fatal' );

        // Idempotent: a second run changes nothing and does not throw.
        $migration->up();
        $this->assertSame( (string) $live, (string) $this->column( $linked ) );
    }

    // Fixtures

    private function arrange(): int {
        wp_set_current_user( $this->hod );
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/test-trainings' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( [
            'date'        => '2026-11-14',
            'location'    => 'Hoofdveld',
            'prospect_id' => $this->prospect_id,
        ] ) );
        $res = rest_get_server()->dispatch( $req );

        $this->assertSame( 200, $res->get_status(), 'the fixture must write, or nothing below means anything' );
        $data = $res->get_data();
        $id   = (int) ( $data['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $id );
        $this->assertNotNull( $this->inviteTask(), 'arranging with a prospect completes an invite task' );
        return $id;
    }

    private function makeUser( string $wp_role ): int {
        if ( get_role( $wp_role ) === null ) {
            add_role( $wp_role, $wp_role, [ 'read' => true ] );
        }
        $uid = self::factory()->user->create( [ 'role' => $wp_role ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function seedProspect(): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'          => 1,
            'first_name'       => 'Tycho',
            'last_name'        => 'Prospect',
            'consent_given_at' => '2026-09-01 00:00:00',
        ] );
        $this->assertNotFalse( $ok, 'prospect insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    private function seedTestTraining(): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_test_trainings", [
            'club_id'       => 1,
            'date'          => '2026-11-14 18:00:00',
            'coach_user_id' => $this->hod,
            'created_by'    => $this->hod,
        ] );
        $this->assertNotFalse( $ok, 'test training insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    private function seedCompletedInvite( string $response_json ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_workflow_tasks", [
            'club_id'          => 1,
            'template_key'     => InviteToTestTrainingTemplate::KEY,
            'assignee_user_id' => $this->hod,
            'status'           => 'completed',
            'due_at'           => '2026-11-01 00:00:00',
            'completed_at'     => '2026-11-02 00:00:00',
            'prospect_id'      => $this->prospect_id,
            'response_json'    => $response_json,
        ] );
        $this->assertNotFalse( $ok, 'task insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    private function column( int $task_id ): ?string {
        global $wpdb;
        $v = $wpdb->get_var( $wpdb->prepare(
            "SELECT test_training_id FROM {$wpdb->prefix}tt_workflow_tasks WHERE id = %d",
            $task_id
        ) );
        return $v === null ? null : (string) $v;
    }

    /** @return array<string,mixed>|null */
    private function inviteTask(): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_workflow_tasks
              WHERE prospect_id = %d AND template_key = %s ORDER BY id ASC LIMIT 1",
            $this->prospect_id,
            InviteToTestTrainingTemplate::KEY
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }
}
