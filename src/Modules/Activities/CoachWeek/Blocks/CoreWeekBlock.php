<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;

/**
 * The blocks that make up what the panel showed before it was configurable.
 * Their defaults come from one table, so an install that never opens the
 * settings sees the panel it always had on a desktop, and a short one on a
 * phone.
 */
abstract class CoreWeekBlock extends AbstractWeekBlock {

    /** Shown on a phone to the coaching personas by default. */
    private const COACH_PHONE = [
        'team_name', 'type', 'time', 'attendance', 'expected', 'opponent', 'availability', 'lineup',
        'action_mark_attendance', 'action_match_prep', 'action_start_match', 'action_open_activity',
    ];

    /** A team manager's panel, on both devices. */
    private const TEAM_MANAGER = [ 'team_name', 'time', 'location', 'expected', 'opponent' ];

    /** Core blocks that are new with the settings, and so off until an academy turns them on. */
    private const OFF_BY_DEFAULT = [ 'next_week' ];

    public function defaultFor( string $persona, string $device ): bool {
        $key = $this->key();
        if ( in_array( $key, self::OFF_BY_DEFAULT, true ) ) return false;
        if ( $persona === 'team_manager' ) return in_array( $key, self::TEAM_MANAGER, true );
        if ( $device === 'phone' ) return in_array( $key, self::COACH_PHONE, true );
        return true;
    }
}
