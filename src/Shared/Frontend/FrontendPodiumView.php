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
            // #0063 — team header in a styled wrapper (the user's
            // "tableheader-like" ask) + RecordLink to team detail.
            // Spacing tightened from 40px to 24px below; the cards
            // already have their own gutter.
            echo '<section class="tt-podium-team" style="margin-bottom:24px;">';
            echo '<header class="tt-podium-team-header" style="background:#f6f7f8; border:1px solid #e5e7ea; border-bottom:0; border-radius:8px 8px 0 0; padding:10px 14px; display:flex; align-items:baseline; gap:8px;">';
            echo '<h2 style="margin:0; font-size:16px;">';
            echo \TT\Shared\Frontend\Components\RecordLink::inline(
                (string) $team->name,
                \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'teams', (int) $team->id )
            );
            echo '</h2>';
            if ( ! empty( $team->age_group ) ) {
                echo '<small style="color:#5b6e75; font-weight:normal;">'
                    . esc_html( (string) $team->age_group )
                    . '</small>';
            }
            echo '</header>';
            echo '<div class="tt-podium-team-body" style="border:1px solid #e5e7ea; border-radius:0 0 8px 8px; padding:12px;">';
            // PlayerCardView::renderPodium already emits a card per
            // player with a link to the player profile (frontend), so
            // the "cards should lead to player profile" ask is already
            // covered there. Wrapping the section header tightens
            // the visual hierarchy the user complained about.
            \TT\Modules\Stats\Admin\PlayerCardView::renderPodium( $top );
            echo '</div>';
            echo '</section>';
        }

        if ( ! $any_podium ) {
            echo '<p><em>' . esc_html__( 'Not enough evaluation data yet to compute podiums. Add at least a few evaluations per player to surface the top performers.', 'talenttrack' ) . '</em></p>';
        }
    }
}
