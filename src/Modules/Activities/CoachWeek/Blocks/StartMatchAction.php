<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\ActionBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Activities\Services\CoachWeek;

/**
 * Start match: on match day, once a preparation exists and Match execution
 * is on. Never on a completed match (#4486). Needs `tt_edit_activities`.
 */
final class StartMatchAction extends CoreWeekBlock implements ActionBlock {

    public function key(): string { return 'action_start_match'; }

    public function group(): string { return WeekBlockGroup::ACTIONS; }

    public function label(): string { return __( 'Start match', 'talenttrack' ); }

    public function help(): string { return __( 'Only on match day, never on a completed match.', 'talenttrack' ); }

    public function actionFor( WeekRow $row, WeekContext $week ): ?string {
        if ( ! $row->isOpenMatch() || ! $week->canEdit || ! $week->prepOn() ) return null;
        return self::applies( $row, $week ) ? CoachWeek::ACTION_START_MATCH : null;
    }

    /** Match day, a preparation exists and Match execution is on. */
    public static function applies( WeekRow $row, WeekContext $week ): bool {
        return $row->when === WeekRow::WHEN_TODAY
            && $week->executionOn()
            && $week->prepProgress( $row->activityId ) !== null;
    }
}
