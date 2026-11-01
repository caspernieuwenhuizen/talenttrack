<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\WorkflowModule;

/**
 * #4497 — "My tasks due this week": the viewer's own actionable tasks (the
 * set My tasks lists: open, in progress or overdue, not snoozed, from
 * `TasksRepository::listActionableForUser()`) with a due date inside the
 * panel's window. The viewer's own tasks, so no team or player check
 * applies. Links to My tasks.
 */
final class TasksDueBlock extends FollowUpBlock {

    public function key(): string { return 'tasks'; }

    public function label(): string { return __( 'My tasks due this week', 'talenttrack' ); }

    public function help(): string { return __( 'Your own open tasks with a due date in the window. Opens My tasks.', 'talenttrack' ); }

    protected function defaults(): array {
        return [
            'head_coach'    => [ true, false ],
            'team_manager'  => [ true, true ],
            'academy_admin' => [ true, true ],
        ];
    }

    public function available(): bool {
        return ModuleRegistry::isEnabled( WorkflowModule::class );
    }

    public function panelDefaults(): array { return [ 'tasks_due' => null ]; }

    public function panelFields( WeekContext $week ): array {
        $uid = $week->userId;
        if ( ! user_can( $uid, 'tt_view_own_tasks' ) || ! self::surfaceOpen( 'my-tasks', $uid ) ) return [];

        $tasks = $week->once( 'tasks_actionable', static fn(): array => ( new TasksRepository() )->listActionableForUser( $uid ) );
        $count = 0;
        foreach ( $tasks as $task ) {
            $due = substr( (string) ( $task['due_at'] ?? '' ), 0, 10 );
            if ( $due !== '' && $due >= $week->start && $due <= $week->end ) $count++;
        }
        return $count > 0 ? [ 'tasks_due' => [ 'count' => $count ] ] : [];
    }
}
