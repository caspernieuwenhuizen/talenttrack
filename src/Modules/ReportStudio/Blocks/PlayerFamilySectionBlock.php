<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\ReportStudio\Domain\FamilySection;

/**
 * A player report section a family may receive, as a Studio block (#4961). A
 * shared copy answers to that section's family rule
 * (`PlayerReportSnapshots::familyMayRead()`).
 */
final class PlayerFamilySectionBlock extends PlayerSectionBlock implements FamilySection {

    public function familySection(): string {
        return $this->section;
    }
}
