<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\TeamStatsService;

/**
 * FrontendMyTeamView — the "My team" tile destination.
 *
 * v3.0.0 slice 3. Replaces the "Mijn team" tab. Own card centered,
 * team top-3 podium, teammate roster (names + photos only, no
 * ratings — per Sprint 2B decision to protect players who don't
 * make the top 3).
 */
class FrontendMyTeamView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'My team', 'talenttrack' ) );

        $team_id = isset( $player->team_id ) ? (int) $player->team_id : 0;
        if ( $team_id <= 0 ) {
            echo '<p>' . esc_html__( 'You are not on a team yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $team = QueryHelpers::get_team( $team_id );
        $team_name = $team ? (string) $team->name : '';

        // Own card — centered.
        echo '<div style="display:flex; justify-content:center; padding:20px 0;">';
        \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $player->id, 'md', true );
        echo '</div>';

        // Team podium — top 3 of the team.
        $team_svc = new TeamStatsService();
        $top = $team_svc->getTopPlayersForTeam( $team_id, 3, 5 );
        if ( ! empty( $top ) ) {
            echo '<h3 style="text-align:center; margin-top:10px;">' . esc_html__( 'Top players on the team', 'talenttrack' ) . '</h3>';
            \TT\Modules\Stats\Admin\PlayerCardView::renderPodium( $top );
        }

        // Teammate roster — names + photos only, no ratings.
        $teammates = $team_svc->getTeammatesOfPlayer( (int) $player->id );
        if ( ! empty( $teammates ) ) {
            echo '<h3 style="text-align:center; margin-top:30px;">';
            printf(
                /* translators: %s is the team name. */
                esc_html__( 'Teammates on %s', 'talenttrack' ),
                esc_html( $team_name )
            );
            echo '</h3>';
            echo '<div class="tt-teammates" style="display:flex; flex-wrap:wrap; gap:18px; justify-content:center; padding:10px 0 30px;">';
            foreach ( $teammates as $mate ) {
                $photo_url = '';
                if ( isset( $mate->photo_id ) && (int) $mate->photo_id > 0 ) {
                    $photo_url = (string) wp_get_attachment_image_url( (int) $mate->photo_id, 'thumbnail' );
                } elseif ( ! empty( $mate->photo_url ) ) {
                    $photo_url = (string) $mate->photo_url;
                }
                $initials = strtoupper(
                    mb_substr( (string) ( $mate->first_name ?? '' ), 0, 1 )
                    . mb_substr( (string) ( $mate->last_name ?? '' ), 0, 1 )
                );
                ?>
                <div style="display:flex; flex-direction:column; align-items:center; gap:6px; width:90px; text-align:center;">
                    <div style="width:72px; height:72px; border-radius:50%; overflow:hidden; background:linear-gradient(135deg,#d0d3d8,#8a8d93); display:flex; align-items:center; justify-content:center; border:2px solid #e5e7ea;">
                        <?php if ( $photo_url ) : ?>
                            <img src="<?php echo esc_url( $photo_url ); ?>" alt="" style="width:100%; height:100%; object-fit:cover;" />
                        <?php else : ?>
                            <span style="font-family:'Oswald',sans-serif; font-weight:700; font-size:22px; color:#fff;"><?php echo esc_html( $initials !== '' ? $initials : '?' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:12px; color:#333; line-height:1.2;">
                        <?php echo esc_html( QueryHelpers::player_display_name( $mate ) ); ?>
                    </div>
                </div>
                <?php
            }
            echo '</div>';
        }
    }
}
