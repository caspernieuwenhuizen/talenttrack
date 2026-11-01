<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\ActionBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Activities\Services\CoachWeek;

/**
 * Mark attendance: on an activity behind us whose register is empty, and on
 * a non-match activity today. Needs `tt_edit_activities`.
 */
final class MarkAttendanceAction extends CoreWeekBlock implements ActionBlock {

    public function key(): string { return 'action_mark_attendance'; }

    public function group(): string { return WeekBlockGroup::ACTIONS; }

    public function label(): string { return __( 'Mark attendance', 'talenttrack' ); }

    public function actionFor( WeekRow $row, WeekContext $week ): ?string {
        if ( ! $row->register || ! $week->canEdit ) return null;
        if ( $row->isDone() ) {
            // A completed match dated after today gets no button: the
            // attendance rule refuses present and late before the day.
            if ( $row->when === WeekRow::WHEN_UPCOMING ) return null;
            return $week->recorded( $row->activityId ) ? null : CoachWeek::ACTION_MARK_ATTENDANCE;
        }
        return ( ! $row->isMatch && $row->when === WeekRow::WHEN_TODAY ) ? CoachWeek::ACTION_MARK_ATTENDANCE : null;
    }
}
