<?php
namespace TT\Modules\Measurements\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * NewMeasurementWizard (#1856) — the wizard-first create flow for a
 * measurement *test definition* (CLAUDE.md §3).
 *
 * Steps: details (category + name + value type) → options (unit +
 * direction + recurrence) → targets (optional per-age-group bands), which
 * is the final step and persists the definition + its targets.
 *
 * Test setup is a head-of-development / academy-admin job, so the wizard
 * gates on `tt_manage_players` — the cap those personas hold and coaches
 * don't — keeping it aligned with the `measurement_definitions` matrix
 * grant (HoD/admin global).
 */
final class NewMeasurementWizard implements WizardInterface {

    public function slug(): string { return 'measurement'; }

    public function label(): string { return __( 'New test', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_manage_players'; }

    public function firstStepSlug(): string { return 'details'; }

    /** @return \TT\Shared\Wizards\WizardStepInterface[] */
    public function steps(): array {
        return [
            new MeasurementDetailsStep(),
            new MeasurementOptionsStep(),
            new MeasurementTargetsStep(),
        ];
    }
}
