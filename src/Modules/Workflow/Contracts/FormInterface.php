<?php
namespace TT\Modules\Workflow\Contracts;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FormInterface — every workflow form (post-match eval, weekly self-eval,
 * goal-setting, goal-approval, quarterly HoD review, plus future forms)
 * implements this.
 *
 * Forms are PHP classes (no form builder in v1) referenced by class name
 * from TaskTemplateInterface::formClass(). The engine instantiates them
 * lazily — once when the inbox renders the task, once again on submit.
 *
 * Sprint 1 ships the interface only — concrete forms land in Sprints 3-4
 * alongside their templates.
 *
 * Live-data forms (e.g. Quarterly HoD review): the form class queries
 * data at render time; nothing is frozen at trigger time.
 */
interface FormInterface {

    /**
     * Render the form for the given task row. Returns the HTML to embed
     * in the inbox detail surface. No <form> wrapper or submit button —
     * those are provided by the surrounding view.
     *
     * @param array<string,mixed> $task The tt_workflow_tasks row, associative.
     */
    public function render( array $task ): string;

    /**
     * Validate raw POST input against the form's schema. Returns an
     * empty array on success, or a map of field-name => error-message on
     * failure.
     *
     * @param array<string,mixed> $raw  Raw POST input for this form.
     * @param array<string,mixed> $task The tt_workflow_tasks row.
     * @return array<string,string>     Validation errors (empty = OK).
     */
    public function validate( array $raw, array $task ): array;

    /**
     * Convert validated input into the canonical response payload that
     * gets stored in tt_workflow_tasks.response_json. Forms own their
     * own schema — no cross-form contract on shape.
     *
     * @param array<string,mixed> $raw  Validated POST input.
     * @param array<string,mixed> $task The tt_workflow_tasks row.
     * @return array<string,mixed>
     */
    public function serializeResponse( array $raw, array $task ): array;
}
