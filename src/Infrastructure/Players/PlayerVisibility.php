<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerVisibility (#3807) — "may this person see any player at all?".
 *
 * The list route and the rendered list used to answer a caller entitled
 * to nobody with an empty page. "No data" and "not allowed" looked
 * identical, so a scout spent a week reporting a broken screen instead
 * of asking for access.
 *
 * ## Why the question is unfiltered
 *
 * It is deliberately asked of the whole club, never of the caller's
 * current filter. Refusing whenever a filter's own rows all fail the
 * per-row check would tell a coach searching "Jansen" that a Jansen
 * exists on somebody else's team — which is the disclosure the per-row
 * filtering exists to prevent. Entitlement is a property of the person;
 * an empty result is a property of the query. Only the first is worth a
 * refusal.
 *
 * ## Why it is not a capability check
 *
 * `tt_view_players` is exactly what the two gates disagreed about: the
 * scout holds it, which is why the door opened, and `canViewPlayer` said
 * no to every row behind it. So the answer has to come from the same
 * predicate the rows come from, or the two will disagree again.
 *
 * One implementation, called by `PlayersRestController::list_players()`
 * and by `FrontendPlayersManageView`, so the screen and the API say the
 * same thing (CLAUDE.md §4).
 */
final class PlayerVisibility {

    /** @var array<int,bool> */
    private static array $cache = [];

    /**
     * True when at least one live player in this club is visible to
     * `$user_id`.
     *
     * Short-circuits on the first hit. `canViewPlayer` resolves from
     * `AuthorizationService`'s per-request caches after the first call,
     * so this is array work over one id column rather than a query per
     * player — and the result is memoised per request besides, because
     * the list route and the view may both ask inside one page load.
     */
    public static function entitledToAny( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( isset( self::$cache[ $user_id ] ) ) return self::$cache[ $user_id ];

        global $wpdb;
        $p = $wpdb->prefix;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}tt_players
              WHERE club_id = %d AND archived_at IS NULL AND trashed_at IS NULL",
            (int) CurrentClub::id()
        ) );

        $entitled = false;
        foreach ( (array) $ids as $pid ) {
            if ( AuthorizationService::canViewPlayer( $user_id, (int) $pid ) ) {
                $entitled = true;
                break;
            }
        }

        self::$cache[ $user_id ] = $entitled;
        return $entitled;
    }

    /**
     * The sentence both surfaces say. One wording, because the screen
     * and the API refusing the same thing differently is how a user
     * comes to believe they are two different problems.
     */
    public static function refusalMessage(): string {
        return __( 'You are not permitted to see any players. Ask the head of development to give you access, or to link the players you scout to your account.', 'talenttrack' );
    }

    /** Tests and long-running processes. */
    public static function flush(): void {
        self::$cache = [];
    }
}
