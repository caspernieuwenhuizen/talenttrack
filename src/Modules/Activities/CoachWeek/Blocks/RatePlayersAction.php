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
 * #4494 — Rate players: on a completed match with players still unrated,
 * open the ratings grid. Shown only where the grid itself would open: the
 * edit right on the row's team, the grid's own reachability and its plan.
 */
final class RatePlayersAction extends AbstractWeekBlock implements ActionBlock {

    public function key(): string { return 'action_rate_players'; }

    public function group(): string { return WeekBlockGroup::ACTIONS; }

    public function label(): string { return __( 'Rate players', 'talenttrack' ); }

    public function help(): string { return __( 'On a completed match with players still to rate.', 'talenttrack' ); }

    public function available(): bool {
        return ActivityGridLink::ratingsEnabled() && LicenseGate::allows( 'ratings_grid' );
    }

    public function defaultFor( string $persona, string $device ): bool {
        return $persona === 'head_coach' && $device === 'desktop';
    }

    public function actionFor( WeekRow $row, WeekContext $week ): ?string {
        if ( ! $row->done || ! $week->canEditTeam( $row->teamId ) ) return null;
        $counts = PlayerRatingsBlock::countsFor( $row, $week );
        if ( $counts === null || $counts['rated'] >= $counts['played'] ) return null;
        ActivityGridLink::primeAnchor( $row->activityId, $row->teamId, $row->date );
        return ActivityGridLink::canUseRatings( $row->activityId, $week->userId ) ? CoachWeek::ACTION_RATE_PLAYERS : null;
    }
}
