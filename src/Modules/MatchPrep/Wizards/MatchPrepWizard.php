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

    /**
     * #0092 opt-in seed hook (v4.5.1 — follow-up to #940). On first
     * hit the wizard framework passes `$_GET` here so the wizard can
     * stash URL params in state. Necessary now that wizard POSTs go
     * through admin-post.php and don't see the original entry URL's
     * query string. Without this, `activity_id` was read from `$_GET`
     * inside AvailabilityStep::render() but never persisted to state,
     * so validate() / submit() saw a missing activity_id after POST.
     *
     * @param array<string,mixed> $get
     * @return array<string,mixed>
     */
    public function initialState( array $get ): array {
        $activity_id = isset( $get['activity_id'] ) ? (int) $get['activity_id'] : 0;
        return $activity_id > 0 ? [ 'activity_id' => $activity_id ] : [];
    }
}
