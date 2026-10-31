<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;

/**
 * How many players can and cannot play in a match still to come. Counts
 * only, never who or why (#4005 decision 3).
 */
final class AvailabilityBlock extends CoreWeekBlock {

    public function key(): string { return 'availability'; }

    public function group(): string { return WeekBlockGroup::MATCH; }

    public function label(): string { return __( 'Availability', 'talenttrack' ); }

    public function help(): string { return __( 'How many players can play. A count, never the reason.', 'talenttrack' ); }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        if ( ! $row->isOpenMatch() ) return [];
        $out = $week->unavailableCount( $row->activityId );
        return [ 'availability' => [ 'available' => count( $week->roster( $row->teamId ) ) - $out, 'unavailable' => $out ] ];
    }
}
