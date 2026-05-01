<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Players\Repositories\PlayerBehaviourRatingsRepository;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * FrontendPlayerStatusCaptureView — single consolidated entry point
 * for recording behaviour + potential against a player (#0063).
 *
 * Replaces the user's "where do I register behaviour / where do I
 * register potential — both confusing" complaint with one screen
 * reachable from the player detail. Two side-by-side forms POST
 * to the existing REST endpoints (`/players/{id}/behaviour-ratings`
 * and `/players/{id}/potential`).
 *
 * Caps:
 *   - Behaviour rating: `tt_rate_player_behaviour`.
 *   - Potential:        `tt_set_player_potential`.
 * Both are fine-grained; a coach without potential-rights can still
 * record behaviour, and the form for the missing capability is
 * suppressed rather than rendered as an error.
 */
final class FrontendPlayerStatusCaptureView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_player_status_capture';
    public const NONCE_FIELD  = '_tt_status_capture_nonce';

    public static function render( int $user_id, bool $is_admin ): void {
        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        $dash      = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
        $back_url  = $player_id > 0
            ? add_query_arg( [ 'tt_view' => 'players', 'id' => $player_id ], $dash )
            : add_query_arg( [ 'tt_view' => 'players' ], $dash );
        FrontendBackButton::render( $back_url );

        $player = $player_id > 0 ? QueryHelpers::get_player( $player_id ) : null;
        if ( ! $player ) {
            self::renderHeader( __( 'Player not found', 'talenttrack' ) );
            return;
        }
        if ( ! current_user_can( 'tt_rate_player_behaviour' ) && ! current_user_can( 'tt_set_player_potential' ) ) {
            self::renderHeader( __( 'Capture behaviour & potential', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to record behaviour or potential ratings.', 'talenttrack' ) . '</p>';
            return;
        }

        // Handle POST.
        $flash = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST[ self::NONCE_FIELD ] )
             && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            $kind = isset( $_POST['kind'] ) ? sanitize_key( (string) $_POST['kind'] ) : '';
            if ( $kind === 'behaviour' && current_user_can( 'tt_rate_player_behaviour' ) ) {
                $rating = isset( $_POST['rating'] ) ? (float) $_POST['rating'] : 0.0;
                $notes  = isset( $_POST['notes'] )  ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '';
                if ( $rating >= 1.0 && $rating <= 5.0 ) {
                    ( new PlayerBehaviourRatingsRepository() )->create( [
                        'player_id' => $player_id,
                        'rating'    => $rating,
                        'notes'     => $notes !== '' ? $notes : null,
                    ] );
                    $flash = __( 'Behaviour rating saved.', 'talenttrack' );
                }
            } elseif ( $kind === 'potential' && current_user_can( 'tt_set_player_potential' ) ) {
                $band  = isset( $_POST['potential_band'] ) ? sanitize_key( (string) $_POST['potential_band'] ) : '';
                $notes = isset( $_POST['notes'] )          ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '';
                $valid = [ 'first_team', 'professional_elsewhere', 'semi_pro', 'top_amateur', 'recreational' ];
                if ( in_array( $band, $valid, true ) ) {
                    ( new PlayerPotentialRepository() )->create( [
                        'player_id'      => $player_id,
                        'potential_band' => $band,
                        'notes'          => $notes !== '' ? $notes : null,
                    ] );
                    $flash = __( 'Potential band saved.', 'talenttrack' );
                }
            }
        }

        self::enqueueAssets();
        self::renderHeader( sprintf(
            /* translators: %s = player name */
            __( 'Behaviour & potential — %s', 'talenttrack' ),
            QueryHelpers::player_display_name( $player )
        ) );

        if ( $flash !== '' ) {
            echo '<div class="tt-notice notice-success" style="background:#e9f5e9; border-left:4px solid #2c8a2c; padding:8px 12px; margin: 8px 0 16px;">'
                . esc_html( $flash ) . '</div>';
        }

        $recent_behaviour = ( new PlayerBehaviourRatingsRepository() )->listForPlayer( $player_id, 5 );
        $latest_potential = ( new PlayerPotentialRepository() )->latestFor( $player_id );

        echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">';

        // Behaviour column
        if ( current_user_can( 'tt_rate_player_behaviour' ) ) :
            ?>
            <section>
                <h3><?php esc_html_e( 'Record a behaviour rating', 'talenttrack' ); ?></h3>
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <input type="hidden" name="kind" value="behaviour" />
                    <p>
                        <label class="tt-field-label tt-field-required" for="tt-bh-rating"><?php esc_html_e( 'Rating (1 – 5)', 'talenttrack' ); ?></label>
                        <select id="tt-bh-rating" name="rating" required class="tt-input">
                            <?php for ( $r = 1; $r <= 5; $r++ ) : ?>
                                <option value="<?php echo (int) $r; ?>"><?php echo (int) $r; ?></option>
                            <?php endfor; ?>
                        </select>
                    </p>
                    <p>
                        <label class="tt-field-label" for="tt-bh-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                        <textarea id="tt-bh-notes" class="tt-input" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Optional context — e.g. "responded well to substitution", "leadership in warm-up".', 'talenttrack' ); ?>"></textarea>
                    </p>
                    <p><button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save behaviour rating', 'talenttrack' ); ?></button></p>
                </form>

                <?php if ( ! empty( $recent_behaviour ) ) : ?>
                    <h4 style="margin-top:18px;"><?php esc_html_e( 'Recent ratings', 'talenttrack' ); ?></h4>
                    <ul class="tt-stack">
                        <?php foreach ( $recent_behaviour as $b ) : ?>
                            <li>
                                <strong><?php echo esc_html( number_format_i18n( (float) $b->rating, 1 ) ); ?></strong>
                                <span class="tt-muted"> &middot; <?php echo esc_html( (string) ( $b->created_at ?? '' ) ); ?></span>
                                <?php if ( ! empty( $b->notes ) ) : ?>
                                    <div class="tt-muted" style="font-size:12px;"><?php echo esc_html( (string) $b->notes ); ?></div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <?php
        endif;

        // Potential column
        if ( current_user_can( 'tt_set_player_potential' ) ) :
            ?>
            <section>
                <h3><?php esc_html_e( 'Set potential', 'talenttrack' ); ?></h3>
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <input type="hidden" name="kind" value="potential" />
                    <p>
                        <label class="tt-field-label tt-field-required" for="tt-pot-band"><?php esc_html_e( 'Potential band', 'talenttrack' ); ?></label>
                        <select id="tt-pot-band" name="potential_band" required class="tt-input">
                            <?php
                            $bands = [
                                'first_team'             => __( 'First team', 'talenttrack' ),
                                'professional_elsewhere' => __( 'Professional elsewhere', 'talenttrack' ),
                                'semi_pro'               => __( 'Semi-pro', 'talenttrack' ),
                                'top_amateur'            => __( 'Top amateur', 'talenttrack' ),
                                'recreational'           => __( 'Recreational', 'talenttrack' ),
                            ];
                            $current_band = $latest_potential ? (string) $latest_potential->potential_band : '';
                            foreach ( $bands as $code => $label ) :
                                ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_band, $code ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <label class="tt-field-label" for="tt-pot-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                        <textarea id="tt-pot-notes" class="tt-input" name="notes" rows="3" placeholder="<?php esc_attr_e( "Optional rationale — e.g. why you've revised the band up or down.", 'talenttrack' ); ?>"></textarea>
                    </p>
                    <p><button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save potential', 'talenttrack' ); ?></button></p>
                </form>

                <?php if ( $latest_potential ) : ?>
                    <p class="tt-muted" style="margin-top:12px;">
                        <?php
                        printf(
                            /* translators: 1: band 2: timestamp */
                            esc_html__( 'Current: %1$s (recorded %2$s).', 'talenttrack' ),
                            esc_html( $bands[ (string) $latest_potential->potential_band ] ?? (string) $latest_potential->potential_band ),
                            esc_html( (string) ( $latest_potential->created_at ?? '' ) )
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </section>
            <?php
        endif;

        echo '</div>';
    }
}
