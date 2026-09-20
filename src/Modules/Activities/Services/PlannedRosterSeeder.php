<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * PlannedRosterSeeder (#3800) — a team activity starts with its team
 * expected, whichever door it came through.
 *
 * ## What was wrong
 *
 * Seeding the planned roster was the **wizard's** job and nobody else's:
 * `Wizards\Activity\AttendanceRosterStep` captured the ticks and the write
 * followed. `POST /activities` wrote no expected rows at all, so an
 * activity created over REST, or quickly on a phone, began with a planned
 * roster of 0 and nobody 'expected'.
 *
 * The activity edit screen's save re-writes the expected rows, which is
 * why opening and saving an activity appeared to *fix* it. It was not
 * repairing anything — it was seeding it for the first time. Coaches were
 * doing that by hand, most weeks, for every session.
 *
 * ## The rule
 *
 * One service, called by every create path, so they cannot drift apart
 * again — which is the failure this issue actually reports.
 *
 * - **Only with a team.** An activity with no `team_id` has no roster to
 *   seed from and is left alone.
 * - **Only active players**, straight from `QueryHelpers::get_players()`,
 *   which is already club-scoped and status-filtered. No second roster
 *   query with its own idea of who is on the team.
 * - **Only when empty.** Seeding an activity that already has planned rows
 *   does nothing. That is what makes this safe to call unconditionally,
 *   and it is why a coach who trims a squad does not get the removed
 *   players back the next time anything touches the activity.
 * - **Status is empty, not 'present'.** These rows say *expected*, not
 *   *attended*. `record_type='expected'` already separates them from the
 *   register, and every reader of actual attendance counts `actual`.
 *
 * Tournaments are seeded like anything else: a tournament day has a
 * register — who turned up — even though its *minutes* are recorded per
 * fixture (#3857). Attendance and minutes are different questions.
 */
final class PlannedRosterSeeder {

    /**
     * Seed an activity's planned roster from its team, if it has neither.
     *
     * @param string $activity_status_key The status the activity is being
     *        created with. An activity created already **completed** is
     *        never seeded — see below.
     *
     * @return int The number of players written. 0 means nothing was done,
     *             which is the normal answer for a teamless activity, an
     *             empty team, a completed one, or one that already has a
     *             roster.
     */
    public static function seed( int $activity_id, int $team_id, string $activity_status_key = '' ): int {
        if ( $activity_id <= 0 || $team_id <= 0 ) return 0;

        // An activity created already completed is not planned, it is
        // recorded — and #1636 seeds its roster as *present* so the coach
        // can rate it straight away. That seed no-ops when the activity has
        // any attendance row at all, so a planned roster written first would
        // silently suppress it and leave a just-played session unrateable.
        //
        // The rule lives here rather than at the call site so a future
        // caller inherits it, which is the same reason
        // `seedCompletedRosterPresent()` keeps its own date rule internal.
        if ( $activity_status_key !== '' && $activity_status_key === ActivityStatusKey::COMPLETED ) {
            return 0;
        }

        // Idempotent by design — see the class docblock.
        if ( self::hasPlannedRoster( $activity_id ) ) return 0;

        $repo = new ActivitiesRepository();

        $players = QueryHelpers::get_players( $team_id );
        if ( empty( $players ) ) return 0;

        $rows = [];
        foreach ( $players as $player ) {
            $pid = (int) ( $player->id ?? 0 );
            if ( $pid <= 0 ) continue;
            $rows[ $pid ] = [ 'status' => '', 'notes' => '' ];
        }
        if ( $rows === [] ) return 0;

        // The same writer the edit form uses. A second insert path here is
        // how the two would disagree about guests and the line-up columns.
        $repo->replacePlannedAttendance( $activity_id, $rows );

        return count( $rows );
    }

    /**
     * Does this activity already have a planned roster? Guests count: an
     * activity planned as "three guests and nobody else" has been planned,
     * and overwriting it with the full team would discard a real decision.
     */
    private static function hasPlannedRoster( int $activity_id ): bool {
        global $wpdb;
        $found = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND record_type = 'expected'
              LIMIT 1",
            $activity_id
        ) );
        return $found === 1;
    }
}
