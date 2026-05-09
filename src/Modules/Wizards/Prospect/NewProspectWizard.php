<?php
namespace TT\Modules\Wizards\Prospect;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * NewProspectWizard (#0081 follow-up, v3.110.48).
 *
 * Replaces the original "+ New prospect" entry-point pattern of
 * dispatching a `LogProspectTemplate` workflow task and redirecting
 * the user into the task form. That implementation triggered an
 * automatic task creation up front (the user's complaint: "after
 * clicking + New prospect a new task seems to be created automatically.
 * That is not intended.") and parked the user under "My tasks" in
 * the breadcrumb chain, away from the onboarding pipeline they came
 * from.
 *
 * The wizard is the canonical entry point per CLAUDE.md §3 — flat-form
 * record creation goes through wizards by default. Steps:
 *
 *   1. Identity   — first / last / DOB / current_club
 *   2. Discovery  — discovered_at_event / scouting_notes
 *   3. Parent     — parent_name / parent_email / parent_phone / consent
 *   4. Review     — confirm + create
 *
 * On submit the review step inserts the `tt_prospects` row and
 * dispatches `InviteToTestTrainingTemplate` for the HoD. No
 * `LogProspectTemplate` task is created — the wizard IS the form
 * that the LogProspect task used to wrap. The chain effectively
 * starts at "Invite", which is the next intentional human action
 * (not a draft of the data the wizard already collected).
 *
 * `LogProspectTemplate` and the `/prospects/log` REST endpoint stay
 * in place for backward compat with anything that calls them
 * (e.g. external integrations, the parent self-confirmation token
 * endpoint), but the standalone onboarding-pipeline view no longer
 * routes through them.
 */
final class NewProspectWizard implements WizardInterface {

    public function slug(): string { return 'new-prospect'; }
    public function label(): string { return __( 'New prospect', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_prospects'; }
    public function firstStepSlug(): string { return 'identity'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new IdentityStep(),
            new DiscoveryStep(),
            new ParentStep(),
            new ReviewStep(),
        ];
    }
}
