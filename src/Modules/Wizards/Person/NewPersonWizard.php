<?php
namespace TT\Modules\Wizards\Person;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * The new-person wizard (#0063).
 *
 * Creates a `tt_people` row for any non-player human in the academy:
 * staff / coach / physio / scout / parent / guardian / mentor.
 * Mirrors the new-player wizard shape:
 *
 *   1. Basics    — first / last / email / phone
 *   2. Role      — role_type radio (defaults to ?role_hint when set)
 *   3. Review    — confirm + create
 *
 * Two query-arg integrations:
 *   - `?role_hint=parent` (sent by ParentSearchPickerComponent's CTA)
 *     pre-selects the role on step 2.
 *   - `?return_to=<url>&return_field=<name>` causes the final step
 *     to redirect back to the calling page with the new person id
 *     appended as `?{$return_field}={$id}`. Used by the parent-picker
 *     to round-trip back to the player edit form with the freshly-
 *     created parent pre-selected.
 *
 * Cap: `tt_edit_people`.
 */
final class NewPersonWizard implements WizardInterface {

    public function slug(): string { return 'new-person'; }
    public function label(): string { return __( 'New person', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_people'; }
    public function firstStepSlug(): string { return 'basics'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new BasicsStep(),
            new RoleStep(),
            new ReviewStep(),
        ];
    }
}
