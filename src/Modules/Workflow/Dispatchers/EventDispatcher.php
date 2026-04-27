<?php
namespace TT\Modules\Workflow\Dispatchers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Repositories\EventLogRepository;
use TT\Modules\Workflow\Repositories\TriggersRepository;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\WorkflowModule;

/**
 * EventDispatcher — wires event-typed rows in tt_workflow_triggers to
 * WordPress action hooks. On init we walk enabled event triggers and
 * register an `add_action( $event_hook, ... )` for each, calling the
 * engine when the event fires.
 *
 * Sprint 2 ships the wiring; the post-match evaluation template (which
 * subscribes to `tt_activity_completed`) is added in Sprint 3 but the
 * actual `do_action('tt_activity_completed', ...)` lives in
 * ActivitiesModule and is *not* wired in Sprint 3 (would conflict with
 * the parallel #0026 branch). The hook will be added by whoever lands
 * #0026 + the event-fire change together post-merge — until then,
 * post-match tasks fire via manual or polling.
 */
class EventDispatcher {

    public static function init(): void {
        add_action( 'init', [ self::class, 'registerEventHooks' ], 20 );
    }

    public static function registerEventHooks(): void {
        $triggers = ( new TriggersRepository() )->listEnabledByType( 'event' );
        foreach ( $triggers as $trigger ) {
            $hook = (string) ( $trigger['event_hook'] ?? '' );
            $template_key = (string) ( $trigger['template_key'] ?? '' );
            if ( $hook === '' || $template_key === '' ) continue;
            add_action( $hook, function ( ...$args ) use ( $template_key, $hook ) {
                self::handle( $template_key, $args, $hook );
            }, 10, 4 );
        }
    }

    /**
     * Translate event arguments into a TaskContext and dispatch. Phase 3
     * adds event-log writes around the dispatch so a failed dispatch
     * (template threw, DB write rejected, etc.) lands in the log as
     * `failed` and can be replayed via `replay()`.
     *
     * @param mixed[] $args
     */
    private static function handle( string $template_key, array $args, string $event_hook ): void {
        $log = new EventLogRepository();
        $log_id = $log->recordFiring( $event_hook, $template_key, $args );
        try {
            $context = self::contextFromArgs( $args );
            $task_ids = WorkflowModule::engine()->dispatch( $template_key, $context );
            if ( $log_id > 0 ) $log->markProcessed( $log_id, $task_ids );
        } catch ( \Throwable $e ) {
            if ( $log_id > 0 ) {
                $log->markFailed( $log_id, $e->getMessage() );
            }
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[TalentTrack workflow] event dispatch failed: ' . $e->getMessage() );
            }
        }
    }

    /**
     * Replay a previously-failed event log row. Increments retries and
     * runs the dispatch path again. Returns the task IDs created on
     * success, or an empty array on failure (the log row's status is
     * updated either way).
     *
     * @return int[]
     */
    public static function replay( int $log_id ): array {
        $log = new EventLogRepository();
        $row = $log->find( $log_id );
        if ( ! $row ) return [];

        $log->incrementRetries( $log_id );
        try {
            $args = EventLogRepository::decodeArgs( (string) ( $row['args_json'] ?? '' ) );
            $context = self::contextFromArgs( $args );
            $task_ids = WorkflowModule::engine()->dispatch( (string) $row['template_key'], $context );
            $log->markProcessed( $log_id, $task_ids );
            return $task_ids;
        } catch ( \Throwable $e ) {
            $log->markFailed( $log_id, $e->getMessage() );
            return [];
        }
    }

    /** @param mixed[] $args */
    private static function contextFromArgs( array $args ): TaskContext {
        if ( empty( $args ) ) return new TaskContext();
        $first = $args[0];
        if ( $first instanceof TaskContext ) return $first;
        if ( is_array( $first ) ) {
            return new TaskContext(
                isset( $first['player_id'] ) ? (int) $first['player_id'] : null,
                isset( $first['team_id'] ) ? (int) $first['team_id'] : null,
                isset( $first['activity_id'] ) ? (int) $first['activity_id'] : null,
                isset( $first['evaluation_id'] ) ? (int) $first['evaluation_id'] : null,
                isset( $first['goal_id'] ) ? (int) $first['goal_id'] : null,
                isset( $first['trial_case_id'] ) ? (int) $first['trial_case_id'] : null,
                isset( $first['parent_task_id'] ) ? (int) $first['parent_task_id'] : null,
                isset( $first['extras'] ) && is_array( $first['extras'] ) ? $first['extras'] : []
            );
        }
        return new TaskContext( null, null, null, null, null, null, null, [ 'event_args' => $args ] );
    }
}
