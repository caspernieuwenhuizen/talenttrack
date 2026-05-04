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

        $player = $player_id > 0 ? QueryHelpers::get_player( $player_id ) : null;

        // v3.92.1 — breadcrumb chain. When player is loaded, chain
        // through Players → [player name]; otherwise just Dashboard.
        if ( $player ) {
            $player_name = QueryHelpers::player_display_name( $player );
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Capture behaviour & potential', 'talenttrack' ),
                [
                    \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'players', __( 'Players', 'talenttrack' ) ),
                    \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'players', $player_name, [ 'id' => $player_id ] ),
                ]
            );
        } else {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Capture behaviour & potential', 'talenttrack' ) );
        }

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
                $related_activity = isset( $_POST['related_activity_id'] )
                    ? absint( $_POST['related_activity_id'] )
                    : 0;
                $rating = isset( $_POST['rating'] ) ? (float) $_POST['rating'] : 0.0;
                $notes  = isset( $_POST['notes'] )  ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '';
                // v3.74.2 — gate against the configured rating scale so
                // clubs that customise min/max/step still validate
                // correctly (was hardcoded 1.0–5.0).
                $rmin = (float) QueryHelpers::get_config( 'rating_min', '1' );
                $rmax = (float) QueryHelpers::get_config( 'rating_max', '5' );
                if ( $rating >= $rmin && $rating <= $rmax ) {
                    ( new PlayerBehaviourRatingsRepository() )->create( [
                        'player_id'           => $player_id,
                        'rating'              => $rating,
                        'notes'               => $notes !== '' ? $notes : null,
                        // v3.74.2 — #15: behaviour ratings can be tied
                        // to a specific completed activity so the
                        // history reads as "during game-X" instead of
                        // a free-floating score.
                        'related_activity_id' => $related_activity > 0 ? $related_activity : null,
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
            // v3.74.2 — pull rating-scale settings + the player's recent
            // completed activities so the form matches club config and
            // can tie a rating to "during game X".
            $rmin = (float) QueryHelpers::get_config( 'rating_min', '1' );
            $rmax = (float) QueryHelpers::get_config( 'rating_max', '5' );
            $rstep = (float) QueryHelpers::get_config( 'rating_step', '1' );
            $rstep = $rstep > 0 ? $rstep : 1.0;
            $recent_activities = self::loadRecentActivitiesForPlayer( $player_id, 20 );
            ?>
            <section>
                <h3><?php esc_html_e( 'Record a behaviour rating', 'talenttrack' ); ?></h3>
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <input type="hidden" name="kind" value="behaviour" />
                    <p>
                        <label class="tt-field-label tt-field-required" for="tt-bh-rating">
                            <?php
                            printf(
                                /* translators: 1: scale min, 2: scale max */
                                esc_html__( 'Rating (%1$s – %2$s)', 'talenttrack' ),
                                esc_html( (string) $rmin ),
                                esc_html( (string) $rmax )
                            );
                            ?>
                        </label>
                        <select id="tt-bh-rating" name="rating" required class="tt-input">
                            <?php
                            // Step through the configured rating scale.
                            $val = $rmin;
                            while ( $val <= $rmax + 0.0001 ) {
                                $display = $rstep < 1 ? rtrim( rtrim( number_format( $val, 2, '.', '' ), '0' ), '.' ) : (string) (int) $val;
                                printf( '<option value="%s">%s</option>', esc_attr( (string) $val ), esc_html( $display ) );
                                $val += $rstep;
                            }
                            ?>
                        </select>
                    </p>
                    <?php if ( ! empty( $recent_activities ) ) : ?>
                    <p>
                        <label class="tt-field-label" for="tt-bh-activity"><?php esc_html_e( 'Related activity (optional)', 'talenttrack' ); ?></label>
                        <select id="tt-bh-activity" name="related_activity_id" class="tt-input">
                            <option value="0"><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                            <?php foreach ( $recent_activities as $act ) : ?>
                                <option value="<?php echo (int) $act->id; ?>">
                                    <?php echo esc_html( sprintf( '%s · %s', (string) $act->session_date, (string) $act->title ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <?php endif; ?>
                    <p>
                        <label class="tt-field-label" for="tt-bh-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                        <textarea id="tt-bh-notes" class="tt-input" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Optional context — e.g. "responded well to substitution", "leadership in warm-up".', 'talenttrack' ); ?>"></textarea>
                    </p>
                    <p><button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save behaviour rating', 'talenttrack' ); ?></button></p>
                </form>

                <?php if ( ! empty( $recent_behaviour ) ) : ?>
                    <h4 style="margin-top:18px;"><?php esc_html_e( 'Recent ratings', 'talenttrack' ); ?></h4>
                    <ul class="tt-stack">
                        <?php foreach ( $recent_behaviour as $b ) :
                            // v3.74.2 — show rated_at (the meaningful "this
                            // happened on" date) instead of created_at;
                            // for legacy rows where rated_at is null,
                            // fall back. Also surface the related
                            // activity link.
                            $when = (string) ( $b->rated_at ?? $b->created_at ?? '' );
                            $related_activity_id = (int) ( $b->related_activity_id ?? 0 );
                            ?>
                            <li>
                                <strong><?php echo esc_html( number_format_i18n( (float) $b->rating, 1 ) ); ?></strong>
                                <span class="tt-muted"> &middot; <?php echo esc_html( $when ); ?></span>
                                <?php if ( $related_activity_id > 0 ) : ?>
                                    <span class="tt-muted"> &middot;
                                        <?php
                                        $act_url = \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'activities', $related_activity_id );
                                        echo \TT\Shared\Frontend\Components\RecordLink::inline( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                            __( 'View activity', 'talenttrack' ),
                                            $act_url
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
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
                        // v3.74.2 — show set_at (the meaningful "this is
                        // when we judged it" date) instead of created_at.
                        $when_set = (string) ( $latest_potential->set_at ?? $latest_potential->created_at ?? '' );
                        printf(
                            /* translators: 1: band 2: timestamp */
                            esc_html__( 'Current: %1$s (recorded on %2$s).', 'talenttrack' ),
                            esc_html( $bands[ (string) $latest_potential->potential_band ] ?? (string) $latest_potential->potential_band ),
                            esc_html( $when_set )
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </section>
            <?php
        endif;

        echo '</div>';
    }

    /**
     * v3.74.2 — short list of completed activities the player attended,
     * used as the "Related activity" dropdown on the behaviour-rating
     * form (#15). Filters to status='completed' so coaches don't tie a
     * rating to an activity that hasn't happened yet.
     *
     * @return list<object>
     */
    private static function loadRecentActivitiesForPlayer( int $player_id, int $limit = 20 ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT a.id, a.session_date, a.title
               FROM {$p}tt_activities a
               JOIN {$p}tt_attendance att ON att.activity_id = a.id
              WHERE att.player_id = %d
                AND a.activity_status_key = %s
                AND a.archived_at IS NULL
              ORDER BY a.session_date DESC
              LIMIT %d",
            $player_id, 'completed', $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }
}
