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
 * An injury is open when it is not archived or trashed, has no
 * `actual_return`, and its `expected_return` is null or today or later. An
 * un-closed record whose expected return has passed is stale data, not an
 * open injury: counting it would let last season's forgotten entry poison
 * every squad from now on.
 */
final class PlayerAvailability {

    /** The player can be planned for. */
    public const AVAILABLE = 'available';

    /** The player is carrying an open injury. No detail, by design. */
    public const UNAVAILABLE = 'unavailable';

    /**
     * The subset of `$player_ids` carrying an open injury, as a set keyed by
     * player id, so a caller can ask about a whole roster in one query.
     *
     * @param list<int>   $player_ids
     * @param string|null $today Y-m-d; site time when null.
     * @return array<int, true>
     */
    public static function unavailableSet( array $player_ids, ?string $today = null ): array {
        $ids = [];
        foreach ( $player_ids as $pid ) {
            $pid = (int) $pid;
            if ( $pid > 0 ) $ids[ $pid ] = true;
        }
        if ( $ids === [] ) return [];

        global $wpdb;
        $ids    = array_keys( $ids );
        $in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $today  = $today ?? AttendanceDateRule::today();
        $params = array_merge( $ids, [ (int) CurrentClub::id(), $today ] );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT player_id
               FROM {$wpdb->prefix}tt_player_injuries
              WHERE player_id IN ($in)
                AND club_id = %d
                AND archived_at IS NULL
                AND trashed_at IS NULL
                AND actual_return IS NULL
                AND ( expected_return IS NULL OR expected_return >= %s )",
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
     * The flag for one player, as it appears in a payload or beside a name.
     *
     * @param array<int, true> $unavailable A set from `unavailableSet()`.
     */
    public static function flagFor( int $player_id, array $unavailable ): string {
        return isset( $unavailable[ $player_id ] ) ? self::UNAVAILABLE : self::AVAILABLE;
    }

    /** One player, one query — for a surface that has a single name to ask about. */
    public static function isUnavailable( int $player_id, ?string $today = null ): bool {
        return isset( self::unavailableSet( [ $player_id ], $today )[ $player_id ] );
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
