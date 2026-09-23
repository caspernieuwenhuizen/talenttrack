<?php
namespace TT\Modules\Vct\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;

/**
 * LoadRestrictionAccess — the one gate on a player's load restriction.
 *
 * There were two, and they disagreed. `PATCH /vct/players/{id}/phv-flag`
 * asked for `tt_vct_plan` plus VCT change scope on the player's team; the
 * panel on the player profile asked for `tt_edit_players` and nothing
 * else, so who could set the flag depended on which surface you reached
 * it through. The REST answer is the one the repository's own contract
 * describes, and it is the narrower of the two, which on a minor's
 * health-adjacent record is the one to keep (CLAUDE.md §1).
 *
 * A restriction only means anything against a plan, and plans are made
 * per team, so a player with no team cannot carry one — the same answer
 * the REST route already gave.
 */
final class LoadRestrictionAccess {

    /**
     * May this user set or clear the restriction on this player?
     */
    public static function canEdit( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_vct_plan' ) ) return false;

        $team_id = self::playerTeamId( $player_id );
        if ( $team_id <= 0 ) return false;

        return AuthorizationService::canPlanForTeam( $user_id, $team_id, 'change' );
    }

    private static function playerTeamId( int $player_id ): int {
        if ( $player_id <= 0 ) return 0;
        global $wpdb;
        $players_table = $wpdb->prefix . 'tt_players';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$players_table} WHERE id = %d LIMIT 1",
            $player_id
        ) );
    }
}
