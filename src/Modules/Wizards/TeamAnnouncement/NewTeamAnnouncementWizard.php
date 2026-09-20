<?php
namespace TT\Modules\Wizards\TeamAnnouncement;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Send\MassAnnouncementSender;
use TT\Shared\Wizards\WizardInterface;

/**
 * NewTeamAnnouncementWizard (#3693) — audience, compose, confirm.
 *
 * Three steps, in that order, because the order is the safety. A
 * sender who writes first and picks an audience afterwards agrees to a
 * recipient count they have only just seen; a sender who picks first
 * reads their own words already knowing who is about to get them.
 *
 * ## Save model (CLAUDE.md §6)
 *
 * **C — draft, then submit.** The wizard framework autosaves the
 * answers into `tt_wizard_drafts`, and nothing leaves the building
 * until the confirm step's `submit()`. That is the whole reason this is
 * a wizard rather than one long form: a mass send has no undo, so the
 * commit is one deliberate act at the end and everything before it is a
 * draft the sender can abandon.
 *
 * ## Who may start it
 *
 * `requiredCap()` names the team tier, which the academy tier implies
 * (`MassAnnouncementSender::holdsTeamTier()`). `isAvailableFor()`
 * answers the real question — the registry's default cap check would
 * miss an academy-only holder, and a Head of Development who may
 * announce to the whole academy but coaches no team is exactly that
 * person.
 */
final class NewTeamAnnouncementWizard implements WizardInterface {

    public function slug(): string { return 'new-team-announcement'; }

    public function label(): string { return __( 'New announcement', 'talenttrack' ); }

    public function requiredCap(): string { return MassAnnouncementSender::CAP_TEAM; }

    public function firstStepSlug(): string { return 'audience'; }

    /**
     * Either tier opens it. What each may then reach is decided per
     * audience, in `MassAnnouncementSender::canSend()`.
     */
    public function isAvailableFor( int $user_id ): bool {
        return MassAnnouncementSender::canAnnounce( $user_id );
    }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new AudienceStep(),
            new ComposeStep(),
            new ConfirmStep(),
        ];
    }
}
