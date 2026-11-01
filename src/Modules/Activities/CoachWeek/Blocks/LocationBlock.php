<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\WeekBlockGroup;

/** Where the activity takes place. Display only: `location` is always on the row. */
final class LocationBlock extends CoreWeekBlock {

    public function key(): string { return 'location'; }

    public function group(): string { return WeekBlockGroup::ROW; }

    public function label(): string { return __( 'Location', 'talenttrack' ); }
}
