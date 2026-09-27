<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerAvailability (#4005) — can this player be planned for, or are they
 * carrying an open injury?
 *
 * The coach planning a session or picking a squad is the person who has to
 * know, and nothing on the planning surfaces read the injury record: the
 * planned roster still listed a player with a broken ankle as expected, and
 * the assistant coach's role cannot open the injury itself. A head coach
 * picking a squad had to remember it themselves and leave the player out by
 * hand.
 *
 * Three decisions this class exists to hold (locked on #4005):
 *
 * 1. **Derived, never stored.** The answer is computed per request from the
 *    injury rows. Persisting it would recreate exactly the stale state the
 *    derivation avoids — the reason a past `expected_return` does not count.
 * 2. **Beside the plan, never instead of it.** `plan_status` is the coach's
 *    own decision (a player may be expected on a light session, rehab
 *    minutes, or travelling with the squad) and this must not overwrite it.
 * 3. **The state and nothing else.** No injury type, body part, note, date
 *    or id ever leaves this class — these are minors, and a role without
 *    injury access learns only that the player cannot be planned for
 *    (CLAUDE.md §1).
 *
 * Availability is a question about a day (#4100): *could this player take
 * part on day D?* D is the date being planned for — the activity's date, or
 * a tournament's start date — and today when a caller has none. An injury
 * counts on day D when it is not archived or trashed, started on or before D,
 * and on D the player was not yet back:
 *
 * - with an `actual_return`, D falls before it (the return day is fit);
 * - without one, `expected_return` is null or D or later.
 *
 * So a current injury does not rewrite the matches the player was fit for,
 * and an injury the player has since recovered from still counts for the
 * match it kept them out of.
 *
 * An un-closed record whose expected return has passed before D is stale
 * data, not an open injury: counting it would let last season's forgotten
 * entry poison every squad from then on.
 *
 * The date goes in; only the state comes out — decision 3 holds.
 */
final class PlayerAvailability {

    /** The player can be planned for. */
    public const AVAILABLE = 'available';

    /** The player is carrying an open injury. No detail, by design. */
    public const UNAVAILABLE = 'unavailable';

    /**
     * The subset of `$player_ids` injured on `$on`, as a set keyed by player
     * id, so a caller can ask about a whole roster in one query.
     *
     * @param list<int>   $player_ids
     * @param string|null $on The day being planned for, Y-m-d (a datetime is
     *                        cut to its date); today in site time when null
     *                        or not a date.
     * @return array<int, true>
     */
    public static function unavailableSet( array $player_ids, ?string $on = null ): array {
        $ids = [];
        foreach ( $player_ids as $pid ) {
            $pid = (int) $pid;
            if ( $pid > 0 ) $ids[ $pid ] = true;
        }
        if ( $ids === [] ) return [];

        global $wpdb;
        $ids    = array_keys( $ids );
        $in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $day    = self::day( $on );
        $params = array_merge( $ids, [ (int) CurrentClub::id(), $day, $day, $day ] );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT player_id
               FROM {$wpdb->prefix}tt_player_injuries
              WHERE player_id IN ($in)
                AND club_id = %d
                AND archived_at IS NULL
                AND trashed_at IS NULL
                AND started_on <= %s
                AND (
                      ( actual_return IS NOT NULL AND actual_return > %s )
                   OR ( actual_return IS NULL
                        AND ( expected_return IS NULL OR expected_return >= %s ) )
                )",
            $params
        ) );

        $out = [];
        foreach ( (array) $rows as $pid ) {
            $pid = (int) $pid;
            if ( $pid > 0 ) $out[ $pid ] = true;
        }
        return $out;
    }

    /**
     * #4103 — the subset of `$player_ids` who cannot play in this activity:
     * injured on its date, or covered by a suspension.
     *
     * A suspension is a question about the activity, not the day. It
     * covers matches only (never a training), counts the team's matches in
     * order, and ends when the last of them is completed — so it needs the
     * activity itself, and `unavailableSet()`'s date is not enough.
     *
     * Decision 3 of #4005 still holds: the answer is the one state, with no
     * word on whether the cause is an injury or a suspension. A planning
     * surface shows one *Unavailable* label either way.
     *
     * @param array<int, int> $player_ids
     * @param string|null     $on The activity's date when the caller has it,
     *                            saving a query; read from the activity
     *                            otherwise.
     * @return array<int, true>
     */
    public static function unavailableForActivity( array $player_ids, int $activity_id, ?string $on = null ): array {
        if ( $activity_id <= 0 ) return self::unavailableSet( array_values( $player_ids ), $on );

        if ( $on === null || trim( $on ) === '' ) {
            $on = ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )->activityDate( $activity_id );
        }

        $out = self::unavailableSet( array_values( $player_ids ), $on );
        foreach ( array_keys( \TT\Infrastructure\Journey\SuspensionService::coveredSet( $player_ids, $activity_id ) ) as $pid ) {
            $out[ $pid ] = true;
        }
        return $out;
    }

    /**
     * The flag for one player, as it appears in a payload or beside a name.
     *
     * @param array<int, true> $unavailable A set from `unavailableSet()`.
     */
    public static function flagFor( int $player_id, array $unavailable ): string {
        return isset( $unavailable[ $player_id ] ) ? self::UNAVAILABLE : self::AVAILABLE;
    }

    /** One player, one query — for a surface that has a single name to ask about. */
    public static function isUnavailable( int $player_id, ?string $on = null ): bool {
        return isset( self::unavailableSet( [ $player_id ], $on )[ $player_id ] );
    }

    /**
     * The day to ask about: the date part of `$on`, or today when the caller
     * has no date or passed something that is not one. Today is the
     * conservative fallback — it is what every surface showed before #4100.
     */
    private static function day( ?string $on ): string {
        $date = substr( trim( (string) $on ), 0, 10 );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) === 1 ? $date : AttendanceDateRule::today();
    }

    /**
     * The one label the surfaces show, and deliberately the word match
     * prep's own availability step already uses (`FrontendMatchPrepView`):
     * one word for one concept across the app, rather than a second
     * translation of the same idea drifting beside it. It says only that
     * the player cannot be planned for.
     */
    public static function label(): string {
        return __( 'Unavailable', 'talenttrack' );
    }
}
