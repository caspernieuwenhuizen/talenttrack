<?php
namespace TT\Modules\Media\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * NewMediaWizard (#2593, epic #2589) — adding photos and video.
 *
 * Reachable at `?tt_view=wizard&tt_wizard=new-media`, optionally carrying
 * `entity_type` + `entity_id` so launching it from a player, team or
 * activity lands on step 2 with the target already answered.
 *
 * **Why the target comes first.** The obvious order is source → details →
 * attach, and it does not work here. The wizard framework posts an
 * ordinary form to `admin-post.php` with no `enctype="multipart/form-data"`,
 * and carries state in a transient — so a chosen file cannot survive a
 * step boundary. Keeping a `$_FILES` tmp path in state would not help
 * either; PHP deletes it when the request ends.
 *
 * So the target is asked first, and the upload commits against it the
 * moment the file is chosen, through the same REST endpoint any other
 * client would use (#2592). The wizard holds the created uuids and the
 * later steps refine them. That also matches how uploading behaves
 * everywhere else: the transfer starts when you pick the file, and you
 * describe it afterwards.
 *
 * The consequence worth stating: abandoning after step 2 leaves the file
 * uploaded and attached, without a title. That is recoverable — it shows
 * up in the record's gallery and can be edited or deleted — and it is
 * better than the alternative, which is a half-uploaded file in a staging
 * area nobody ever cleans up.
 *
 *   1. target   which player, team or activity this belongs to
 *   2. source   upload files, or paste a link to video hosted elsewhere
 *   3. details  title, description, when it was taken
 *   4. confirm  what will be saved, and where it will appear
 *
 * Cap: `tt_manage_media` — adding media is a create, and the matrix
 * carries create under `create_delete` (#2591). Save + Cancel is exempt
 * under CLAUDE.md §6 (c): `WizardChrome` supplies Previous / Next /
 * Cancel.
 */
final class NewMediaWizard implements WizardInterface {

    public function slug(): string { return 'new-media'; }

    public function label(): string { return __( 'Add media', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_manage_media'; }

    /**
     * #3105 — the creation entry point for `media`. The wizard exists to
     * put a new file in the store, which is the one thing an out-of-plan
     * club cannot do; everything it already uploaded stays readable in the
     * galleries. `WizardRegistry::isAvailable()` prefers this over
     * `requiredCap()`, so the "Add media" button and `urlFor()` agree
     * without a second gate at each call site.
     */
    public function isAvailableFor( int $user_id ): bool {
        if ( ! \TT\Modules\License\LicenseGate::allows( 'media' ) ) return false;
        return \TT\Infrastructure\Security\AuthorizationService::userCanOrMatrix(
            $user_id, $this->requiredCap()
        );
    }

    public function firstStepSlug(): string { return 'target'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new MediaTargetStep(),
            new MediaSourceStep(),
            new MediaDetailsStep(),
            new MediaConfirmStep(),
        ];
    }
}
