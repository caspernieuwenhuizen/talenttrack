<?php
namespace TT\Modules\Workflow\Dispatchers;

if ( ! defined( 'ABSPATH' ) ) exit;

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
            add_action( $hook, function ( ...$args ) use ( $template_key ) {
                self::handle( $template_key, $args );
            }, 10, 4 );
        }
    }

    /**
     * Translate event arguments into a TaskContext and dispatch.
     *
     * Convention for event arguments: the first argument may be a
     * TaskContext (cleanest), or an associative array of (player_id,
     * team_id, activity_id, evaluation_id, goal_id, trial_case_id) keys.
     * Anything else gets wrapped as `extras` on an empty context.
     *
     * @param mixed[] $args
     */
    private static function handle( string $template_key, array $args ): void {
        $context = self::contextFromArgs( $args );
        WorkflowModule::engine()->dispatch( $template_key, $context );
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
