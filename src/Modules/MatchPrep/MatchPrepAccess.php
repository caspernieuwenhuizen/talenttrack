<?php
namespace TT\Modules\MatchPrep;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\ActivityTeamScope;

/**
 * MatchPrepAccess — who may read and who may change a match's prep.
 *
 * Reading follows the activity read rule: an activities capability and
 * the activity's team in read scope, which a club-wide reader such as the
 * read-only observer holds for every team. Changing it follows the write
 * rule: `tt_edit_activities` and the team in write scope. Every surface of
 * the prep asks these: the prep screen, the activity page's entry, the
 * `match-prep` REST routes, the print sheet and the PDF export.
 */
final class MatchPrepAccess {

    /**
     * May this user read the prep of this activity?
     *
     * @param object    $activity A row carrying `team_id`.
     * @param bool|null $is_admin The caller's admin flag; null asks `tt_edit_settings`.
     */
    public static function canRead( int $user_id, object $activity, ?bool $is_admin = null ): bool {
        if ( $user_id <= 0 ) return false;
        if ( ! user_can( $user_id, 'tt_view_activities' ) && ! user_can( $user_id, 'tt_edit_activities' ) ) return false;
        return ActivityTeamScope::coversTeam( $user_id, self::teamId( $activity ), $is_admin );
    }

    /**
     * May this user change the prep of this activity?
     *
     * @param object    $activity A row carrying `team_id`.
     * @param bool|null $is_admin The caller's admin flag; null asks `tt_edit_settings`.
     */
    public static function canWrite( int $user_id, object $activity, ?bool $is_admin = null ): bool {
        if ( $user_id <= 0 ) return false;
        if ( ! user_can( $user_id, 'tt_edit_activities' ) ) return false;
        return ActivityTeamScope::coversTeamForWrite( $user_id, self::teamId( $activity ), $is_admin );
    }

    /**
     * {@see self::canRead()} asked about an activity id, for the routes and
     * exports that have not loaded the row. An activity that does not exist
     * in this club is not readable.
     */
    public static function canReadActivity( int $user_id, int $activity_id, ?bool $is_admin = null ): bool {
        $team_id = ActivityTeamScope::teamIdForActivity( $activity_id );
        if ( $team_id === null ) return false;
        return self::canRead( $user_id, (object) [ 'team_id' => $team_id ], $is_admin );
    }

    /** {@see self::canWrite()} asked about an activity id. */
    public static function canWriteActivity( int $user_id, int $activity_id, ?bool $is_admin = null ): bool {
        $team_id = ActivityTeamScope::teamIdForActivity( $activity_id );
        if ( $team_id === null ) return false;
        return self::canWrite( $user_id, (object) [ 'team_id' => $team_id ], $is_admin );
    }

    private static function teamId( object $activity ): int {
        return (int) ( ( (array) $activity )['team_id'] ?? 0 );
    }
}
