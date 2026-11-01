<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\MatchPrep\MatchPrepModule;

/** How far the line-up of a match still to come is. Needs the Match prep module. */
final class LineupBlock extends CoreWeekBlock {

    public function key(): string { return 'lineup'; }

    public function group(): string { return WeekBlockGroup::MATCH; }

    public function label(): string { return __( 'Line-up status', 'talenttrack' ); }

    public function help(): string { return __( 'How many starters are placed in the match preparation.', 'talenttrack' ); }

    public function available(): bool { return ModuleRegistry::isEnabled( MatchPrepModule::class ); }

    public function rowDefaults(): array { return [ 'lineup' => null ]; }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        if ( ! $row->isOpenMatch() || ! $week->canSeeTeam( $row->teamId ) ) return [];
        return [ 'lineup' => $week->prepProgress( $row->activityId ) ];
    }
}
