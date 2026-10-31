<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;

/** How far the line-up of a match still to come is. */
final class LineupBlock extends CoreWeekBlock {

    public function key(): string { return 'lineup'; }

    public function group(): string { return WeekBlockGroup::MATCH; }

    public function label(): string { return __( 'Line-up status', 'talenttrack' ); }

    public function help(): string { return __( 'How many starters are placed in the match preparation.', 'talenttrack' ); }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        if ( ! $row->isOpenMatch() ) return [];
        return [ 'lineup' => $week->prepProgress( $row->activityId ) ];
    }
}
