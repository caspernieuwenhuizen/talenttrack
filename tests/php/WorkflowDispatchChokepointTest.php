<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\GoalApprovalForm;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\Repositories\TemplateConfigRepository;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskEngine;
use TT\Modules\Workflow\TaskStatus;
use TT\Modules\Workflow\TaskSupersede;
use TT\Modules\Workflow\TaskTemplate;
use TT\Modules\Workflow\TemplateRegistry;

/**
 * #4287 — the workflow engine takes a per-record deadline from the template,
 * dispatches idempotently when the template opts in, and supersedes a
 * player's active tasks when their date moves.
 */
final class WorkflowDispatchChokepointTest extends WP_UnitTestCase {

    private const DATED  = 'test_chokepoint_dated';
    private const PLAIN  = 'test_chokepoint_plain';

    private TemplateRegistry $registry;
    private TasksRepository $tasks;
    private TaskEngine $engine;
    private int $coach = 0;

    public function set_up(): void {
        parent::set_up();
        $this->coach    = (int) self::factory()->user->create();
        $this->registry = new TemplateRegistry();
        $this->tasks    = new TasksRepository();
        $this->engine   = new TaskEngine( $this->registry, $this->tasks, new TemplateConfigRepository() );

        $coach = $this->coach;
        $this->registry->register( $this->template( self::DATED, true, $coach ) );
        $this->registry->register( $this->template( self::PLAIN, false, $coach ) );
    }

    public function test_a_template_deadline_becomes_the_due_date(): void {
        $ids = $this->engine->dispatch( self::DATED, $this->context( 9101, '2026-11-20' ) );

        $this->assertCount( 1, $ids );
        $this->assertSame( '2026-11-20 00:00:00', (string) $this->tasks->find( $ids[0] )['due_at'] );
    }

    public function test_a_template_without_a_deadline_keeps_the_offset(): void {
        $before = current_time( 'timestamp' );
        $ids    = $this->engine->dispatch( self::PLAIN, $this->context( 9102, '2026-11-20' ) );

        $this->assertCount( 1, $ids );
        $due = strtotime( (string) $this->tasks->find( $ids[0] )['due_at'] );
        $this->assertGreaterThanOrEqual( $before + 3 * 86400 - 5, $due );
        $this->assertLessThanOrEqual( current_time( 'timestamp' ) + 3 * 86400 + 5, $due );
    }

    public function test_dispatching_the_same_key_twice_creates_one_task(): void {
        $first  = $this->engine->dispatch( self::DATED, $this->context( 9103, '2026-11-20' ) );
        $second = $this->engine->dispatch( self::DATED, $this->context( 9103, '2026-11-20' ) );

        $this->assertCount( 1, $first );
        $this->assertSame( [], $second );
        $this->assertCount( 1, $this->tasks->listActiveForPlayer( self::DATED, 9103 ) );
    }

    public function test_a_new_date_for_the_same_player_is_a_new_task(): void {
        $this->engine->dispatch( self::DATED, $this->context( 9104, '2026-11-20' ) );
        $moved = $this->engine->dispatch( self::DATED, $this->context( 9104, '2026-12-04' ) );

        $this->assertCount( 1, $moved );
    }

    public function test_a_template_that_does_not_opt_in_still_creates_every_time(): void {
        $this->engine->dispatch( self::PLAIN, $this->context( 9105, '' ) );
        $this->engine->dispatch( self::PLAIN, $this->context( 9105, '' ) );

        $this->assertCount( 2, $this->tasks->listActiveForPlayer( self::PLAIN, 9105 ) );
    }

    public function test_supersede_skips_one_players_active_tasks_and_leaves_others_alone(): void {
        $old   = $this->engine->dispatch( self::DATED, $this->context( 9106, '2026-11-20' ) );
        $other = $this->engine->dispatch( self::DATED, $this->context( 9107, '2026-11-20' ) );

        $skipped = ( new TaskSupersede( $this->tasks ) )->forPlayer( self::DATED, 9106 );

        $this->assertSame( 1, $skipped );
        $this->assertSame( TaskStatus::SKIPPED, (string) $this->tasks->find( $old[0] )['status'] );
        $this->assertSame( TaskStatus::OPEN, (string) $this->tasks->find( $other[0] )['status'] );
    }

