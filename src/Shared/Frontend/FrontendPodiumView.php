<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\TeamStatsService;

/**
 * FrontendPodiumView — the "Podium" tile destination (coaching group).
 *
 * v3.0.0 slice 4. Top-3 podium per team for every team the coach
 * has access to (or every team, for admins). Visual focus on the
 * podium; no forms, no rosters — those live in Teams and Players.
 */
class FrontendPodiumView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'Top performers', 'talenttrack' ) );

        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );

        if ( empty( $teams ) ) {
            echo '<p><em>' . esc_html__( 'No teams assigned.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $team_svc = new TeamStatsService();
        $any_podium = false;

        foreach ( $teams as $team ) {
            $top = $team_svc->getTopPlayersForTeam( (int) $team->id, 3, 5 );
            if ( empty( $top ) ) continue;

            $any_podium = true;
            echo '<section style="margin-bottom:40px;">';
            echo '<h2 style="margin:0 0 16px; font-size:18px;">'
                . esc_html( (string) $team->name );
            if ( ! empty( $team->age_group ) ) {
                echo ' <small style="color:#666; font-weight:normal;">('
                    . esc_html( (string) $team->age_group )
                    . ')</small>';
            }
            echo '</h2>';
            \TT\Modules\Stats\Admin\PlayerCardView::renderPodium( $top );
            echo '</section>';
        }

        if ( ! $any_podium ) {
            echo '<p><em>' . esc_html__( 'Not enough evaluation data yet to compute podiums. Add at least a few evaluations per player to surface the top performers.', 'talenttrack' ) . '</em></p>';
        }
    }
}
