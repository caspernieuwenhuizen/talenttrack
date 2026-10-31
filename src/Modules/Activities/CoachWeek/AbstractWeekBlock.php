<?php
namespace TT\Modules\Activities\CoachWeek;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * No-op defaults for a block: no help text, off unless a subclass says
 * otherwise, nothing on a row and nothing on the panel. A block overrides
 * only what it contributes.
 */
abstract class AbstractWeekBlock implements WeekBlock {

    public function help(): string {
        return '';
    }

    public function defaultFor( string $persona, string $device ): bool {
        return false;
    }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        return [];
    }

    public function panelFields( WeekContext $week ): array {
        return [];
    }
}
