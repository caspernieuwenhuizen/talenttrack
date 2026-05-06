<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\AwaitTeamOfferDecisionForm;
use TT\Modules\Workflow\Resolvers\RoleBasedResolver;
use TT\Modules\Workflow\TaskTemplate;

/**
 * AwaitTeamOfferDecisionTemplate (#0081 child 4 — onboarding pipeline link 6).
 *
 * Spawned by `ReviewTrialGroupMembershipTemplate` when the HoD chooses
 * `offer_team_position`. Tracks the parent + player decision: do they
 * accept the offer (promote to academy team) or decline?
 *
 * Pipeline stage: **Team Offer** (column 5 on the future widget). Stays
 * in this stage until the parent's decision is captured. Decision is
 * recorded by the HoD on the parent's behalf — there's no public
 * surface for the parent to self-record the answer (out of scope; the
 * comms module #0066 will eventually pipe a parent-facing form).
 *
 * Default deadline: 14 days. The recruitment offer is time-sensitive —
 * every day of delay is a recruitment risk.
 *
 * Required cap: `tt_decide_trial_outcome` (HoD, Admin per matrix).
 */
class AwaitTeamOfferDecisionTemplate extends TaskTemplate {

    public const KEY = 'await_team_offer_decision';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Team-offer decision pending', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Waiting for the parent + player to accept or decline the team-offer position. Record the response when it arrives.', 'talenttrack' );
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
        return AwaitTeamOfferDecisionForm::class;
    }

    public function entityLinks(): array {
        return [ 'prospect_id', 'trial_case_id', 'player_id' ];
    }
}
