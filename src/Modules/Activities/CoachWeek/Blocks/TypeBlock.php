<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\WeekBlockGroup;

/** The activity type above the row ("Training", "Match"). Display only: `type_label` is always on the row. */
final class TypeBlock extends CoreWeekBlock {

    public function key(): string { return 'type'; }

    public function group(): string { return WeekBlockGroup::ROW; }

    public function label(): string { return __( 'Activity type', 'talenttrack' ); }

    public function help(): string { return __( 'Training, match, and whether it is today.', 'talenttrack' ); }
}
