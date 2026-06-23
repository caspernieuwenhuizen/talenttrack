<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\TeamStatsService;
use TT\Shared\Frontend\Components\FrontendAppChrome;

/**
 * FrontendPodiumView — the "Podium" tile destination (coaching group).
 *
 * v3.0.0 slice 4. Top-3 podium per team for every team the coach
 * has access to (or every team, for admins). Visual focus on the
 * podium; no forms, no rosters — those live in Teams and Players.
 *
 * v4.45.10 (#1695) — view body restyled to the 2026 "chrome" look:
 * a summary KPI strip plus per-team white chrome cards (1px #e3e6e1,
 * radius 14px, --tt-shadow-md), the leading team carrying the brand-gold
 * accent. The shared PlayerCardView podium still renders the player cards
 * inside each card. Styling lives in assets/css/frontend-podium.css.
 */
class FrontendPodiumView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-podium',
            TT_PLUGIN_URL . 'assets/css/frontend-podium.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Top performers', 'talenttrack' ) );
        self::renderHeader( __( 'Top performers', 'talenttrack' ) );

        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );

        if ( empty( $teams ) ) {
            echo '<p class="tt-podium-empty"><em>' . esc_html__( 'No teams assigned.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $team_svc = new TeamStatsService();

        // Collect the teams that actually have a podium first, so the
        // summary strip and the lead-team accent can be derived without
        // a second pass. Plain iteration over already-authorized data —
        // no business logic, no extra queries.
        $podiums       = [];
        $ranked_player_count = 0;
        foreach ( $teams as $team ) {
            $top = $team_svc->getTopPlayersForTeam( (int) $team->id, 3, 5 );
            if ( empty( $top ) ) continue;
            $podiums[]            = [ 'team' => $team, 'top' => $top ];
            $ranked_player_count += count( $top );
        }

        if ( empty( $podiums ) ) {
            echo '<p class="tt-podium-empty"><em>' . esc_html__( 'Not enough evaluation data yet to compute podiums. Add at least a few evaluations per player to surface the top performers.', 'talenttrack' ) . '</em></p>';
            return;
        }

        // Summary KPI strip — real counts from the data above.
        echo '<div class="tt-podium-kpis">';
        echo FrontendAppChrome::kpiTile( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — kpiTile escapes its inputs.
            'label' => __( 'Teams ranked', 'talenttrack' ),
            'value' => number_format_i18n( count( $podiums ) ),
        ] );
        echo FrontendAppChrome::kpiTile( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — kpiTile escapes its inputs.
            'label' => __( 'Top performers', 'talenttrack' ),
            'value' => number_format_i18n( $ranked_player_count ),
        ] );
        echo '</div>';

        foreach ( $podiums as $i => $row ) {
            $team    = $row['team'];
            $top     = $row['top'];
            $is_lead = ( $i === 0 );

            $section_class = 'tt-podium-team' . ( $is_lead ? ' tt-podium-team--lead' : '' );
            echo '<section class="' . esc_attr( $section_class ) . '">';

            echo '<header class="tt-podium-team-header">';
            echo '<h2>';
            echo \TT\Shared\Frontend\Components\RecordLink::inline(
                (string) $team->name,
                \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'teams', (int) $team->id )
            );
            echo '</h2>';
            if ( ! empty( $team->age_group ) ) {
                echo '<span class="tt-podium-chip">'
                    . esc_html( \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'age_group', (string) $team->age_group ) )
                    . '</span>';
            }
            if ( $is_lead ) {
                echo '<span class="tt-podium-lead-pill">' . esc_html__( 'Leading', 'talenttrack' ) . '</span>';
            }
            echo '</header>';

            echo '<div class="tt-podium-team-body">';
            // PlayerCardView::renderPodium emits a card per player, each
            // linking to the player profile — the shared component the
            // player and coach dashboards reuse. The chrome card wrapper
            // is all this view styles.
            \TT\Modules\Stats\Admin\PlayerCardView::renderPodium( $top );
            echo '</div>';
            echo '</section>';
        }
    }
}
