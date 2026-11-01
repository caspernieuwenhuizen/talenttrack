<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;

/**
 * How many players can and cannot play in a match still to come. Counts
 * only, never who or why (#4005 decision 3). Scouts and observers hold no
 * injury read, so they get it only when an academy switches it on for them.
 */
final class AvailabilityBlock extends CoreWeekBlock {

    /** Personas that see the count only once an academy turns it on for them. */
    private const OPT_IN = [ 'scout', 'readonly_observer' ];

    public function key(): string { return 'availability'; }

    public function group(): string { return WeekBlockGroup::MATCH; }

    public function label(): string { return __( 'Availability', 'talenttrack' ); }

    public function help(): string { return __( 'How many players can play. A count, never the reason. Off for scouts and observers unless you switch it on.', 'talenttrack' ); }

    public function optInFor( string $persona ): bool { return in_array( $persona, self::OPT_IN, true ); }

    public function rowDefaults(): array { return [ 'availability' => null ]; }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        if ( ! $row->isOpenMatch() || ! $week->canSeeTeam( $row->teamId ) ) return [];
        $out = $week->unavailableCount( $row->activityId );
        return [ 'availability' => [ 'available' => count( $week->roster( $row->teamId ) ) - $out, 'unavailable' => $out ] ];
    }
}
