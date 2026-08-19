<?php
namespace TT\Modules\Wizards\TeamBlueprint;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\TeamChemistryAccess;
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

    /**
     * #2557 — authorization answered by the matrix, not by the raw cap.
     *
     * `tt_manage_team_chemistry` is granted to administrator / head_dev /
     * club_admin only, and is absent from `LegacyCapMapper::MAPPING`, so
     * `WizardRegistry`'s default `userCanOrMatrix( requiredCap() )` gate
     * denied a head coach — who the `team_chemistry` matrix seeds with
     * `[rc, team]` and who therefore SEES the "+ New blueprint" button
     * rendered by `FrontendTeamBlueprintsView`. Result: the button
     * resolved to an empty URL and did nothing.
     *
     * `TeamChemistryAccess::canManage()` is the same decision the list
     * view, the editor controls, `ReviewStep` and the REST writes make,
     * so the entry point and the flow behind it can no longer disagree.
     * It resolves authority WITHOUT the `team_chemistry` sub-feature
     * toggle (#1485) — the blueprint surfaces deliberately survive that
     * feature being off, which is the default. Bridging the cap in
     * `LegacyCapMapper` instead would re-introduce the toggle via
     * `canAnyScope()`.
     *
     * `requiredCap()` stays declared: it is the wizard's documented cap
     * for the authorization-debug surfaces, and the interface contract.
     */
    public function isAvailableFor( int $user_id ): bool {
        return TeamChemistryAccess::canManage( $user_id );
    }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new SetupStep(),
            new ReviewStep(),
        ];
    }
}
