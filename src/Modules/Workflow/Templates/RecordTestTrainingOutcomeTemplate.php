<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Modules\Workflow\Chain\ChainStep;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\RecordTestTrainingOutcomeForm;
use TT\Modules\Workflow\Resolvers\RoleBasedResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * RecordTestTrainingOutcomeTemplate (#0081 child 2b — onboarding pipeline link 4/5).
 *
 * Spawned by `ConfirmTestTrainingTemplate` once the parent confirms.
 * Coach (assigned to the test-training row) records observation +
 * recommendation; HoD reviews and decides at submit.
 *
 * Pipeline stage: **Test Training** (column 3 on the future widget).
 *
 * Default deadline: +7 days from spawn.
 *
 * Form recommendation values:
 *   - `admit_to_trial` — open a `tt_trial_cases` row + spawn
 *     `ReviewTrialGroupMembershipTemplate` (90 days out).
 *   - `decline` — archive the prospect, terminal.
 *   - `request_second_session` — re-spawn `InviteToTestTrainingTemplate`
 *     for another invite cycle.
 *
 * Required cap: `tt_decide_test_training_outcome` (HoD, Admin).
 */
class RecordTestTrainingOutcomeTemplate extends TaskTemplate {

    public const KEY = 'record_test_training_outcome';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Record test-training outcome', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Record observations and the admit / decline / second-attendance decision after the prospect attended a test training.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+7 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        // Assigned to HoD by default. Operator can override per-template
        // if they want it on the session's coach instead — this is the
        // recommended cohort for batch decisions.
        return new RoleBasedResolver( 'tt_head_dev' );
    }

    public function formClass(): string {
        return RecordTestTrainingOutcomeForm::class;
    }

    public function entityLinks(): array {
        return [ 'prospect_id' ];
    }

    /**
     * Side-effects: trial-case creation on `admit_to_trial`, prospect
     * archival on `decline`. The form's `serializeResponse()` also
     * carries forward player_id when a prospect-to-player promotion
     * happened earlier; we don't promote here (the prospect stays a
     * prospect through the trial cycle and only promotes on team
     * acceptance in #0081 child 4 `AwaitTeamOfferDecisionForm`).
     */
    public function onComplete( array $task, array $response ): void {
        $recommendation = (string) ( $response['recommendation'] ?? '' );
        $prospect_id    = (int) ( $task['prospect_id'] ?? 0 );
        if ( $prospect_id <= 0 || $recommendation === '' ) return;

        if ( $recommendation === 'decline' ) {
            ( new ProspectsRepository() )->archive(
                $prospect_id,
                ProspectsRepository::ARCHIVE_REASON_DECLINED,
                (int) ( $task['assignee_user_id'] ?? 0 )
            );
        }
        // admit_to_trial side-effect (trial case creation) is handled
        // in the form's serializeResponse() so the trial_case_id ends
        // up in $response and we can read it from the chain step
        // contextBuilder below. Keeping the entity-creation in the
        // form mirrors LogProspect/InviteToTestTraining's pattern of
        // "the form IS the entity-creation flow."
        // request_second_session is a chain-step concern (loop back
        // to invite); no side-effect here.
    }

    public function chainSteps(): array {
        return [
            // Admitted → Review trial-group membership.
            new ChainStep(
                'review_trial_group',
                ReviewTrialGroupMembershipTemplate::KEY,
                static function ( array $task, array $response ): bool {
                    return ( (string) ( $response['recommendation'] ?? '' ) ) === 'admit_to_trial'
                        && ! empty( $response['trial_case_id'] );
                },
                static function ( array $task, array $response ): TaskContext {
                    return new TaskContext(
                        null, null, null, null, null,
                        (int) $response['trial_case_id'],
                        (int) ( $task['id'] ?? 0 ),
                        (int) ( $task['prospect_id'] ?? 0 )
                    );
                },
                'HoD reviews trial-group membership in 90 days'
            ),
            // Request a second session → loop back to InviteToTestTraining.
            new ChainStep(
                'second_session',
                InviteToTestTrainingTemplate::KEY,
                static function ( array $task, array $response ): bool {
                    return ( (string) ( $response['recommendation'] ?? '' ) ) === 'request_second_session';
                },
                static function ( array $task, array $response ): TaskContext {
                    return new TaskContext(
                        null, null, null, null, null, null,
                        (int) ( $task['id'] ?? 0 ),
                        (int) ( $task['prospect_id'] ?? 0 )
                    );
                },
                'HoD invites prospect to a second test training'
            ),
        ];
    }
}
