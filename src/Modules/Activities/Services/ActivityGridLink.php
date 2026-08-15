<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * ActivityGridLink (#2401, epic #2381) — deep-links from a single
 * activity into the desktop entry grids, plus the reachability test that
 * decides whether the affordance renders at all.
 *
 * The grids are *period* surfaces: players × activities for one team over
 * a date window. An activity-level affordance narrows the window to that
 * activity's own date, so the coach lands on a single column rather than
 * hunting for it. Three callers need that URL and the same gate — the
 * activity detail page, the list card, and `ActivityCompletionResolver`
 * on the wizard-off path — so the decision lives here rather than being
 * rebuilt per render surface (CLAUDE.md §4).
 *
 * The gate mirrors what the grid views and their bulk endpoints already
 * enforce (`tt_edit_activities` + the feature toggle), plus one
 * activity-level precondition: the grid's rows ARE a team roster, so a
 * club-wide activity with no team has nothing to show and must not offer
 * the link (§7 — hide the affordance, never dead-click it).
 */
final class ActivityGridLink {

    /**
     * Per-request memo of `{team, date}` per activity id. A list render
     * asks about the same activity twice (once to gate, once to build),
     * so without this every card would cost two reads.
     *
     * @var array<int, array{team:int,date:string}>
     */
    private static array $anchors = [];

    /**
     * Seed an activity's team + date from a row the caller already holds.
     * The activity list and detail queries both select `s.*`, so both
     * fields are in hand and the grid links cost no extra reads.
     */
    public static function primeAnchor( int $activity_id, int $team_id, string $session_date ): void {
        if ( $activity_id <= 0 ) return;
        self::$anchors[ $activity_id ] = [ 'team' => $team_id, 'date' => $session_date ];
    }

    public static function attendanceEnabled(): bool {
        return FeatureRegistry::isEnabled( 'attendance_grid' );
    }

    public static function minutesEnabled(): bool {
        return FeatureRegistry::isEnabled( 'minutes_grid' );
    }

    /**
     * Can `$user_id` reach the attendance grid for this activity? Mirrors
     * `ActivitiesRestController::can_edit_grid()` and adds the
     * has-a-team precondition.
     */
    public static function canUseAttendance( int $activity_id, int $user_id ): bool {
        if ( ! self::attendanceEnabled() ) return false;
        return self::hasTeamAndCap( $activity_id, $user_id );
    }

    /**
     * Same for the minutes grid. Minutes only exist on match-family
     * types, so the caller pairs this with an `isMatchType()` check.
     */
    public static function canUseMinutes( int $activity_id, int $user_id ): bool {
        if ( ! self::minutesEnabled() ) return false;
        return self::hasTeamAndCap( $activity_id, $user_id );
    }

    /**
     * Attendance-grid URL for this activity's team, filtered to its date.
     * Empty string when the activity has no team — callers gate on
     * `canUseAttendance()` first, so an empty return means "don't render".
     */
    public static function attendanceUrl( int $activity_id, bool $with_back = true ): string {
        return self::gridUrl( 'attendance-grid', $activity_id, $with_back );
    }

    public static function minutesUrl( int $activity_id, bool $with_back = true ): string {
        return self::gridUrl( 'minutes-grid', $activity_id, $with_back );
    }

    private static function hasTeamAndCap( int $activity_id, int $user_id ): bool {
        if ( $activity_id <= 0 || $user_id <= 0 ) return false;
        if ( self::anchor( $activity_id )['team'] <= 0 ) return false;
        return AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_activities' );
    }

    private static function gridUrl( string $view_slug, int $activity_id, bool $with_back ): string {
        $anchor = self::anchor( $activity_id );
        if ( $anchor['team'] <= 0 ) return '';

        // Slug is a constant chosen by the two public wrappers above, not
        // caller input; the reachability gate is `canUseAttendance()` /
        // `canUseMinutes()`, which mirror the target views' own guards.
        $args = [ 'tt_view' => $view_slug, 'team_id' => $anchor['team'] ];
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $anchor['date'] ) ) {
            $args['from'] = $anchor['date'];
            $args['to']   = $anchor['date'];
        }
        $url = add_query_arg( $args, RecordLink::dashboardUrl() );

        // `appendTo()` captures the CURRENT request as the back target, so
        // the pill reads "Back to <wherever the coach clicked from>"
        // (CLAUDE.md §5) without the caller naming it.
        return $with_back ? BackLink::appendTo( $url ) : $url;
    }

    /** @return array{team:int,date:string} */
    private static function anchor( int $activity_id ): array {
        if ( ! isset( self::$anchors[ $activity_id ] ) ) {
            self::$anchors[ $activity_id ] = ( new ActivitiesRepository() )->gridAnchor( $activity_id );
        }
        return self::$anchors[ $activity_id ];
    }
}