    public function test_supersede_keeps_the_task_on_the_current_date(): void {
        $old  = $this->engine->dispatch( self::DATED, $this->context( 9108, '2026-11-20' ) );
        $kept = $this->engine->dispatch( self::DATED, $this->context( 9108, '2026-12-04' ) );

        $skipped = ( new TaskSupersede( $this->tasks ) )->forPlayer( self::DATED, 9108, '2026-12-04' );

        $this->assertSame( 1, $skipped );
        $this->assertSame( TaskStatus::SKIPPED, (string) $this->tasks->find( $old[0] )['status'] );
        $this->assertSame( TaskStatus::OPEN, (string) $this->tasks->find( $kept[0] )['status'] );
    }

    /** #4358 — a superseded (skipped) task frees its key. */
    public function test_a_skipped_task_does_not_block_a_new_one_on_the_same_key(): void {
        $first = $this->engine->dispatch( self::DATED, $this->context( 9111, '2026-11-20' ) );
        ( new TaskSupersede( $this->tasks ) )->forPlayer( self::DATED, 9111 );

        $again = $this->engine->dispatch( self::DATED, $this->context( 9111, '2026-11-20' ) );

        $this->assertCount( 1, $again );
        $this->assertNotSame( $first[0], $again[0] );
        $this->assertSame( TaskStatus::OPEN, (string) $this->tasks->find( $again[0] )['status'] );
    }

    /** #4358 — an answered task stays answered: completed still blocks. */
    public function test_a_completed_task_still_blocks_its_key(): void {
        $first = $this->engine->dispatch( self::DATED, $this->context( 9112, '2026-11-20' ) );
        $this->tasks->complete( $first[0], [] );

        $this->assertSame( [], $this->engine->dispatch( self::DATED, $this->context( 9112, '2026-11-20' ) ) );
    }

    /** #4358 — a cancelled task frees its key, like a skipped one. */
    public function test_a_cancelled_task_does_not_block_a_new_one(): void {
        $first = $this->engine->dispatch( self::DATED, $this->context( 9113, '2026-11-20' ) );
        $this->tasks->update( $first[0], [ 'status' => TaskStatus::CANCELLED ] );

        $this->assertCount( 1, $this->engine->dispatch( self::DATED, $this->context( 9113, '2026-11-20' ) ) );
    }

    public function test_the_created_action_fires_on_the_dated_path(): void {
        $before = did_action( 'tt_workflow_task_created' );
        $this->engine->dispatch( self::DATED, $this->context( 9109, '2026-11-20' ) );
        $this->engine->dispatch( self::DATED, $this->context( 9109, '2026-11-20' ) );

        $this->assertSame( $before + 1, did_action( 'tt_workflow_task_created' ), 'once for the task, not for the deduplicated repeat' );
    }

    public function test_a_disabled_template_dispatches_nothing_on_the_dated_path(): void {
        ( new TemplateConfigRepository() )->upsert( self::DATED, [ 'enabled' => false ] );

        $this->assertSame( [], $this->engine->dispatch( self::DATED, $this->context( 9110, '2026-11-20' ) ) );
    }

    private function context( int $player_id, string $date ): TaskContext {
        return new TaskContext( $player_id, null, null, null, null, null, null, null, [ 'date' => $date ] );
    }

    private function template( string $key, bool $dated, int $assignee ): TaskTemplate {
        return new class( $key, $dated, $assignee ) extends TaskTemplate {
            public function __construct( private string $k, private bool $dated, private int $assignee ) {}
            public function key(): string { return $this->k; }
            public function name(): string { return $this->k; }
            public function description(): string { return $this->k; }
            public function defaultDeadlineOffset(): string { return '+3 days'; }
            public function defaultAssignee(): AssigneeResolver {
                $uid = $this->assignee;
                return new LambdaResolver( static fn( TaskContext $c ): array => [ $uid ] );
            }
            public function formClass(): string { return GoalApprovalForm::class; }
            public function entityLinks(): array { return [ 'player_id' ]; }
            public function deadlineFor( TaskContext $context ): ?string {
                return $this->dated ? (string) ( $context->extras['date'] ?? '' ) : null;
            }
            public function idempotentDispatch(): bool { return $this->dated; }
        };
    }
}
