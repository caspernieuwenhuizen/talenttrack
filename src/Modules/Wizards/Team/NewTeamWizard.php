<?php
namespace TT\Modules\Wizards\Team;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * The new-team wizard. Three steps: basics → staff → review.
 *
 * Each staff slot is independently skippable — clubs that don't have
 * a physio yet shouldn't be blocked. The review step writes the
 * team row and inserts one `tt_team_people` row per filled staff
 * slot, mapping the slot to a `functional_role` slug
 * (head-coach / assistant-coach / team-manager / physio).
 */
final class NewTeamWizard implements WizardInterface {

    public function slug(): string { return 'new-team'; }
    public function label(): string { return __( 'New team', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_teams'; }
    public function firstStepSlug(): string { return 'basics'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new BasicsStep(),
            new StaffStep(),
            new ReviewStep(),
        ];
    }
}
