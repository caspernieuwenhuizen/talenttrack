<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Chain\ChainStep;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\InviteToTestTrainingForm;
use TT\Modules\Workflow\Resolvers\RoleBasedResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * InviteToTestTrainingTemplate (#0081 child 2 — onboarding pipeline link 2/5).
 *
 * Spawned automatically by `LogProspectTemplate`'s chain step. Assigned
 * to a user with the `tt_head_dev` role — the HoD picks an existing or
 * new test training, composes the parent-facing invitation, and
 * submits.
 *
 * Pipeline stage: **Invited** (column 2 on the future widget). Stays in
 * this stage until the HoD completes the task; child 2 PR 2b's
 * `ConfirmTestTrainingTemplate` then takes over while the parent's
 * confirmation is pending.
 *
 * Default deadline: 7 days. The HoD has plenty of time to find a
 * suitable slot, but stale entries should surface on the dashboard
 * so they don't sit forever.
 *
 * Required cap: `tt_invite_prospects` (HoD, Admin per matrix scoping).
 *
 * Chain step: spawns `ConfirmTestTrainingTemplate` to track the
 * parent confirmation while the test training is upcoming.
 */
class InviteToTestTrainingTemplate extends TaskTemplate {

    public const KEY = 'invite_to_test_training';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Invite prospect to test training', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Pick a test training for this prospect, compose the invitation to the parent, and send. Due 7 days after the prospect is logged.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+7 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new RoleBasedResolver( 'tt_head_dev' );
    }

    public function formClass(): string {
        return InviteToTestTrainingForm::class;
    }

    public function entityLinks(): array {
        return [ 'prospect_id' ];
    }

    public function chainSteps(): array {
        return [
            new ChainStep(
                'await_parent_confirmation',
                ConfirmTestTrainingTemplate::KEY,
                null,
                static function ( array $task, array $response ): TaskContext {
                    return new TaskContext(
                        null, null, null, null, null, null,
                        (int) ( $task['id'] ?? 0 ),
                        (int) ( $task['prospect_id'] ?? 0 )
                    );
                },
                'HoD tracks the parent confirmation'
            ),
        ];
    }
}
