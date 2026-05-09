<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\TeamStatsService;

/**
 * FrontendMyTeamView — the "My team" tile destination.
 *
 * Layout (#0061 round 3):
 *   1. Podium first (visual headline) — top 3 by rolling rating.
 *   2. The viewer's own player card with a "You're #N of M" badge.
 *      The badge surfaces the viewer's rank without ever showing
 *      ranks of *other* teammates — protecting the silent middle.
 *   3. Teammate roster (names + photos only, click → non-rating
 *      detail surface in `FrontendTeammateView`).
 */
class FrontendMyTeamView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My team', 'talenttrack' ) );
        self::renderHeader( __( 'My team', 'talenttrack' ) );

        $team_id = isset( $player->team_id ) ? (int) $player->team_id : 0;
        if ( $team_id <= 0 ) {
            echo '<p>' . esc_html__( 'You are not on a team yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $team       = QueryHelpers::get_team( $team_id );
        $team_name  = $team ? (string) $team->name : '';
        $team_svc   = new TeamStatsService();
        $top        = $team_svc->getTopPlayersForTeam( $team_id, 3, 5 );
        $rank_info  = $team_svc->getRankInTeam( (int) $player->id, 5 );
        $own_in_top = false;
        if ( $rank_info !== null ) {
            foreach ( $top as $row ) {
                if ( (int) $row['player_id'] === (int) $player->id ) {
                    $own_in_top = true;
                    break;
                }
            }
        }
        ?>
        <style>
        .tt-mt-stack { max-width: 980px; margin: 0 auto; padding: 8px 0 16px; }
        .tt-mt-podium-block { margin-bottom: 24px; }
        .tt-mt-podium-block h3 { text-align: center; margin: 0 0 8px; font-size: 14px; color: var(--tt-muted, #5b6e75); text-transform: uppercase; letter-spacing: 0.05em; }
        .tt-mt-own-card-wrap { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 16px 12px; background: linear-gradient(180deg, transparent 0%, #f6f7f8 100%); border-top: 1px solid var(--tt-line, #e5e7ea); border-bottom: 1px solid var(--tt-line, #e5e7ea); }
        .tt-mt-own-card-wrap .tt-pc { transform: scale(0.85); transform-origin: top center; margin-bottom: -32px; }
        .tt-mt-rank-badge { display: inline-flex; flex-direction: column; align-items: center; gap: 2px; padding: 8px 18px; background: var(--tt-primary, #0b3d2e); color: #fff; border-radius: 999px; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
        .tt-mt-rank-badge-rank { font-family: 'Oswald', sans-serif; font-weight: 700; font-size: 22px; line-height: 1; }
        .tt-mt-rank-badge-meta { font-size: 11px; opacity: 0.85; letter-spacing: 0.04em; }
        .tt-mt-rank-badge.tt-mt-rank-badge-podium { background: linear-gradient(135deg, #ffd34d 0%, #c98c00 100%); color: #2c1d00; }
        .tt-mt-rank-badge-unrated { background: var(--tt-line, #e5e7ea); color: var(--tt-muted, #5b6e75); }
        .tt-mt-rank-badge-unrated .tt-mt-rank-badge-rank { font-size: 14px; letter-spacing: 0.04em; }
        .tt-mt-roster-title { text-align: center; margin: 30px 0 10px; font-size: 14px; color: var(--tt-muted, #5b6e75); text-transform: uppercase; letter-spacing: 0.05em; }
        @media (min-width: 768px) {
            .tt-mt-own-card-wrap { padding: 22px 12px; }
            .tt-mt-own-card-wrap .tt-pc { transform: scale(0.95); margin-bottom: -16px; }
        }
        </style>
        <div class="tt-mt-stack">
            <div class="tt-mt-podium-block">
                <?php if ( ! empty( $top ) ) : ?>
                    <h3><?php esc_html_e( 'Top players on the team', 'talenttrack' ); ?></h3>
                    <?php \TT\Modules\Stats\Admin\PlayerCardView::renderPodium( $top ); ?>
                <?php else : ?>
                    <p style="text-align:center; color:var(--tt-muted, #6a6d66); margin:30px 0;"><em><?php esc_html_e( 'Not enough rated teammates yet for a podium.', 'talenttrack' ); ?></em></p>
                <?php endif; ?>
            </div>

            <div class="tt-mt-own-card-wrap">
                <?php \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $player->id, 'md', true ); ?>
                <?php if ( $rank_info !== null ) : ?>
                    <span class="tt-mt-rank-badge<?php echo $own_in_top ? ' tt-mt-rank-badge-podium' : ''; ?>">
                        <span class="tt-mt-rank-badge-rank"><?php
                            /* translators: %d is the player's rank within their team. */
                            printf( esc_html__( '#%d', 'talenttrack' ), (int) $rank_info['rank'] );
                        ?></span>
                        <span class="tt-mt-rank-badge-meta"><?php
                            /* translators: %d is the count of rated players on the team. */
                            printf( esc_html__( 'of %d on the team', 'talenttrack' ), (int) $rank_info['total'] );
                        ?></span>
                    </span>
                <?php else : ?>
                    <span class="tt-mt-rank-badge tt-mt-rank-badge-unrated">
                        <span class="tt-mt-rank-badge-rank"><?php esc_html_e( 'Not yet rated', 'talenttrack' ); ?></span>
                        <span class="tt-mt-rank-badge-meta"><?php esc_html_e( 'rank shows after the first evaluation', 'talenttrack' ); ?></span>
                    </span>
                <?php endif; ?>
            </div>

            <?php
            $teammates = $team_svc->getTeammatesOfPlayer( (int) $player->id );
            if ( ! empty( $teammates ) ) :
                $teammate_base = remove_query_arg( [ 'tt_view', 'player_id' ] );
                ?>
                <h3 class="tt-mt-roster-title">
                    <?php
                    /* translators: %s is the team name. */
                    printf( esc_html__( 'Teammates on %s', 'talenttrack' ), esc_html( $team_name ) );
                    ?>
                </h3>
                <div class="tt-teammates" style="display:flex; flex-wrap:wrap; gap:18px; justify-content:center; padding:10px 0 30px;">
                    <?php foreach ( $teammates as $mate ) :
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
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
