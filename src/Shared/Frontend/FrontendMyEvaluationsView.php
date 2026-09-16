<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\PlayerEvaluationsReader;
use TT\Infrastructure\Evaluations\EvalRatingsRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\RatingPillComponent;

/**
 * FrontendMyEvaluationsView — the player's "My evaluations" tile.
 *
 * #0003 polish (v3.18.0): visual rebuild.
 *
 *   - Each evaluation gets a large **circular badge** with the overall
 *     score and a tier color (green/yellow/red).
 *   - **Main-category pills** show inline; **subcategory** detail is
 *     hidden behind a per-row "Show detail" toggle.
 *   - Above 640px: scannable two-column rows. Below 640px: stacked
 *     cards with the badge at the top.
 *
 * Reuses `RatingPillComponent` (introduced by this PR) so the visual
 * language matches `FrontendOverviewView`'s My card tile (#0004).
 */
class FrontendMyEvaluationsView extends FrontendViewBase {

    /**
     * v3.110.215 (#846) — coach branch. Renders evaluations AUTHORED
     * by the current coach, filtered to "this week" + the trailing
     * 30 days for context. The KPI tile on the coach dashboard counts
     * `coach_id = current_user_id AND eval_date >= last Monday`; this
     * surface shows the same scope so the KPI value and the list agree.
     */
    public static function renderForCoach( int $coach_user_id ): void {
        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My evaluations', 'talenttrack' ) );
        self::renderHeader( __( 'My evaluations', 'talenttrack' ) );

        if ( $coach_user_id <= 0 ) {
            echo '<p><em>' . esc_html__( 'Sign in to see the evaluations you authored.', 'talenttrack' ) . '</em></p>';
            return;
        }

        // #920 — query now lives in `EvaluationsRepository::recentForCoach`
        // (mirrored by the new `GET /evaluations/recent` REST endpoint).
        // Default window is 30 days — wider than the strictly-this-week
        // KPI cut — so coaches always see some recent context.
        //
        // v4.20.27 (#1213) — honour `?days=<int>` so the
        // `MyEvaluationsThisWeek` KPI deep-link can narrow the view to
        // the same 7-day cut its `compute()` aggregates. Pre-fix, KPI
        // "3 this week" landed on a 30-day list rendering 11 rows —
        // breeding KPI ↔ list mistrust. Param clamped to [1, 90] so
        // URL tampering can't run unbounded windows.
        $days = isset( $_GET['days'] ) ? (int) wp_unslash( (string) $_GET['days'] ) : 30;
        if ( $days < 1 ) $days = 30;
        if ( $days > 90 ) $days = 90;

        $evals = ( new \TT\Infrastructure\Evaluations\EvaluationsRepository() )
            ->recentForCoach( $coach_user_id, $days );

        if ( empty( $evals ) ) {
            echo '<p><em>' . sprintf(
                /* translators: %d is the window size in days */
                esc_html__( 'No evaluations authored in the last %d days. New evaluations you record will appear here.', 'talenttrack' ),
                $days
            ) . '</em></p>';
            return;
        }

        echo '<p class="tt-mye-lead">'
            . sprintf(
                /* translators: %d is the window size in days */
                esc_html__( 'Evaluations you authored in the last %d days, newest first.', 'talenttrack' ),
                $days
            )
            . '</p>';

        echo '<div class="tt-report-card"><div class="tt-table-wrap"><table class="tt-table">';
        echo '<thead><tr>'
            . '<th>' . esc_html__( 'Date', 'talenttrack' ) . '</th>'
            . '<th>' . esc_html__( 'Player', 'talenttrack' ) . '</th>'
            . '<th>' . esc_html__( 'Type', 'talenttrack' ) . '</th>'
            . '<th>' . esc_html__( 'Match', 'talenttrack' ) . '</th>'
            . '</tr></thead><tbody>';
        foreach ( $evals as $e ) {
            $player_name = trim( ( (string) ( $e->first_name ?? '' ) ) . ' ' . ( (string) ( $e->last_name ?? '' ) ) );
            if ( $player_name === '' ) $player_name = '—';
            $detail_url = add_query_arg( [
                'tt_view' => 'evaluations',
                'id'      => (int) $e->id,
            ], remove_query_arg( [ 'tt_view' ] ) );
            $match_text = '';
            if ( ! empty( $e->opponent ) ) {
                $match_text = (string) $e->opponent;
                if ( ! empty( $e->game_result ) ) $match_text .= ' (' . (string) $e->game_result . ')';
            }
            echo '<tr>';
            echo '<td>' . esc_html( \TT\Shared\Dates\TTDate::date( (string) $e->eval_date ) ) . '</td>';
            echo '<td><a class="tt-link" href="' . esc_url( $detail_url ) . '">' . esc_html( $player_name ) . '</a></td>';
            // #806 — pre-localised by EvaluationsRepository so bypass
            // becomes structurally impossible. Falls back to the raw
            // type_name when the lookup row is missing.
            $type_disp = ! empty( $e->type_name_localised ) ? $e->type_name_localised : ( $e->type_name ?? '—' );
            echo '<td>' . esc_html( (string) $type_disp ) . '</td>';
            echo '<td>' . esc_html( $match_text !== '' ? $match_text : '—' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * The player / parent branch.
     *
     * #3478 — this used to query every evaluation the player had ever been
     * given and render each one's full subcategory breakdown into the page,
     * hidden: 2.5 MB and 4,368 rating rows for one child with 208
     * evaluations, plus two queries per row to build it. Decided 2026-09-16:
     * the current season by default, earlier seasons on request, and the
     * breakdown fetched from `GET /players/{id}/evaluations/{eid}/detail`
     * when a row is opened. The query and the cut live in
     * `PlayerEvaluationsReader`, which the REST route reads too.
     */
    public static function render( object $player ): void {
        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My evaluations', 'talenttrack' ) );
        self::renderHeader( __( 'My evaluations', 'talenttrack' ) );

        $player_id = (int) $player->id;
        $scope     = ( isset( $_GET['eval_scope'] ) && sanitize_key( (string) wp_unslash( $_GET['eval_scope'] ) ) === PlayerEvaluationsReader::SCOPE_ALL ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            ? PlayerEvaluationsReader::SCOPE_ALL
            : PlayerEvaluationsReader::SCOPE_CURRENT;

        $reader  = new PlayerEvaluationsReader();
        $evals   = $reader->listForPlayer( $player_id, $scope );
        $window  = $reader->window( $scope );
        $earlier = $reader->countOutside( $player_id, $scope );

        self::enqueueDetailScript( $player_id );
        self::renderScopeBar( $scope, $window['season_name'], $earlier );

        if ( empty( $evals ) ) {
            $msg = $earlier > 0
                ? __( 'No evaluations yet this season. Earlier seasons are one tap away above.', 'talenttrack' )
                : __( 'No evaluations yet. Your coaches will record them here as training and matches progress.', 'talenttrack' );
            echo '<p><em>' . esc_html( $msg ) . '</em></p>';
            return;
        }

        $max      = (float) QueryHelpers::get_config( 'rating_max', '10' );
        $eval_ids = array_map( static fn( $e ) => (int) $e->id, $evals );
        $overalls = ( new EvalRatingsRepository() )->overallRatingsForEvaluations( $eval_ids );

        // Summary KPIs over the rows in the window. Newest-first, so the first
        // rated entry is the current rating and the next one the prior cut.
        $rated_values = [];
        foreach ( $evals as $e ) {
            $v = $overalls[ (int) $e->id ]['value'] ?? null;
            if ( $v !== null ) $rated_values[] = (float) $v;
        }
        $latest_rating = $rated_values[0] ?? null;
        $prev_rating   = $rated_values[1] ?? null;
        ?>
        <div class="tt-mye-summary" role="group" aria-label="<?php esc_attr_e( 'Evaluation summary', 'talenttrack' ); ?>">
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \TT\Shared\Frontend\Components\FrontendAppChrome::kpiTile( [
                'label' => $scope === PlayerEvaluationsReader::SCOPE_CURRENT && $window['season_name'] !== null
                    ? __( 'Evaluations this season', 'talenttrack' )
                    : __( 'Evaluations', 'talenttrack' ),
                'value' => (string) number_format_i18n( count( $evals ) ),
            ] );

            if ( $latest_rating !== null ) {
                $kpi = [
                    'label' => __( 'Latest rating', 'talenttrack' ),
                    'value' => number_format_i18n( $latest_rating, 1 ) . ' / ' . number_format_i18n( $max, 0 ),
                ];
                if ( $prev_rating !== null ) {
                    $diff = $latest_rating - $prev_rating;
                    if ( abs( $diff ) < 0.05 ) {
                        $kpi['trend'] = 'flat';
                        $kpi['delta'] = __( 'No change', 'talenttrack' );
                    } else {
                        $kpi['trend'] = $diff > 0 ? 'up' : 'down';
                        $kpi['delta'] = ( $diff > 0 ? '+' : '−' ) . number_format_i18n( abs( $diff ), 1 );
                    }
                }
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo \TT\Shared\Frontend\Components\FrontendAppChrome::kpiTile( $kpi );
            }
            ?>
        </div>
        <ol class="tt-mye-list" aria-label="<?php esc_attr_e( 'Evaluations, newest first', 'talenttrack' ); ?>">
            <?php foreach ( $evals as $ev ) :
                $eid           = (int) $ev->id;
                $overall_value = $overalls[ $eid ]['value'] ?? null;
                $row_id        = 'tt-mye-row-' . $eid;
                $detail_id     = $row_id . '-detail';
                $main_pills    = $reader->mainPills( $eid );
                $has_detail    = $reader->hasDetail( $eid );
                ?>
                <li class="tt-mye-item" id="<?php echo esc_attr( $row_id ); ?>">
                    <div class="tt-mye-badge-wrap">
                        <?php if ( $overall_value !== null ) :
                            echo RatingPillComponent::badge( (float) $overall_value, $max ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component escapes.
                        else : ?>
                            <span class="tt-rp-badge tt-rp-attention" aria-label="<?php esc_attr_e( 'No overall rating yet', 'talenttrack' ); ?>" role="img"><span aria-hidden="true">—</span></span>
                        <?php endif; ?>
                        <div class="tt-mye-meta">
                            <div class="tt-mye-date"><?php echo esc_html( \TT\Shared\Dates\TTDate::date( (string) $ev->eval_date ) ); ?></div>
                            <div class="tt-mye-type"><?php echo esc_html( (string) ( $ev->type_name ?: '—' ) ); ?></div>
                            <?php if ( ! empty( $ev->coach_name ) ) : ?>
                                <div class="tt-mye-coach"><?php
                                    printf(
                                        /* translators: %s is the coach's display name */
                                        esc_html__( 'by %s', 'talenttrack' ),
                                        esc_html( (string) $ev->coach_name )
                                    );
                                ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tt-mye-body">
                        <?php if ( ! empty( $ev->opponent ) ) : ?>
                            <p class="tt-mye-match">
                                <?php
                                printf(
                                    /* translators: 1: opponent name, 2: match result */
                                    esc_html__( 'vs %1$s (%2$s)', 'talenttrack' ),
                                    esc_html( (string) $ev->opponent ),
                                    esc_html( (string) ( $ev->game_result ?: '—' ) )
                                );
                                ?>
                            </p>
                        <?php endif; ?>

                        <?php if ( ! empty( $main_pills ) ) : ?>
                            <div class="tt-mye-pills">
                                <?php foreach ( $main_pills as $pill ) : ?>
                                    <?php echo RatingPillComponent::pill( $pill['label'], $pill['rating'], $max ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component escapes. ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php
                        // #1386 — coach's player-facing feedback. Distinct from
                        // the staff-only `notes` field, which the reader never
                        // selects.
                        $feedback = trim( (string) ( $ev->player_feedback ?? '' ) );
                        if ( $feedback !== '' ) : ?>
                            <div class="tt-mye-feedback">
                                <div class="tt-mye-feedback-label"><?php esc_html_e( 'Feedback from your coach', 'talenttrack' ); ?></div>
                                <p class="tt-mye-feedback-text"><?php echo nl2br( esc_html( $feedback ) ); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ( $has_detail ) : ?>
                            <button type="button" class="tt-mye-toggle" data-tt-mye-toggle data-eval-id="<?php echo (int) $eid; ?>" aria-expanded="false" aria-controls="<?php echo esc_attr( $detail_id ); ?>">
                                <span class="tt-mye-toggle-show"><?php esc_html_e( 'Show detail', 'talenttrack' ); ?></span>
                                <span class="tt-mye-toggle-hide"><?php esc_html_e( 'Hide detail', 'talenttrack' ); ?></span>
                            </button>
                            <div class="tt-mye-detail" id="<?php echo esc_attr( $detail_id ); ?>" aria-live="polite" hidden></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }

    /**
     * #3478 — which cut is showing, and the way to the other one.
     *
     * A link, not a JS control: it works without script, survives a reload
     * and can be bookmarked, and "earlier seasons" is a rare enough request
     * that a page load is the right price for it.
     */
    private static function renderScopeBar( string $scope, ?string $season_name, int $earlier ): void {
        if ( $scope === PlayerEvaluationsReader::SCOPE_CURRENT && ( $season_name === null || $earlier === 0 ) ) {
            return; // Nothing narrowed, so nothing to widen.
        }
        echo '<p class="tt-mye-scope">';
        if ( $scope === PlayerEvaluationsReader::SCOPE_CURRENT ) {
            echo esc_html( sprintf(
                /* translators: %s: season name, e.g. 2026/2027 */
                __( 'Season %s.', 'talenttrack' ),
                (string) $season_name
            ) ) . ' ';
            echo '<a class="tt-link" href="' . esc_url( add_query_arg( 'eval_scope', PlayerEvaluationsReader::SCOPE_ALL ) ) . '">'
                . esc_html( sprintf(
                    /* translators: %d: number of evaluations from earlier seasons */
                    _n( 'Show %d evaluation from earlier seasons', 'Show %d evaluations from earlier seasons', $earlier, 'talenttrack' ),
                    $earlier
                ) )
                . '</a>';
        } else {
            echo esc_html__( 'All seasons.', 'talenttrack' ) . ' ';
            echo '<a class="tt-link" href="' . esc_url( remove_query_arg( 'eval_scope' ) ) . '">'
                . esc_html__( 'Show this season only', 'talenttrack' )
                . '</a>';
        }
        echo '</p>';
    }

    /** #3478 — the detail fetch, enqueued rather than inlined (CLAUDE.md §2). */
    private static function enqueueDetailScript( int $player_id ): void {
        wp_enqueue_script(
            'tt-frontend-my-evaluations',
            TT_PLUGIN_URL . 'assets/js/frontend-my-evaluations.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-frontend-my-evaluations', 'TTMyEvaluations', [
            'detailUrl' => esc_url_raw( rest_url( 'talenttrack/v1/players/' . $player_id . '/evaluations/' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'i18n'      => [
                'loading' => __( 'Loading…', 'talenttrack' ),
                'error'   => __( 'The detail could not be loaded. Try again.', 'talenttrack' ),
                'empty'   => __( 'No category breakdown for this evaluation.', 'talenttrack' ),
            ],
        ] );
    }
}
