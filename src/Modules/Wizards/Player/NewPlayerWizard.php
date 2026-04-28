<?php
namespace TT\Modules\Wizards\Player;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * The new-player wizard.
 *
 * Step 1 (path) asks "is this a trial player or a roster player?".
 * The answer routes to either the roster step (full player fields)
 * or the trial step (minimal trial fields + creates a real
 * trial case via #0017's TrialsRestController). Both paths end at
 * the review step.
 */
final class NewPlayerWizard implements WizardInterface {

    public function slug(): string { return 'new-player'; }
    public function label(): string { return __( 'New player', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_players'; }
    public function firstStepSlug(): string { return 'path'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new PathStep(),
            new RosterDetailsStep(),
            new TrialDetailsStep(),
            new ReviewStep(),
        ];
    }
}
