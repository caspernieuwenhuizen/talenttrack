<?php
namespace TT\Modules\Trials\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * NewTrialCaseWizard (#3221, epic #3050) — the wizard-first create flow
 * for a trial case (CLAUDE.md §3).
 *
 * A trial is the first thing that happens to a player at the academy, and
 * §1 names it explicitly as a transition worth modelling. Opening a case
 * is a top-level record creation with a player, a track, a date range and
 * a staff assignment — exactly the shape §3 describes — and it was the one
 * such flow with no wizard.
 *
 * It predates #0058, so this is not a retrofit obligation. It is worth
 * doing anyway because of what the flat form has cost this module twice:
 * #3115 found it creating players with a raw insert that skipped
 * `tt_player_created`, and #3130 found `tt_trial_started` fired by three
 * of its four callers. Both were second-write-path bugs. Adding a wizard
 * without addressing that would have made a third, so the commit goes
 * through {@see \TT\Modules\Trials\Services\TrialCaseOpener} — which the
 * flat form now calls too.
 *
 * Steps: player → trial (track, dates, notes) → staff, which is the final
 * step and persists. The flat form stays as the power-user fallback per
 * §3; `WizardEntryPoint` decides which one a "+ New" button reaches.
 *
 * Gated on `tt_manage_trials`, the same capability the flat form's create
 * branch enforces — a read-only head coach reaches neither.
 */
final class NewTrialCaseWizard implements WizardInterface {

    public function slug(): string { return 'trial-case'; }

    public function label(): string { return __( 'Open trial case', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_manage_trials'; }

    public function firstStepSlug(): string { return 'player'; }

    /** @return \TT\Shared\Wizards\WizardStepInterface[] */
    public function steps(): array {
        return [
            new TrialPlayerStep(),
            new TrialDetailsStep(),
            new TrialStaffStep(),
        ];
    }
}
