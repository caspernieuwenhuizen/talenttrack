<?php
namespace TT\Modules\Wizards\Team;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * The new-team wizard. Four steps: basics → staff → roster → review.
 *
 * Each staff slot is independently skippable — clubs that don't have
 * a physio yet shouldn't be blocked. Roster is also skippable; the
 * review step writes the team row + inserts one `tt_team_people` row
 * per filled staff slot, then bulk-updates `tt_players.team_id` for
 * each player ticked on the roster step.
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
            // #0063 — admins want to assign players in the same flow
            // they use to create the team, not a follow-up.
            new RosterStep(),
            // #0062 — when Spond credentials exist, optionally pick the
            // matching Spond group here instead of going back to the
            // team-edit form afterwards. Auto-skipped when no
            // credentials are configured.
            new SpondGroupStep(),
            new ReviewStep(),
        ];
    }
}
