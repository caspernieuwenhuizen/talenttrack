<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Workflow\Chain\ChainStep;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\ReviewTrialGroupMembershipForm;
use TT\Modules\Workflow\Resolvers\RoleBasedResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * ReviewTrialGroupMembershipTemplate (#0081 child 2b — onboarding pipeline link 5/5).
 *
 * Spawned 90 days after `RecordTestTrainingOutcomeTemplate` admits a
 * prospect to the trial group. Re-spawns every 90 days while the trial
 * case stays in `continue_in_trial_group` decision state.
 *
 * Pipeline stage: **Trial Group** (column 4 on the future widget).
 *
 * Default deadline: +14 days from spawn.
 *
 * Form decisions:
 *   - `offer_team_position` → spawn `AwaitTeamOfferDecisionTemplate`.
 *   - `continue_in_trial_group` → trial case `decision` flips to
 *     `continue_in_trial_group`, `continued_until` bumps 90 days,
 *     this template re-spawns in 90 days for another review pass.
 *   - `decline_final` → trial case `decision = deny_final`, prospect
 *     archived. Terminal.
 *
 * Required cap: `tt_decide_trial_outcome` (HoD, Admin).
 */
class ReviewTrialGroupMembershipTemplate extends TaskTemplate {

    public const KEY = 'review_trial_group_membership';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Review trial-group membership', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Decide whether to offer the prospect a team position, continue in the trial group, or decline. Re-spawns every 90 days while the trial case is in continue-in-trial-group state.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+14 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new RoleBasedResolver( 'tt_head_dev' );
    }

    public function formClass(): string {
        return ReviewTrialGroupMembershipForm::class;
    }

    public function entityLinks(): array {
        return [ 'prospect_id', 'trial_case_id', 'player_id' ];
    }

    /**
     * On `decline_final` the trial case + prospect both terminate
     * here. The form's `serializeResponse()` already wrote the
     * trial case decision; this hook archives the prospect for
     * symmetry with the other terminal-outcome paths.
     */
    public function onComplete( array $task, array $response ): void {
        $decision    = (string) ( $response['decision'] ?? '' );
        $prospect_id = (int) ( $task['prospect_id'] ?? 0 );
        if ( $prospect_id <= 0 ) return;

        if ( $decision === 'decline_final' ) {
            ( new ProspectsRepository() )->archive(
                $prospect_id,
                ProspectsRepository::ARCHIVE_REASON_DECLINED,
                (int) ( $task['assignee_user_id'] ?? 0 )
            );
        }
    }

    public function chainSteps(): array {
        return [
            // Offer team position → AwaitTeamOfferDecision
            new ChainStep(
                'await_offer_decision',
                AwaitTeamOfferDecisionTemplate::KEY,
                static function ( array $task, array $response ): bool {
                    return ( (string) ( $response['decision'] ?? '' ) ) === 'offer_team_position';
                },
                static function ( array $task, array $response ): TaskContext {
                    return new TaskContext(
                        isset( $task['player_id'] ) ? (int) $task['player_id'] : null,
                        null, null, null, null,
                        isset( $task['trial_case_id'] ) ? (int) $task['trial_case_id'] : null,
                        (int) ( $task['id'] ?? 0 ),
                        isset( $task['prospect_id'] ) ? (int) $task['prospect_id'] : null
                    );
                },
                'Track the parent + player team-offer decision'
            ),
            // Continue → re-spawn this template in 90 days
            new ChainStep(
                'continue_review',
                self::KEY,
                static function ( array $task, array $response ): bool {
                    return ( (string) ( $response['decision'] ?? '' ) ) === 'continue_in_trial_group';
                },
                static function ( array $task, array $response ): TaskContext {
                    return new TaskContext(
                        isset( $task['player_id'] ) ? (int) $task['player_id'] : null,
                        null, null, null, null,
                        isset( $task['trial_case_id'] ) ? (int) $task['trial_case_id'] : null,
                        (int) ( $task['id'] ?? 0 ),
                        isset( $task['prospect_id'] ) ? (int) $task['prospect_id'] : null
                    );
                },
                'Re-review trial-group membership in 90 days'
            ),
        ];
    }
}
