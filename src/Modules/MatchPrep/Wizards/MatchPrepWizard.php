<?php
namespace TT\Modules\MatchPrep\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * MatchPrepWizard (#838) — one-step pre-form availability collection.
 *
 * The wizard collects Present/Absent + reason for every player on the
 * activity's team, then redirects the user to the main match-prep
 * form (`?tt_view=match-prep&activity_id=N`). The form is the bulk of
 * the editing surface; the wizard is intentionally minimal so the
 * coach lands on the lineup screen as fast as possible.
 *
 * Entry: `?tt_view=wizard&slug=match-prep&activity_id=N` (caller
 * passes activity_id through; AvailabilityStep reads it from the
 * URL on first render and stows it in state).
 */
final class MatchPrepWizard implements WizardInterface {

    public function slug(): string { return 'match-prep'; }
    public function label(): string { return __( 'Plan match prep', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_activities'; }
    public function firstStepSlug(): string { return 'availability'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [ new AvailabilityStep() ];
    }
}
