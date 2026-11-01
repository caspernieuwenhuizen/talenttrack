<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\ActionBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Activities\Services\ActivityGridLink;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Modules\License\LicenseGate;

/**
 * #4494 — Minutes + stats: on a completed match whose minutes check is not
 * complete, open the minutes grid. Shown only where the grid itself would
 * open: the edit right on the row's team and the grid's own reachability.
 */
final class MinutesStatsAction extends AbstractWeekBlock implements ActionBlock {

    public function key(): string { return 'action_minutes_stats'; }

    public function group(): string { return WeekBlockGroup::ACTIONS; }

    public function label(): string { return __( 'Minutes + stats', 'talenttrack' ); }

    public function help(): string { return __( 'On a completed match whose minutes check is not complete.', 'talenttrack' ); }

    public function available(): bool {
        return ActivityGridLink::minutesEnabled() && LicenseGate::allows( 'minutes_grid' );
    }

    public function defaultFor( string $persona, string $device ): bool {
        return $persona === 'head_coach';
    }

    public function actionFor( WeekRow $row, WeekContext $week ): ?string {
        if ( ! $row->done || ! $week->canEditTeam( $row->teamId ) ) return null;
        $check = MinutesCheckBlock::checkFor( $row, $week );
        if ( $check === null || $check['status'] === 'complete' ) return null;
        ActivityGridLink::primeAnchor( $row->activityId, $row->teamId, $row->date );
        return ActivityGridLink::canUseMinutes( $row->activityId, $week->userId ) ? CoachWeek::ACTION_MINUTES_STATS : null;
    }
}
