<?php
namespace TT\Modules\Wizards\TeamBlueprint;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * The new-team-blueprint wizard. Two steps: pick formation + name,
 * then review-and-create. Created blueprints land on the editor at
 * `?tt_view=team-blueprints&id=<new>` ready for drag-drop.
 *
 * Entry from `?tt_view=team-blueprints&team_id=<id>` "+ New blueprint"
 * button — `team_id` is carried via querystring into wizard state.
 */
final class NewTeamBlueprintWizard implements WizardInterface {

    public function slug(): string { return 'new-team-blueprint'; }
    public function label(): string { return __( 'New blueprint', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_manage_team_chemistry'; }
    public function firstStepSlug(): string { return 'setup'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new SetupStep(),
            new ReviewStep(),
        ];
    }
}
