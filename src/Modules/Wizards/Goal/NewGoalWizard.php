<?php
namespace TT\Modules\Wizards\Goal;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * The new-goal wizard. Three steps: player → methodology link →
 * details. The final step writes the goal directly (the goal create
 * form is light enough that we don't need to hand off).
 */
final class NewGoalWizard implements WizardInterface {

    public function slug(): string { return 'new-goal'; }
    public function label(): string { return __( 'New goal', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_goals'; }
    public function firstStepSlug(): string { return 'player'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new PlayerStep(),
            new LinkStep(),
            new DetailsStep(),
        ];
    }
}
