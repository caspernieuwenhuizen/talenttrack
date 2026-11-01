<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\WeekBlockGroup;

/** Start, kick-off and presence times. Display only: the times are always on the row. */
final class TimesBlock extends CoreWeekBlock {

    public function key(): string { return 'time'; }

    public function group(): string { return WeekBlockGroup::ROW; }

    public function label(): string { return _x( 'Times', 'This week panel block: start and kick-off times', 'talenttrack' ); }

    public function help(): string { return __( 'Start time; for a match the time to be present and the kick-off.', 'talenttrack' ); }
}
