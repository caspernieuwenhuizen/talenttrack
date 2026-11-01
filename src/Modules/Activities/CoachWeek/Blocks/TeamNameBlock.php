<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\WeekBlockGroup;

/** The team's name on each row, when the week covers more than one team. Display only: `team_name` is always on the row. */
final class TeamNameBlock extends CoreWeekBlock {

    public function key(): string { return 'team_name'; }

    public function group(): string { return WeekBlockGroup::SCOPE; }

    public function label(): string { return __( 'Team name on each row', 'talenttrack' ); }

    public function help(): string { return __( 'Shown when the week covers more than one team.', 'talenttrack' ); }
}
