<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * The new-evaluation wizard. Two real steps + a redirect on submit.
 *
 * The evaluation forms themselves are heavyweight (eval categories,
 * sub-ratings, attachments). The wizard's job here is narrow: pick
 * the right player + the right type, then hand off to the existing
 * full evaluation form.
 */
final class NewEvaluationWizard implements WizardInterface {

    public function slug(): string { return 'new-evaluation'; }
    public function label(): string { return __( 'New evaluation', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_evaluations'; }
    public function firstStepSlug(): string { return 'player'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new PlayerStep(),
            new TypeStep(),
        ];
    }
}
