<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * PlayerKpiResolver — maps the viewing user of a PLAYER_PARENT KPI to a
 * `tt_players.id` (#1385).
 *
 * The KPI `compute()` contract receives the viewer's WP user id. For a
 * player persona that maps straight to their own player record. For a
 * parent it doesn't (the parent isn't a player); parents with a single
 * linked child resolve to that child via the canonical
 * `tt_player_parents` pivot (#1993 — `guardian_email` is no longer a
 * live linkage source). Multi-child parents have no single target here
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

        // Single-child parent fallback — canonical pivot, not guardian_email.
        $children = ParentChildResolver::children( $user_id );
        return count( $children ) === 1 ? (int) $children[0]->id : 0;
    }
}
