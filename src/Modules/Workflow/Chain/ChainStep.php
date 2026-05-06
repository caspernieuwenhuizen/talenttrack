<?php
namespace TT\Modules\Workflow\Chain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\TaskContext;

/**
 * ChainStep — declarative spawns_on_complete primitive (#0022 Phase 2).
 *
 * A template's `chainSteps()` returns zero or more ChainSteps describing
 * what to spawn after that template's task is completed. The engine reads
 * the steps after `onComplete()` runs and dispatches each one.
 *
 * Replaces the tactical Phase 1 hack of templates calling
 * `WorkflowModule::engine()->dispatch()` directly inside `onComplete()`.
 * The hack still works (back-compat), but new templates should declare
 * their chains here so the dashboard, audit log, and admin retry surface
 * can see them as first-class structure.
 *
 * `id` is a stable slug unique within the parent template, used as the
 * `spawned_by_step` value on the spawned task so failures and retries
 * can target a specific step.
 *
 * `condition` and `contextBuilder` are nullable callables — when null,
 * the step always spawns and inherits the parent task's context (with
 * `parent_task_id` populated).
 */
final class ChainStep {

    /**
     * @param string $id                      Stable slug (e.g. 'goal_approval')
     * @param string $template_key            Template to dispatch
     * @param null|callable(array<string,mixed>, array<string,mixed>): bool $condition
     *                                        ($task, $response) => spawn?
     * @param null|callable(array<string,mixed>, array<string,mixed>): TaskContext $contextBuilder
     *                                        ($task, $response) => derived context
     * @param string $description             Human-readable label for admin UI
     */
    public function __construct(
        public readonly string $id,
        public readonly string $template_key,
        public readonly ?\Closure $condition = null,
        public readonly ?\Closure $contextBuilder = null,
        public readonly string $description = ''
    ) {}

    /**
     * Default context builder: inherit the parent task's entity links and
     * stamp parent_task_id. Templates with bespoke routing override via
     * the `contextBuilder` argument.
     *
     * @param array<string,mixed> $task
     */
    public static function inheritContext( array $task ): TaskContext {
        return new TaskContext(
            isset( $task['player_id'] )      ? (int) $task['player_id']      : null,
            isset( $task['team_id'] )        ? (int) $task['team_id']        : null,
            isset( $task['activity_id'] )    ? (int) $task['activity_id']    : null,
            isset( $task['evaluation_id'] )  ? (int) $task['evaluation_id']  : null,
            isset( $task['goal_id'] )        ? (int) $task['goal_id']        : null,
            isset( $task['trial_case_id'] )  ? (int) $task['trial_case_id']  : null,
            (int) ( $task['id'] ?? 0 ),
            isset( $task['prospect_id'] )    ? (int) $task['prospect_id']    : null
        );
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $response
     */
    public function shouldSpawn( array $task, array $response ): bool {
        if ( $this->condition === null ) return true;
        return (bool) ( $this->condition )( $task, $response );
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $response
     */
    public function buildContext( array $task, array $response ): TaskContext {
        if ( $this->contextBuilder === null ) return self::inheritContext( $task );
        $built = ( $this->contextBuilder )( $task, $response );
        return $built instanceof TaskContext ? $built : self::inheritContext( $task );
    }
}
