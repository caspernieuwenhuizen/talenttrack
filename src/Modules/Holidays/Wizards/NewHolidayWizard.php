<?php
namespace TT\Modules\Holidays\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * NewHolidayWizard (#1480) — the wizard-first create flow for a holiday
 * period (CLAUDE.md §3). One step: name + date range + optional note.
 */
final class NewHolidayWizard implements WizardInterface {

    public function slug(): string { return 'holiday'; }
    public function label(): string { return __( 'New holiday', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_manage_holidays'; }
    public function firstStepSlug(): string { return 'details'; }

    /** @return \TT\Shared\Wizards\WizardStepInterface[] */
    public function steps(): array {
        return [ new HolidayDetailsStep() ];
    }
}
