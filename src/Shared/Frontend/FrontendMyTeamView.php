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
        // #1695 — view-body restyle to the 2026 "chrome" look. The
        // global app chrome (#1690) supplies the header; this sheet
        // frames the podium / own-card / roster sections as white chrome
        // cards. Depends on tt-frontend-app-chrome (already enqueued by
        // DashboardShortcode) for the KPI tile + token baseline.
        wp_enqueue_style(
            'tt-frontend-my-team',
            TT_PLUGIN_URL . 'assets/css/frontend-my-team.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
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
        <div class="tt-mt-stack">
            <div class="tt-mt-card tt-mt-podium-block">
                <?php if ( ! empty( $top ) ) : ?>
                    <h3 class="tt-mt-card__title"><?php esc_html_e( 'Top players on the team', 'talenttrack' ); ?></h3>
                    <?php
                    // #1354 — player/parent surface: celebration only.
                    // Position + name + photo + tier; the top-3's actual
                    // rating numbers stay staff-side, matching the
                    // docs/player-dashboard.md promise that teammates'
                    // ratings stay private.
                    \TT\Modules\Stats\Admin\PlayerCardView::renderPodium( $top, false );
                    ?>
                <?php else : ?>
                    <p class="tt-mt-empty"><?php esc_html_e( 'Not enough rated teammates yet for a podium.', 'talenttrack' ); ?></p>
                <?php endif; ?>
            </div>

            <?php
            // #1989 — non-sensitive team fixtures the player may see: the next
            // match and a recent-results form line. No teammate ratings.
            $acts           = new \TT\Modules\Activities\Repositories\ActivitiesRepository();
            $next_matches   = $acts->upcomingMatchesForTeam( $team_id, 1 );
            $recent_results = $acts->recentResultsForTeam( $team_id, 5 );
            if ( ! empty( $next_matches ) || ! empty( $recent_results ) ) :
                $date_fmt = (string) get_option( 'date_format', 'j M Y' );
                ?>
                <div class="tt-mt-card tt-mt-fixtures-block">
                    <?php if ( ! empty( $next_matches ) ) :
                        $nm     = $next_matches[0];
                        $nm_opp = trim( (string) ( $nm->opponent ?? '' ) );
                        ?>
                        <h3 class="tt-mt-card__title"><?php esc_html_e( 'Next match', 'talenttrack' ); ?></h3>
                        <div class="tt-mt-fixture">
                            <span class="tt-mt-fixture__date"><?php echo esc_html( date_i18n( $date_fmt, (int) strtotime( (string) $nm->session_date ) ) ); ?></span>
                            <span class="tt-mt-fixture__opp"><?php
                                /* translators: %s is the opponent name. */
                                echo esc_html( $nm_opp !== '' ? sprintf( __( 'vs %s', 'talenttrack' ), $nm_opp ) : (string) $nm->title );
                            ?></span>
                            <?php if ( (string) ( $nm->home_away ?? '' ) !== '' ) : ?>
                                <span class="tt-mt-fixture__ha"><?php echo esc_html( (string) $nm->home_away === 'away' ? __( 'Away', 'talenttrack' ) : __( 'Home', 'talenttrack' ) ); ?></span>
                            <?php endif; ?>
                            <?php if ( trim( (string) ( $nm->location ?? '' ) ) !== '' ) : ?>
                                <span class="tt-mt-fixture__loc"><?php echo esc_html( (string) $nm->location ); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $recent_results ) ) : ?>
                        <h3 class="tt-mt-card__title"><?php esc_html_e( 'Recent results', 'talenttrack' ); ?></h3>
                        <div class="tt-mt-form">
                            <?php foreach ( $recent_results as $res ) :
                                $cls    = $res->outcome === 'W' ? 'win' : ( $res->outcome === 'L' ? 'loss' : 'draw' );
                                $letter = $res->outcome === 'W' ? _x( 'W', 'match result: win', 'talenttrack' )
                                        : ( $res->outcome === 'L' ? _x( 'L', 'match result: loss', 'talenttrack' )
                                        : _x( 'D', 'match result: draw', 'talenttrack' ) );
                                $opp    = trim( (string) ( $res->opponent ?? '' ) );
                                $tip    = sprintf(
                                    /* translators: 1: opponent, 2: own score, 3: opponent score */
                                    __( '%1$s — %2$d–%3$d', 'talenttrack' ),
                                    $opp !== '' ? $opp : (string) $res->title,
                                    (int) $res->team_score,
                                    (int) $res->opp_score
                                );
                                ?>
                                <span class="tt-mt-form__chip tt-mt-form__chip--<?php echo esc_attr( $cls ); ?>" title="<?php echo esc_attr( $tip ); ?>">
                                    <span class="tt-mt-form__letter"><?php echo esc_html( $letter ); ?></span>
                                    <span class="tt-mt-form__score"><?php echo esc_html( (int) $res->team_score . '–' . (int) $res->opp_score ); ?></span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="tt-mt-card tt-mt-own-card-wrap">
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
                <div class="tt-mt-card tt-mt-roster-block">
                    <h3 class="tt-mt-card__title">
                        <?php
                        /* translators: %s is the team name. */
                        printf( esc_html__( 'Teammates on %s', 'talenttrack' ), esc_html( $team_name ) );
                        ?>
                    </h3>
                    <div class="tt-mt-roster">
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
                            <a href="<?php echo $mate_url; ?>" class="tt-mt-mate">
                                <span class="tt-mt-mate__avatar">
                                    <?php if ( $photo_url ) : ?>
                                        <img src="<?php echo esc_url( $photo_url ); ?>" alt="" />
                                    <?php else : ?>
                                        <span class="tt-mt-mate__initials"><?php echo esc_html( $initials !== '' ? $initials : '?' ); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="tt-mt-mate__name">
                                    <?php echo esc_html( QueryHelpers::player_display_name( $mate ) ); ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
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
