<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerKpiResolver — maps the viewing user of a PLAYER_PARENT KPI to a
 * `tt_players.id` (#1385).
 *
 * The KPI `compute()` contract receives the viewer's WP user id. For a
 * player persona that maps straight to their own player record. For a
 * parent it doesn't (the parent isn't a player); parents with a single
 * linked child resolve to that child via the same `guardian_email` link
 * the ChildSwitcher uses. Multi-child parents have no single target here
 * — they use the child switcher + per-child drill-down instead, so we
 * return 0 and the KPI renders unavailable.
 */
final class PlayerKpiResolver {

    public static function playerId( int $user_id ): int {
        if ( $user_id <= 0 ) return 0;

        $player = QueryHelpers::get_player_for_user( $user_id );
        if ( $player && (int) $player->id > 0 ) {
            return (int) $player->id;
        }

        // Single-child parent fallback (guardian_email link).
        $user = get_user_by( 'id', $user_id );
        if ( ! $user instanceof \WP_User || (string) $user->user_email === '' ) {
            return 0;
        }

        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players
              WHERE guardian_email = %s AND status = 'active' AND club_id = %d
              LIMIT 2",
            $user->user_email, CurrentClub::id()
        ) );
        return ( is_array( $ids ) && count( $ids ) === 1 ) ? (int) $ids[0] : 0;
    }
}
