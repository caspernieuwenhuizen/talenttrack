<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Chain\ChainStep;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\LogProspectForm;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * LogProspectTemplate (#0081 child 2 — onboarding pipeline link 1/5).
 *
 * The scout's quick-capture form. A scout sees a player at a match, gets
 * parent contact details, and clicks "+ New prospect" — the entry-point
 * that dispatches one of these tasks assigned to the scout themselves
 * (so it appears in their inbox, not the HoD's). Submitting the form
 * creates the `tt_prospects` row and chain-spawns `InviteToTestTraining`
 * for the HoD to compose the parent-facing invitation.
 *
 * Pipeline stage: **Prospects** (the leftmost column on the future
 * pipeline widget). The task is open while the scout drafts; on
 * completion the prospect's stage advances to **Invited** as the chain
 * step spawns `InviteToTestTraining`.
 *
 * Default deadline: 14 days. The assignee is the initiator so the
 * deadline is generous — it exists to surface stale-not-yet-completed
 * tasks ("scout started a prospect entry and never finished") rather
 * than to enforce urgency.
 *
 * Assignment: the user who initiated. The REST entry point passes the
 * initiating user via `TaskContext.extras['initiated_by']` and a
 * `LambdaResolver` reads it back. This is a deliberate choice over
 * `RoleBasedResolver` — assigning to "all scouts" would fan out to the
 * whole scout cohort which is exactly what the chain doesn't want.
 *
 * Required cap to start the chain: `tt_edit_prospects` (scout, HoD,
 * academy admin per the matrix scoping in #0081 child 1).
 */
class LogProspectTemplate extends TaskTemplate {

    public const KEY = 'log_prospect';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Log prospect', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Quick-capture a new prospect — identity, age, current club, parent contact, scouting notes. Submitting creates the prospect record and notifies the Head of Development to invite them to a test training.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+14 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        // The initiator is passed via context extras; if absent (e.g. a
        // misconfigured trigger that doesn't go through the REST entry
        // point), the resolver returns no assignees and the engine logs
        // the dispatch as resolving to nobody — the same behaviour as
        // any other template with a missing context field.
        return new LambdaResolver( static function ( TaskContext $ctx ): array {
            $uid = (int) ( $ctx->extras['initiated_by'] ?? 0 );
            return $uid > 0 ? [ $uid ] : [];
        } );
    }

    public function formClass(): string {
        return LogProspectForm::class;
    }

    public function entityLinks(): array {
        return [ 'prospect_id' ];
    }

    /**
     * After the scout submits the form, the form's `serializeResponse()`
     * has already written the prospect row and tucked `prospect_id` into
     * the response payload. Stamp it onto the task row's entity link so
     * the chain step (and the future pipeline widget) can join on it.
     */
    public function onComplete( array $task, array $response ): void {
        $prospect_id = isset( $response['prospect_id'] ) ? (int) $response['prospect_id'] : 0;
        if ( $prospect_id <= 0 ) return;

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_workflow_tasks',
            [ 'prospect_id' => $prospect_id ],
            [ 'id' => (int) $task['id'] ]
        );
    }

    /**
     * Chain step: spawn InviteToTestTraining for the HoD to invite the
     * just-logged prospect to a test training. Skip the spawn
     * if `prospect_id` didn't make it onto the response (defensive —
     * shouldn't happen since `serializeResponse()` raises a hard error
     * when it can't insert the row).
     */
    public function chainSteps(): array {
        return [
            new ChainStep(
                'notify_hod_review',
                InviteToTestTrainingTemplate::KEY,
                static function ( array $task, array $response ): bool {
                    return ! empty( $response['prospect_id'] );
                },
                static function ( array $task, array $response ): TaskContext {
                    return new TaskContext(
                        null, null, null, null, null, null,
                        (int) ( $task['id'] ?? 0 ),
                        (int) $response['prospect_id']
                    );
                },
                'Notify HoD to invite the prospect to a test training'
            ),
        ];
    }
}
