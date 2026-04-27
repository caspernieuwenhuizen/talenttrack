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

        // Own card alongside the team podium, separated by a vertical
        // rule so they read as two related-but-distinct units. Stacks
        // vertically below 880px (the rule renders horizontal there).
        $team_svc = new TeamStatsService();
        $top = $team_svc->getTopPlayersForTeam( $team_id, 3, 5 );
        ?>
        <style>
        .tt-mt-grid {
            display: grid;
            grid-template-columns: auto 1px 1fr;
            align-items: start;
            gap: 24px;
            padding: 8px 0 16px;
            max-width: 980px;
            margin: 0 auto;
        }
        .tt-mt-grid .tt-mt-rule { background: var(--tt-line, #e3e1d8); align-self: stretch; }
        .tt-mt-own { font-size: 75%; }
        .tt-mt-own .tt-pc { transform: scale(0.75); transform-origin: top left; margin-bottom: -54px; margin-right: -50px; }
        .tt-mt-podium-wrap { min-width: 0; }
        .tt-mt-podium-wrap h3 { text-align: center; margin: 0 0 6px; font-size: 14px; }
        @media (max-width: 880px) {
            .tt-mt-grid { grid-template-columns: 1fr; }
            .tt-mt-grid .tt-mt-rule { height: 1px; width: 100%; }
        }
        </style>
        <div class="tt-mt-grid">
            <div class="tt-mt-own">
                <?php \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $player->id, 'md', true ); ?>
            </div>
            <div class="tt-mt-rule" aria-hidden="true"></div>
            <div class="tt-mt-podium-wrap">
                <?php if ( ! empty( $top ) ) : ?>
                    <h3><?php esc_html_e( 'Top players on the team', 'talenttrack' ); ?></h3>
                    <?php \TT\Modules\Stats\Admin\PlayerCardView::renderPodium( $top ); ?>
                <?php else : ?>
                    <p style="text-align:center; color:var(--tt-muted, #6a6d66); margin:30px 0;"><em><?php esc_html_e( 'Not enough rated teammates yet for a podium.', 'talenttrack' ); ?></em></p>
                <?php endif; ?>
            </div>
        </div>
        <?php

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
            $teammate_base = remove_query_arg( [ 'tt_view', 'player_id' ] );
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
                $mate_url = esc_url( add_query_arg( [
                    'tt_view'   => 'teammate',
                    'player_id' => (int) $mate->id,
                ], $teammate_base ) );
                ?>
                <a href="<?php echo $mate_url; ?>" style="display:flex; flex-direction:column; align-items:center; gap:6px; width:90px; text-align:center; text-decoration:none; color:inherit;">
                    <div style="width:72px; height:72px; border-radius:50%; overflow:hidden; background:linear-gradient(135deg,#d0d3d8,#8a8d93); display:flex; align-items:center; justify-content:center; border:2px solid #e5e7ea; transition:transform 150ms ease, box-shadow 150ms ease;" onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 4px 10px rgba(0,0,0,0.12)';" onmouseout="this.style.transform=''; this.style.boxShadow='';">
                        <?php if ( $photo_url ) : ?>
                            <img src="<?php echo esc_url( $photo_url ); ?>" alt="" style="width:100%; height:100%; object-fit:cover;" />
                        <?php else : ?>
                            <span style="font-family:'Oswald',sans-serif; font-weight:700; font-size:22px; color:#fff;"><?php echo esc_html( $initials !== '' ? $initials : '?' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:12px; color:#333; line-height:1.2;">
                        <?php echo esc_html( QueryHelpers::player_display_name( $mate ) ); ?>
                    </div>
                </a>
                <?php
            }
            echo '</div>';
        }
    }
}
