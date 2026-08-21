<?php
namespace TT\Modules\Training\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Training\Services\PlayerExposureAggregator;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\GoalSettingForm;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * PlayerExposureAggregationTaskTemplate (#2500, epic #2493).
 *
 * The nightly rebuild of `tt_player_principle_exposure`: how many minutes
 * each player has spent training each principle.
 *
 * A server-side job, not a user-facing task. It goes through the Workflow
 * module's `TaskTemplate` contract rather than `wp_schedule_event`
 * because CLAUDE.md §4 makes the scheduler a single replaceable
 * chokepoint for the SaaS port — one abstraction to swap, not fifty cron
 * registrations. `expandTrigger()` does the work and returns an empty
 * array, so the engine creates zero tasks.
 *
 * Cadence: 03:00 daily, an hour after the VCT workload job, so the two
 * heavy nightly passes do not contend. The matching `tt_workflow_triggers`
 * row lands in migration 0223.
 *
 * ## Why nightly *and* on run completion
 *
 * Decision D17: a coach who finishes a session and opens the player file
 * has to see it there, so `TrainingRunsRestController` also calls
 * `rebuildForRun()` when a run completes. The two paths must agree, which
 * is why both call the same aggregator rather than each doing their own
 * arithmetic — the incremental one only narrows *which players* are
 * recomputed, never what is counted.
 *
 * The nightly pass therefore exists to catch what the incremental one
 * cannot: a run edited after completion, an exercise re-tagged with a
 * different principle, attendance corrected the next morning. It is a
 * full rebuild rather than a delta so that it is also the repair
 * mechanism — a missed night fixes itself the following night.
 */
class PlayerExposureAggregationTaskTemplate extends TaskTemplate {

    public const KEY = 'training_exposure_aggregation';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Player training exposure aggregation', 'talenttrack' );
    }

    public function description(): string {
        return __(
            'Nightly job that rebuilds how many minutes each player has trained each principle, from completed training runs and the attendance recorded against them. Creates no tasks.',
            'talenttrack'
        );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'cron', 'expression' => '0 3 * * *' ];
    }

    public function defaultDeadlineOffset(): string {
        // No tasks are created, so no deadline is ever consulted.
        return '+1 day';
    }

    public function defaultAssignee(): AssigneeResolver {
        // Never called — expandTrigger returns an empty list. Stating it
        // as an empty resolver keeps the contract explicit rather than
        // leaving a null to trip over.
        return new LambdaResolver( static function ( TaskContext $ctx ): array {
            return [];
        } );
    }

    public function formClass(): string {
        // Never rendered: no tasks means no form. Referencing an existing
        // implementation rather than inventing a stub, as the VCT
        // aggregation template does.
        return GoalSettingForm::class;
    }

    public function entityLinks(): array { return []; }

    /**
     * The work. Returns [] so the engine's task-create loop is a no-op.
     *
     * @return list<TaskContext>
     */
    public function expandTrigger( TaskContext $context ): array {
        ( new PlayerExposureAggregator() )->rebuildAll();

        return [];
    }
}
