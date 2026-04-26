<?php
namespace TT\Modules\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\TaskTemplateInterface;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\Repositories\TemplateConfigRepository;

/**
 * TaskEngine — the orchestration layer's public API. Templates fire
 * triggers through `dispatch()`; assignee resolution + fan-out + task
 * persistence + per-install override application happen in here.
 *
 * Sprint 1 ships the engine API only — no live dispatchers (cron /
 * event / manual buttons) are wired up yet, so dispatch() in production
 * is invoked only by future sprints' code or tests. The shape is
 * locked so Sprint 2 can wire dispatchers without engine churn.
 */
class TaskEngine {

    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly TasksRepository $tasks,
        private readonly TemplateConfigRepository $configs
    ) {}

    /**
     * Dispatch a trigger for a template. Walks: lookup → enabled-check
     * → expandTrigger → resolveAssignees → create one task per
     * (context, assignee). Returns the IDs of created tasks (empty
     * array if the template is disabled, missing, or resolves to no
     * assignees).
     *
     * Idempotency is the caller's concern: this engine creates a row
     * every time it's called. Cron / event dispatchers in Sprint 2 are
     * responsible for their own dedup (e.g. "session X already had a
     * post-match eval task created").
     *
     * @return int[]
     */
    public function dispatch( string $template_key, TaskContext $context ): array {
        $template = $this->registry->get( $template_key );
        if ( $template === null ) {
            $this->log( sprintf( 'dispatch: unknown template_key %s', $template_key ) );
            return [];
        }

        $config = $this->configs->findByKey( $template_key );
        if ( $config !== null && empty( $config['enabled'] ) ) {
            return [];
        }

        $deadline_offset = $config['deadline_offset_override']
            ?? $template->defaultDeadlineOffset();

        $contexts = $template->expandTrigger( $context );
        $resolver = $template->defaultAssignee();

        $created = [];
        foreach ( $contexts as $task_context ) {
            $user_ids = $resolver->resolve( $task_context );
            if ( empty( $user_ids ) ) {
                $this->log( sprintf(
                    'dispatch: %s resolved to no assignees (context: player=%s team=%s)',
                    $template_key,
                    $task_context->player_id ?? 'null',
                    $task_context->team_id ?? 'null'
                ) );
                continue;
            }
            foreach ( $user_ids as $user_id ) {
                $id = $this->tasks->create( array_merge(
                    $task_context->toEntityLinks(),
                    [
                        'template_key'     => $template_key,
                        'assignee_user_id' => (int) $user_id,
                        'due_at'           => $this->computeDueAt( $deadline_offset ),
                    ]
                ) );
                if ( $id > 0 ) $created[] = $id;
            }
        }

        return $created;
    }

    /**
     * Mark a task completed and run the template's onComplete hook.
     * Returns true on success.
     *
     * @param array<string,mixed> $response
     */
    public function complete( int $task_id, array $response ): bool {
        $task = $this->tasks->find( $task_id );
        if ( $task === null ) return false;

        $ok = $this->tasks->complete( $task_id, $response );
        if ( ! $ok ) return false;

        $template = $this->registry->get( (string) $task['template_key'] );
        if ( $template !== null ) {
            // Re-fetch so the on-complete hook sees the persisted state
            // (status/completed_at/response_json filled).
            $persisted = $this->tasks->find( $task_id );
            if ( is_array( $persisted ) ) {
                $template->onComplete( $persisted, $response );
            }
        }
        return true;
    }

    private function computeDueAt( string $offset ): string {
        $ts = strtotime( $offset, current_time( 'timestamp' ) );
        if ( $ts === false ) {
            // Defensive fallback: 7 days. Templates declare their own
            // offsets via TaskTemplateInterface::defaultDeadlineOffset();
            // a typo (e.g. "+7 dayz") shouldn't break dispatch.
            $ts = current_time( 'timestamp' ) + ( 7 * 86400 );
        }
        return date( 'Y-m-d H:i:s', $ts );
    }

    private function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[TalentTrack workflow] ' . $message );
        }
    }
}
