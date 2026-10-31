<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\ActionBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Activities\Services\CoachWeek;

/** Open the activity, on every match today or later. A row with no button is itself the link. */
final class OpenActivityAction extends CoreWeekBlock implements ActionBlock {

    public function key(): string { return 'action_open_activity'; }

    public function group(): string { return WeekBlockGroup::ACTIONS; }

    public function label(): string { return __( 'Open activity', 'talenttrack' ); }

    public function actionFor( WeekRow $row, WeekContext $week ): ?string {
        return ( $row->isMatch && $row->when !== WeekRow::WHEN_PAST ) ? CoachWeek::ACTION_OPEN_ACTIVITY : null;
    }
}
