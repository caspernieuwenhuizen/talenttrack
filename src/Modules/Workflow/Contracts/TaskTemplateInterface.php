<?php
namespace TT\Modules\Workflow\Contracts;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\TaskContext;

/**
 * TaskTemplateInterface — every workflow template (post-match eval,
 * weekly self-eval, quarterly goal-setting, quarterly HoD review, plus
 * future templates) implements this.
 *
 * Templates are PHP classes in `src/Modules/Workflow/Templates/`,
 * registered via WorkflowModule::registerTemplate(). The matching form
 * (FormInterface) is referenced by class name from formClass(); the
 * engine instantiates it on render.
 *
 * Sprint 1 ships the interface only — concrete templates land in
 * Sprints 3-4. The interface is fixed early so resolvers and the engine
 * can be written against it without churn.
 */
interface TaskTemplateInterface {

    /**
     * Stable slug used as the foreign key for tt_workflow_tasks.template_key,
     * tt_workflow_triggers.template_key, and tt_workflow_template_config.template_key.
     * Must be unique across all registered templates. Snake_case lowercase.
     */
    public function key(): string;

    /**
     * Human-readable name for the template library / config UI.
     * Translatable via __() at the call site.
     */
    public function name(): string;

    /**
     * One-paragraph description of what the template does, who gets the
     * task, and on what cadence. Translatable.
     */
    public function description(): string;

    /**
     * Default cadence config. Returns one of:
     *   - ['type' => 'manual']
     *   - ['type' => 'cron', 'expression' => '0 18 * * 0']  (WP-cron-flavoured)
     *   - ['type' => 'event', 'hook' => 'tt_session_completed']
     *
     * Per-install overrides live in tt_workflow_template_config.cadence_override.
     *
     * @return array{type:string, expression?:string, hook?:string}
     */
    public function defaultSchedule(): array;

    /**
     * Default deadline offset from creation, expressed as a human-readable
     * relative date string compatible with strtotime() (e.g. "+72 hours",
     * "+7 days", "+14 days"). Per-install overrides via
     * tt_workflow_template_config.deadline_offset_override.
     */
    public function defaultDeadlineOffset(): string;

    /**
     * Returns the AssigneeResolver this template uses by default. Called
     * at task creation; the resolver's resolve() returns the user IDs
     * the engine fans the task out to.
     */
    public function defaultAssignee(): AssigneeResolver;

    /**
     * Fully-qualified class name of the FormInterface implementation that
     * renders + validates this template's response. Engine instantiates
     * the form lazily.
     */
    public function formClass(): string;

    /**
     * Which TT entity types this template links to. Each value names a
     * tt_workflow_tasks column (e.g. 'player_id', 'team_id', 'session_id',
     * 'evaluation_id', 'goal_id', 'trial_case_id'). The engine populates
     * those columns from the trigger context.
     *
     * @return string[]
     */
    public function entityLinks(): array;

    /**
     * Fan-out hook: given a trigger context, return one TaskContext per
     * task instance to create. Default templates return a single context;
     * fan-out templates (post-match eval, weekly self-eval) return one
     * per affected entity.
     *
     * Overridden by subclasses. Default implementation returns
     * [ $context ].
     *
     * @param TaskContext $context
     * @return TaskContext[]
     */
    public function expandTrigger( TaskContext $context ): array;

    /**
     * Optional callback after a task is completed. The default no-op is
     * fine for most templates; goal-setting overrides this in Phase 1 to
     * spawn its approval-chain second task (the tactical hack flagged
     * in the spec — graduates to a first-class `spawns_on_complete`
     * primitive in Phase 2).
     *
     * @param array<string,mixed> $task     The completed task row (associative).
     * @param array<string,mixed> $response The response payload from the form.
     */
    public function onComplete( array $task, array $response ): void;
}
