<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\WeekBlockGroup;

/** A match's opponent and home or away. Display only: `opponent` and `home_away` are always on the row. */
final class OpponentBlock extends CoreWeekBlock {

    public function key(): string { return 'opponent'; }

    public function group(): string { return WeekBlockGroup::MATCH; }

    public function label(): string { return __( 'Opponent and home or away', 'talenttrack' ); }

    public function help(): string { return __( 'Off: the row shows the activity title instead.', 'talenttrack' ); }
}
