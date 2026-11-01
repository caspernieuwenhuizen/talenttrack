<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;

/** "Attendance recorded" or "not recorded" on an activity that is behind us. */
final class AttendanceBlock extends CoreWeekBlock {

    public function key(): string { return 'attendance'; }

    public function group(): string { return WeekBlockGroup::TRAINING; }

    public function label(): string { return __( 'Attendance status', 'talenttrack' ); }

    public function help(): string { return __( 'Whether attendance is recorded, once the activity has passed.', 'talenttrack' ); }

    public function rowDefaults(): array { return [ 'attendance_recorded' => null ]; }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        if ( ! $row->isDone() || ! $row->register || ! $week->canSeeTeam( $row->teamId ) ) return [];
        return [ 'attendance_recorded' => $week->recorded( $row->activityId ) ];
    }
}
