<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
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

    public static function render( object $player ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'My evaluations', 'talenttrack' ) );

        global $wpdb;
        $p = $wpdb->prefix;

        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, lt.name AS type_name, u.display_name AS coach_name
             FROM {$p}tt_evaluations e
             LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id = lt.id
             LEFT JOIN {$wpdb->users} u ON e.coach_id = u.ID
             WHERE e.player_id = %d AND e.archived_at IS NULL
             ORDER BY e.eval_date DESC",
            (int) $player->id
        ) );

        if ( empty( $evals ) ) {
            echo '<p><em>' . esc_html__( 'No evaluations yet. Your coaches will record them here as training and matches progress.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $max         = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $eval_ids    = array_map( fn( $e ) => (int) $e->id, $evals );
        $overalls    = ( new EvalRatingsRepository() )->overallRatingsForEvaluations( $eval_ids );
        ?>
        <ol class="tt-mye-list" aria-label="<?php esc_attr_e( 'Evaluations, newest first', 'talenttrack' ); ?>">
            <?php foreach ( $evals as $ev ) :
                $eid           = (int) $ev->id;
                $full          = QueryHelpers::get_evaluation( $eid );
                $overall_value = $overalls[ $eid ]['value'] ?? null;
                $row_id        = 'tt-mye-row-' . $eid;
                $detail_id     = $row_id . '-detail';

                // Group ratings by parent so we can show main pills inline
                // and tuck subcategories behind the toggle.
                $main_pills = [];
                $sub_groups = [];
                if ( $full && ! empty( $full->ratings ) ) {
                    foreach ( $full->ratings as $r ) {
                        $label = EvalCategoriesRepository::displayLabel( (string) $r->category_name );
                        if ( empty( $r->category_parent_id ) ) {
                            $main_pills[ (int) $r->category_id ] = [ 'label' => $label, 'rating' => (float) $r->rating ];
                        } else {
                            $sub_groups[ (int) $r->category_parent_id ][] = [ 'label' => $label, 'rating' => (float) $r->rating ];
                        }
                    }
                }
                $has_detail = ! empty( $sub_groups );
                ?>
                <li class="tt-mye-item" id="<?php echo esc_attr( $row_id ); ?>">
                    <div class="tt-mye-badge-wrap">
                        <?php if ( $overall_value !== null ) :
                            echo RatingPillComponent::badge( (float) $overall_value, $max );
                        else : ?>
                            <span class="tt-rp-badge tt-rp-attention" aria-label="<?php esc_attr_e( 'No overall rating yet', 'talenttrack' ); ?>" role="img"><span aria-hidden="true">—</span></span>
                        <?php endif; ?>
                        <div class="tt-mye-meta">
                            <div class="tt-mye-date"><?php echo esc_html( (string) $ev->eval_date ); ?></div>
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
                                    esc_html( (string) ( $ev->match_result ?: '—' ) )
                                );
                                ?>
                            </p>
                        <?php endif; ?>

                        <?php if ( ! empty( $main_pills ) ) : ?>
                            <div class="tt-mye-pills">
                                <?php foreach ( $main_pills as $main_id => $row ) : ?>
                                    <?php echo RatingPillComponent::pill( $row['label'], $row['rating'], $max ); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $has_detail ) : ?>
                            <button type="button" class="tt-mye-toggle" data-tt-mye-toggle aria-expanded="false" aria-controls="<?php echo esc_attr( $detail_id ); ?>">
                                <span class="tt-mye-toggle-show"><?php esc_html_e( 'Show detail', 'talenttrack' ); ?></span>
                                <span class="tt-mye-toggle-hide"><?php esc_html_e( 'Hide detail', 'talenttrack' ); ?></span>
                            </button>
                            <div class="tt-mye-detail" id="<?php echo esc_attr( $detail_id ); ?>" hidden>
                                <?php foreach ( $sub_groups as $main_id => $subs ) :
                                    $main_label = $main_pills[ $main_id ]['label'] ?? '';
                                    ?>
                                    <div class="tt-mye-detail-group">
                                        <?php if ( $main_label !== '' ) : ?>
                                            <div class="tt-mye-detail-heading"><?php echo esc_html( $main_label ); ?></div>
                                        <?php endif; ?>
                                        <ul class="tt-mye-detail-list">
                                            <?php foreach ( $subs as $sub ) : ?>
                                                <li>
                                                    <span class="tt-mye-detail-label"><?php echo esc_html( $sub['label'] ); ?></span>
                                                    <span class="tt-mye-detail-rating"><?php echo esc_html( number_format_i18n( $sub['rating'], 1 ) ); ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>

        <script>
        // Document-level delegation so the toggle keeps working when the
        // dashboard is rendered (or re-rendered) via REST/fetch — the
        // previous querySelectorAll-on-IIFE only bound on initial DOM
        // parse and the click silently failed after any re-render.
        (function(){
            if (window.__ttMyEvalsBound) return;
            window.__ttMyEvalsBound = true;
            document.addEventListener('click', function(e){
                var btn = e.target && e.target.closest ? e.target.closest('[data-tt-mye-toggle]') : null;
                if (!btn) return;
                var open = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', open ? 'false' : 'true');
                var detail = document.getElementById(btn.getAttribute('aria-controls'));
                if (detail) detail.hidden = open;
            });
        })();
        </script>
        <?php
    }
}
