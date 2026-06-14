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
 *   2. The viewer's own player card with a growth-framed trend chip.
 *      The raw "#N of M" rank badge is opt-in per academy via the
 *      `tt_player_visible_rank` config toggle (default OFF, #1384) —
 *      when off, the player sees only the personal trend; when on,
 *      rank + trend show together. Either way no *other* teammate's
 *      rank is exposed, protecting the silent middle.
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

        // #1384 — the player-visible team rank ("#N of M") is opt-in per
        // academy. Default OFF: the player sees a growth-framed personal
        // trend chip instead. ON: rank badge + trend chip together. Staff
        // personas always see rank elsewhere; this gates the player's own
        // "My team" surface only.
        $show_rank = QueryHelpers::get_config( 'tt_player_visible_rank', '0' ) === '1';
        $trend     = ( new \TT\Infrastructure\Evaluations\EvaluationsRepository() )
            ->personalTrendForPlayer( (int) $player->id );

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
        /* #1384 — growth-framed trend chip (mobile-first). Replaces the
         * raw rank for the player; shown alongside it when rank is on. */
        .tt-mt-trend-chip { display: inline-flex; flex-direction: column; align-items: center; gap: 4px; max-width: 22rem; padding: 10px 16px; background: var(--tt-bg-soft, #f5f7f6); border: 1px solid var(--tt-line, #e5e7ea); border-radius: 14px; text-align: center; }
        .tt-mt-trend-headline { display: inline-flex; align-items: center; gap: 6px; font-family: 'Oswald', sans-serif; font-weight: 700; font-size: 17px; line-height: 1.1; color: var(--tt-primary, #0b3d2e); }
        .tt-mt-trend-headline.tt-mt-trend-up { color: #1f7a4d; }
        .tt-mt-trend-headline.tt-mt-trend-down { color: #b45309; }
        .tt-mt-trend-sub { font-size: 12px; color: var(--tt-muted, #5b6e75); }
        .tt-mt-trend-top { font-size: 12px; color: var(--tt-text, #1f2a30); }
        .tt-mt-trend-top strong { color: var(--tt-primary, #0b3d2e); }
        @media (min-width: 768px) {
            .tt-mt-own-card-wrap { padding: 22px 12px; }
            .tt-mt-own-card-wrap .tt-pc { transform: scale(0.95); margin-bottom: -16px; }
        }
        </style>
        <div class="tt-mt-stack">
            <div class="tt-mt-podium-block">
                <?php if ( ! empty( $top ) ) : ?>
                    <h3><?php esc_html_e( 'Top players on the team', 'talenttrack' ); ?></h3>
                    <?php
                    // #1354 — player/parent surface: celebration only.
                    // Position + name + photo + tier; the top-3's actual
                    // rating numbers stay staff-side, matching the
                    // docs/player-dashboard.md promise that teammates'
                    // ratings stay private.
                    \TT\Modules\Stats\Admin\PlayerCardView::renderPodium( $top, false );
                    ?>
                <?php else : ?>
                    <p style="text-align:center; color:var(--tt-muted, #6a6d66); margin:30px 0;"><em><?php esc_html_e( 'Not enough rated teammates yet for a podium.', 'talenttrack' ); ?></em></p>
                <?php endif; ?>
            </div>

            <div class="tt-mt-own-card-wrap">
                <?php \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $player->id, 'md', true ); ?>
                <?php if ( $show_rank ) : ?>
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
                <?php endif; ?>
                <?php self::renderTrendChip( $trend ); ?>
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

    /**
     * #1384 — growth-framed personal trend chip. Always shown to the
     * player; the raw team rank is opt-in (tt_player_visible_rank).
     *
     * @param array{has_data:bool,current_avg:float|null,prior_avg:float|null,delta:float|null,top_category:string|null} $trend
     */
    private static function renderTrendChip( array $trend ): void {
        echo '<div class="tt-mt-trend-chip">';

        if ( empty( $trend['has_data'] ) ) {
            echo '<span class="tt-mt-trend-headline">' . esc_html__( 'Your progress', 'talenttrack' ) . '</span>';
            echo '<span class="tt-mt-trend-sub">' . esc_html__( 'Your rating trend will appear after your first evaluation.', 'talenttrack' ) . '</span>';
            echo '</div>';
            return;
        }

        $delta = $trend['delta'];
        if ( $delta === null ) {
            echo '<span class="tt-mt-trend-headline">' . esc_html( sprintf(
                /* translators: %s is the player's average rating so far, e.g. "7.2". */
                __( '%s average so far', 'talenttrack' ),
                number_format_i18n( (float) $trend['current_avg'], 1 )
            ) ) . '</span>';
            echo '<span class="tt-mt-trend-sub">' . esc_html__( 'Your trend appears after your next evaluation.', 'talenttrack' ) . '</span>';
            echo '</div>';
            return;
        }

        if ( $delta > 0 ) {
            $icon = 'trend-up';
            $cls  = ' tt-mt-trend-up';
            $headline = sprintf(
                /* translators: %s is the rating increase since last month, e.g. "0.4". */
                __( '+%s since last month', 'talenttrack' ),
                number_format_i18n( $delta, 1 )
            );
        } elseif ( $delta < 0 ) {
            $icon = 'trend-down';
            $cls  = ' tt-mt-trend-down';
            $headline = sprintf(
                /* translators: %s is the (negative) rating change since last month, e.g. "-0.3". */
                __( '%s since last month', 'talenttrack' ),
                number_format_i18n( $delta, 1 )
            );
        } else {
            $icon = 'trend-flat';
            $cls  = '';
            $headline = __( 'Level with last month', 'talenttrack' );
        }

        echo '<span class="tt-mt-trend-headline' . esc_attr( $cls ) . '">';
        echo \TT\Shared\Icons\IconRenderer::render( $icon, [ 'width' => 16, 'height' => 16 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
        echo '<span>' . esc_html( $headline ) . '</span>';
        echo '</span>';

        if ( $delta > 0 && ! empty( $trend['top_category'] ) ) {
            echo '<span class="tt-mt-trend-top">' . sprintf(
                /* translators: %s is a skill category label, e.g. "Technical". */
                esc_html__( 'Improving most: %s', 'talenttrack' ),
                '<strong>' . esc_html( (string) $trend['top_category'] ) . '</strong>'
            ) . '</span>';
        }

        echo '</div>';
    }
}
