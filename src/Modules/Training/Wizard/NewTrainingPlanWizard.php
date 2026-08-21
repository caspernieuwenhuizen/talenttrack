<?php
namespace TT\Modules\Training\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * NewTrainingPlanWizard (#2497, epic #2493) — the generator's front door.
 *
 * Reachable at `?tt_view=wizard&slug=new-training-plan`. Five steps, and
 * the coach is never shown a blank canvas: by the proposal step the plan
 * already exists, drafted from the club's own exercise library against
 * this squad's open development targets.
 *
 * That is the point of the wave. The competitor is a paper sheet made in
 * ten minutes on the couch, so the wizard's job is to be finished before
 * ten minutes are up.
 *
 *   1. when     team + date; age, match-day context and squad size resolved
 *   2. theme    what to work on, prefilled from the periodisation week
 *   3. shape    duration and expected turnout, both prefilled
 *   4. proposal the drafted blocks, regenerable
 *   5. review   coverage, warnings, and save
 *
 * Cap: `tt_training_plan`. Save + Cancel is exempt under CLAUDE.md §6 (c)
 * — `WizardChrome` supplies Previous / Next / Cancel.
 */
final class NewTrainingPlanWizard implements WizardInterface {

    public function slug(): string { return 'new-training-plan'; }

    public function label(): string { return __( 'New training plan', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_training_plan'; }

    public function firstStepSlug(): string { return 'when'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new WhenStep(),
            new ThemeStep(),
            new ShapeStep(),
            new ProposalStep(),
            new ReviewStep(),
        ];
    }
}
