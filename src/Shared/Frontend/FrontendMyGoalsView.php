<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;

/**
 * FrontendMyGoalsView — the "My goals" tile destination.
 *
 * v3.0.0 slice 3. Shows development goals set by the player's
 * coaches, grouped visually by status via color-coded cards.
 */
class FrontendMyGoalsView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'My goals', 'talenttrack' ) );

        global $wpdb;
        $p = $wpdb->prefix;

        $goals = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_goals
             WHERE player_id = %d AND archived_at IS NULL
             ORDER BY created_at DESC",
            (int) $player->id
        ) );

        if ( empty( $goals ) ) {
            echo '<p><em>' . esc_html__( 'No goals assigned yet. Your coaches will add development goals here as you progress.', 'talenttrack' ) . '</em></p>';
            return;
        }

        ?>
        <div class="tt-goals-list">
            <?php foreach ( $goals as $g ) : ?>
                <div class="tt-goal-item tt-status-<?php echo esc_attr( (string) $g->status ); ?>">
                    <h4><?php echo esc_html( (string) $g->title ); ?></h4>
                    <?php if ( ! empty( $g->description ) ) : ?>
                        <p><?php echo esc_html( (string) $g->description ); ?></p>
                    <?php endif; ?>
                    <span class="tt-status-badge"><?php echo esc_html( LabelTranslator::goalStatus( (string) $g->status ) ); ?></span>
                    <?php if ( ! empty( $g->due_date ) ) : ?>
                        <small><?php esc_html_e( 'Due:', 'talenttrack' ); ?> <?php echo esc_html( (string) $g->due_date ); ?></small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
