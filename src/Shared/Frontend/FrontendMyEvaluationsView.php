<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendMyEvaluationsView — the "My evaluations" tile destination.
 *
 * v3.0.0 slice 3. Lists every evaluation recorded for the logged-in
 * player, most-recent first. Shows date, type, coach, and rating
 * pills. Match-type evals also show opponent and result.
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

        ?>
        <table class="tt-table tt-table-sortable">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Coach', 'talenttrack' ); ?></th>
                    <th data-tt-sort="off"><?php esc_html_e( 'Ratings', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $evals as $ev ) :
                    $full = QueryHelpers::get_evaluation( (int) $ev->id );
                    ?>
                    <tr>
                        <td><?php echo esc_html( (string) $ev->eval_date ); ?></td>
                        <td><?php echo esc_html( (string) ( $ev->type_name ?: '—' ) ); ?></td>
                        <td><?php echo esc_html( (string) $ev->coach_name ); ?></td>
                        <td>
                            <?php if ( ! empty( $ev->opponent ) ) : ?>
                                <small>
                                    <?php
                                    printf(
                                        /* translators: 1: opponent name, 2: match result */
                                        esc_html__( 'vs %1$s (%2$s)', 'talenttrack' ),
                                        esc_html( (string) $ev->opponent ),
                                        esc_html( (string) ( $ev->match_result ?: '—' ) )
                                    );
                                    ?>
                                </small><br/>
                            <?php endif; ?>
                            <?php if ( $full && ! empty( $full->ratings ) ) : ?>
                                <?php foreach ( $full->ratings as $r ) : ?>
                                    <span class="tt-rating-pill"><?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $r->category_name ) ); ?>: <?php echo esc_html( (string) $r->rating ); ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
