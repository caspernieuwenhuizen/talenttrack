<?php
namespace TT\Modules\Vct\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * NewVctSessionWizard (#0095 VCT-9 / #944).
 *
 * Five-step wizard that walks a coach through generating a VCT
 * training session. Reachable via
 * `?tt_view=wizard&slug=new-vct-session`.
 *
 * Steps:
 *   1. when   — team + date pick; age + MD context auto-resolved
 *   2. theme  — single-select tactical theme (optional)
 *   3. dur    — duration slider/number bounded by the age profile
 *   4. prev   — server-side preview via VctTrainingComposer
 *   5. review — final confirmation; persists + redirects
 *
 * Cap: tt_vct_plan. Scope: team-level (checked at the When step
 * validation + on final submit via canPlanForTeam()).
 */
final class NewVctSessionWizard implements WizardInterface {

    public function slug(): string { return 'new-vct-session'; }
    public function label(): string { return __( 'New VCT training', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_vct_plan'; }
    public function firstStepSlug(): string { return 'when'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new WhenStep(),
            new ThemeStep(),
            new DurationStep(),
            new PreviewStep(),
            new ReviewStep(),
        ];
    }
}
