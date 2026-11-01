<?php
namespace TT\Modules\Activities\CoachWeek;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * No-op defaults for a block: no help text, always available, off unless a
 * subclass says otherwise, owns no fields, contributes nothing. A block
 * overrides only what it contributes.
 */
abstract class AbstractWeekBlock implements WeekBlock {

    public function help(): string {
        return '';
    }

    public function available(): bool {
        return true;
    }

    public function defaultFor( string $persona, string $device ): bool {
        return false;
    }

    public function optInFor( string $persona ): bool {
        return false;
    }

    public function rowDefaults(): array {
        return [];
    }

    public function panelDefaults(): array {
        return [];
    }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        return [];
    }

    public function panelFields( WeekContext $week ): array {
        return [];
    }
}
