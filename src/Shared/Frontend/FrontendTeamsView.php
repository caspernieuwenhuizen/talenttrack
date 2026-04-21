<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\TeamStatsService;

/**
 * FrontendTeamsView — the "My teams" tile destination (coaching group).
 *
 * v3.0.0 slice 4. Shows every team the coach has access to, each with
 * its top-3 podium and full roster as FIFA cards. Admins see all teams.
 */
class FrontendTeamsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'My teams', 'talenttrack' ) );

        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );

        if ( empty( $teams ) ) {
            echo '<p><em>' . esc_html__( 'No teams assigned.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $team_svc = new TeamStatsService();

        foreach ( $teams as $team ) {
            echo '<section style="margin-bottom:40px;">';
            echo '<h2 style="margin:0 0 12px; font-size:18px;">'
                . esc_html( (string) $team->name );
            if ( ! empty( $team->age_group ) ) {
                echo ' <small style="color:#666; font-weight:normal;">('
                    . esc_html( (string) $team->age_group )
                    . ')</small>';
            }
            echo '</h2>';

            $top = $team_svc->getTopPlayersForTeam( (int) $team->id, 3, 5 );
            if ( ! empty( $top ) ) {
                \TT\Modules\Stats\Admin\PlayerCardView::renderPodium( $top );
            }

            $players = QueryHelpers::get_players( (int) $team->id );
            if ( empty( $players ) ) {
                echo '<p><em>' . esc_html__( 'No players on this team yet.', 'talenttrack' ) . '</em></p>';
            } else {
                echo '<h3 style="font-size:14px; margin:20px 0 10px; text-transform:uppercase; letter-spacing:0.05em; color:#666;">'
                    . esc_html__( 'Roster', 'talenttrack' )
                    . '</h3>';
                echo '<div class="tt-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:14px;">';
                foreach ( $players as $pl ) {
                    self::renderMiniCard( $pl );
                }
                echo '</div>';
            }
            echo '</section>';
        }
    }

    private static function renderMiniCard( object $player ): void {
        $pos = json_decode( (string) $player->preferred_positions, true );
        $detail_url = add_query_arg(
            [ 'tt_view' => 'players', 'player_id' => (int) $player->id ],
            remove_query_arg( [ 'tt_view', 'player_id' ] )
        );
        ?>
        <a href="<?php echo esc_url( $detail_url ); ?>" style="display:block; text-decoration:none; color:inherit;">
            <div class="tt-card" style="transition:transform 150ms ease, box-shadow 150ms ease; cursor:pointer;"
                 onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)';"
                 onmouseout="this.style.transform='';this.style.boxShadow='';">
                <?php if ( ! empty( $player->photo_url ) ) : ?>
                    <div class="tt-card-thumb"><img src="<?php echo esc_url( (string) $player->photo_url ); ?>" alt="" /></div>
                <?php endif; ?>
                <div class="tt-card-body">
                    <h3><?php echo esc_html( QueryHelpers::player_display_name( $player ) ); ?></h3>
                    <?php if ( is_array( $pos ) && $pos ) : ?>
                        <p><strong><?php esc_html_e( 'Pos:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $pos ) ); ?></p>
                    <?php endif; ?>
                    <?php if ( $player->jersey_number ) : ?>
                        <p><strong>#</strong><?php echo esc_html( (string) $player->jersey_number ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php
    }
}
