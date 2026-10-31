<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\ActionBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Activities\Services\CoachWeek;

/** Match preparation, on a match still to come, unless Start match applies. Needs `tt_edit_activities`. */
final class MatchPrepAction extends CoreWeekBlock implements ActionBlock {

    public function key(): string { return 'action_match_prep'; }

    public function group(): string { return WeekBlockGroup::ACTIONS; }

    public function label(): string { return __( 'Match preparation', 'talenttrack' ); }

    public function actionFor( WeekRow $row, WeekContext $week ): ?string {
        if ( ! $row->isOpenMatch() || ! $week->canEdit || ! $week->prepOn() ) return null;
        return StartMatchAction::applies( $row, $week ) ? null : CoachWeek::ACTION_MATCH_PREP;
    }
}
