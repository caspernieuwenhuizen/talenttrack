<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;

/** How many of the squad are expected, from the planned squad, on a non-match activity today or later. */
final class ExpectedPlayersBlock extends CoreWeekBlock {

    public function key(): string { return 'expected'; }

    public function group(): string { return WeekBlockGroup::TRAINING; }

    public function label(): string { return __( 'Expected players', 'talenttrack' ); }

    public function help(): string { return __( 'How many players are expected, from the planned squad.', 'talenttrack' ); }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        if ( $row->isMatch || $row->isDone() ) return [];
        return [ 'planned' => $week->planned( $row ) ];
    }
}
