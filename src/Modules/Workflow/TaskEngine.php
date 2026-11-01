<?php
namespace TT\Modules\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\AfterCommit;
use TT\Modules\Workflow\Chain\ChainStep;
use TT\Modules\Workflow\Contracts\CompletionStepInterface;
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

    /**
     * The statuses that hold a natural key for idempotent dispatch: every one
     * but skipped and cancelled.
     */
    private const DEDUPE_STATUSES = [
        TaskStatus::OPEN,
        TaskStatus::IN_PROGRESS,
        TaskStatus::OVERDUE,
        TaskStatus::COMPLETED,
    ];

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
     * A template's `deadlineFor()` date wins over the deadline offset.
     * A template that opts into `idempotentDispatch()` gets no second task
     * for the same template, assignee, player and `due_at`; for every
     * other template dedup stays the caller's concern.
     *
     * @param string|null $spawned_by_step Phase 2 chain primitive — the
     *     parent template's step id when this dispatch comes from a
     *     chain step. Persisted onto the spawned task for retry +
     *     dashboard provenance.
     * @return int[]
     */
    public function dispatch( string $template_key, TaskContext $context, ?string $spawned_by_step = null ): array {
        $template = $this->registry->get( $template_key );
        if ( $template === null ) {
            $this->log( sprintf( 'dispatch: unknown template_key %s', $template_key ) );
            return [];
        }

        $config = $this->configs->findByKey( $template_key );
        if ( $config !== null && empty( $config['enabled'] ) ) {
            return [];
        }

        // #1644 — a template may be gated by a FeatureRegistry flag (e.g.
        // the onboarding-pipeline templates). When the feature is off the
        // template never dispatches; existing tasks and views are untouched.
        $feature_key = $template->featureKey();
        if ( $feature_key !== null && ! \TT\Core\FeatureRegistry::isEnabled( $feature_key ) ) {
            return [];
        }

        $deadline_offset = $config['deadline_offset_override']
            ?? $template->defaultDeadlineOffset();

        $contexts   = $template->expandTrigger( $context );
        $resolver   = $template->defaultAssignee();
        $idempotent = $template->idempotentDispatch();

        $created = [];
        foreach ( $contexts as $task_context ) {
            $due_at = self::normalizeDueAt( (string) $template->deadlineFor( $task_context ) )
                ?? $this->computeDueAt( $deadline_offset );
            $player_id = (int) ( $task_context->player_id ?? 0 );

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
                // #4358 — a skipped or cancelled task frees its key, so a date
                // moved away and back gets its reminder again; a completed one
                // keeps blocking, so an answered reminder stays answered.
                if ( $idempotent && $player_id > 0
                    && $this->tasks->findByNaturalKey( $template_key, (int) $user_id, $player_id, $due_at, self::DEDUPE_STATUSES ) !== null
                ) {
                    continue;
                }
                $row = array_merge(
                    $task_context->toEntityLinks(),
                    [
                        'template_key'     => $template_key,
                        'assignee_user_id' => (int) $user_id,
                        'due_at'           => $due_at,
                    ]
                );
                if ( $spawned_by_step !== null && $spawned_by_step !== '' ) {
                    $row['spawned_by_step'] = $spawned_by_step;
                }
                $id = $this->tasks->create( $row );
                if ( $id > 0 ) {
                    $created[] = $id;
                    /**
                     * Fires after a workflow task is persisted. Listeners
                     * (TaskMailer in Sprint 2; future notification bell
                     * pushers, audit-log entries, etc.) subscribe here
                     * rather than touching dispatch() directly.
                     *
                     * @param int    $task_id
                     * @param string $template_key
                     * @param int    $assignee_user_id
                     */
                    do_action( 'tt_workflow_task_created', $id, $template_key, (int) $user_id );
                }
            }
        }

        return $created;
    }

    /**
     * Mark a task completed and run the template's onComplete hook.
     * Returns true only when this call completed the task.
     *
     * #4560 — a task that is already completed is left alone and the answer
     * is false, for every caller: completing it again would overwrite the
     * stored answer and run the template's hook and chain a second time. The
     * repository's UPDATE carries the status test, so of two racing submits
     * exactly one gets through.
     *
     * `$step` is the form's own writes (#4550). It runs in one transaction
     * with the completing UPDATE, so either the task is completed with its
     * records or neither happened and the task is still open to try again.
     * The template's hook and chain run after the commit. Without a step the
     * UPDATE is the only write and needs no transaction.
     *
     * @param array<string,mixed> $response
     * @param array<string,mixed> $raw      The validated input `$step` receives.
     */
    public function complete( int $task_id, array $response, ?CompletionStepInterface $step = null, array $raw = [] ): bool {
        $task = $this->tasks->find( $task_id );
        if ( $task === null ) return false;

        if ( $step === null ) {
            if ( ! $this->tasks->complete( $task_id, $response ) ) return false;
        } else {
            $written = $this->completeWithStep( $task_id, $response, $step, $raw );
            if ( $written === null ) return false;
            $response = $written;
        }

        $template = $this->registry->get( (string) $task['template_key'] );
        if ( $template !== null ) {
            // Re-fetch so the on-complete hook sees the persisted state
            // (status/completed_at/response_json filled).
            $persisted = $this->tasks->find( $task_id );
            if ( is_array( $persisted ) ) {
                $template->onComplete( $persisted, $response );
                $this->runChainSteps( $template, $persisted, $response );
                /**
                 * Fires after a workflow task is completed and the
                 * template's onComplete() hook + chain steps have run.
                 * The audit log subscribes here per the spec's "task
                 * history lives in audit log" decision.
                 *
                 * @param array<string,mixed> $task     Persisted row.
                 * @param array<string,mixed> $response Form response payload.
                 */
                do_action( 'tt_workflow_task_completed', $persisted, $response );
            }
        }
        return true;
    }

    /**
     * The completing UPDATE and the form's writes, committed together. The
     * response with the ids of what was written, or null when the task was
     * not completed: already completed, or rolled back after a failure.
     *
     * Known gap (#4561): MySQL has no nested transactions, and a
     * `START TRANSACTION` inside the step commits this one. Archiving a
     * player opens its own (`ArchiveRepository::archive()`), and a group
     * review's `decline_final` reaches it through
     * `TrialDecisionPlayerStatusSubscriber`. On that path what was written
     * before the archive is committed early, and a later failure cannot
     * roll it back.
     *
     * @param array<string,mixed> $response
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    private function completeWithStep( int $task_id, array $response, CompletionStepInterface $step, array $raw ): ?array {
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        // Messages the step's writes trigger wait for the commit, so a
        // rollback announces nothing.
        AfterCommit::open();
        $written = $response;
        try {
            if ( ! $this->tasks->complete( $task_id, $response ) ) {
                throw new CompletionStepFailed( 'The task was not completed by this call.' );
            }
            $completed = $this->tasks->find( $task_id );
            if ( ! is_array( $completed ) ) {
                throw new CompletionStepFailed( 'The completed task could not be read back.' );
            }
            $written = $step->onCompleted( $completed, $raw, $response );
            if ( $written !== $response
                && ! $this->tasks->update( $task_id, [ 'response_json' => (string) wp_json_encode( $written ) ] ) ) {
                throw new CompletionStepFailed( 'The ids of what was written could not be stored.' );
            }
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            AfterCommit::discard();
            $this->log( 'Completing task ' . $task_id . ' was rolled back: ' . $e->getMessage() );
            return null;
        }

        $wpdb->query( 'COMMIT' );
        AfterCommit::commit();
        return $written;
    }

    /**
     * Walk a template's `chainSteps()` and dispatch each step whose
     * condition holds. Each spawned task gets the parent's `id` as
     * `parent_task_id` (via the default ChainStep::inheritContext) and
     * the step's `id` as `spawned_by_step`.
     *
     * @param array<string,mixed> $task
     * @param array<string,mixed> $response
     */
    private function runChainSteps( TaskTemplateInterface $template, array $task, array $response ): void {
        $steps = $template->chainSteps();
        if ( empty( $steps ) ) return;
        foreach ( $steps as $step ) {
            if ( ! ( $step instanceof ChainStep ) ) continue;
            if ( ! $step->shouldSpawn( $task, $response ) ) continue;
            $context = $step->buildContext( $task, $response );
            $this->dispatch( $step->template_key, $context, $step->id );
        }
    }

    /**
     * A template's own deadline as the stored `due_at` format, or null when
     * it gave none or one `strtotime()` cannot read. One format on every
     * path keeps the natural-key lookup exact.
     */
    public static function normalizeDueAt( string $date ): ?string {
        if ( trim( $date ) === '' ) return null;
        $ts = strtotime( $date );
        return $ts === false ? null : date( 'Y-m-d H:i:s', $ts );
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
