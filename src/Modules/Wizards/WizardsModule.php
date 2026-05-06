<?php
namespace TT\Modules\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Wizards\Activity\NewActivityWizard;
use TT\Modules\Wizards\Evaluation\EvaluationRowRestController;
use TT\Modules\Wizards\Evaluation\NewEvaluationWizard;
use TT\Modules\Wizards\Goal\NewGoalWizard;
use TT\Modules\Wizards\Person\NewPersonWizard;
use TT\Modules\Wizards\Player\NewPlayerWizard;
use TT\Modules\Wizards\Team\NewTeamWizard;
use TT\Modules\Wizards\TeamBlueprint\NewTeamBlueprintWizard;
use TT\Shared\Wizards\WizardDraftRestController;
use TT\Shared\Wizards\WizardRegistry;

/**
 * WizardsModule (#0055) — record-creation wizards.
 *
 * Registers the five shipped wizards (new-player, new-team,
 * new-evaluation, new-goal, new-activity) with the shared
 * `WizardRegistry`. The
 * config toggle `tt_wizards_enabled` decides which surface entry
 * points; default is `'all'` so a fresh install gets the wizards
 * out of the box.
 *
 * The framework primitives live under `src/Shared/Wizards/` so a
 * future module that wants to add its own wizard (e.g. the planned
 * #0006 team-planning wizard) doesn't need to depend on this one.
 */
class WizardsModule implements ModuleInterface {

    public function getName(): string { return 'wizards'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        WizardRegistry::register( new NewPlayerWizard() );
        WizardRegistry::register( new NewTeamWizard() );
        WizardRegistry::register( new NewEvaluationWizard() );
        WizardRegistry::register( new NewGoalWizard() );
        WizardRegistry::register( new NewActivityWizard() );
        WizardRegistry::register( new NewPersonWizard() );
        WizardRegistry::register( new NewTeamBlueprintWizard() );

        // #0072 — daily cron to prune stale `tt_wizard_drafts` rows.
        WizardDraftCleanupCron::init();

        // #0072 follow-up — autosave indicator endpoint.
        WizardDraftRestController::init();

        // #0072 follow-up — per-row evaluation insert endpoint, drives the
        // Review-step progress bar.
        EvaluationRowRestController::init();
    }
}
