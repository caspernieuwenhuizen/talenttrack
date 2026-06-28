<?php
namespace TT\Modules\Strava\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendStravaView (#2061, epic #2002) — the per-player "Connect with
 * Strava" panel. Reachable at `?tt_view=strava` (the viewer's own player)
 * or `?tt_view=strava&player_id=N` for a child / viewable player, the
 * same Me-view subject resolution the other `My*` views use.
 *
 * Mobile-first, vanilla JS, REST-driven (CLAUDE.md §2, §4): the shell is
 * static markup; `frontend-strava.js` fetches `/players/{id}/strava/status`
 * + `/activities` and drives connect / disconnect against the REST API.
 *
 * Gate 2 — the connect button is gated behind an inline consent checkbox;
 * the server additionally refuses to mint the authorize URL without a
 * recorded consent (#2060), so the checkbox is the affordance, not the
 * enforcement.
 *
 * Gate 1 — only distance / duration / pace / elevation are shown; there is
 * no heart-rate data to render.
 */
final class FrontendStravaView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Strava', 'talenttrack' ) );
        self::renderHeader( __( 'Strava activity', 'talenttrack' ) );
        self::renderPanel( $player );
    }

    /**
     * The panel markup only — no breadcrumb / header chrome — so it can be
     * embedded as a tab on the player detail view (#2061) as well as served
     * as the standalone `?tt_view=strava` page.
     */
    public static function renderPanel( object $player ): void {
        self::enqueueViewAssets( (int) $player->id );

        $notice = isset( $_GET['tt_strava'] ) ? sanitize_key( (string) $_GET['tt_strava'] ) : '';
        ?>
        <div class="tt-strava" data-tt-strava data-player-id="<?php echo (int) $player->id; ?>">

            <?php if ( $notice !== '' ) : ?>
                <div class="tt-strava__flash tt-strava__flash--<?php echo esc_attr( $notice === 'connected' ? 'ok' : 'warn' ); ?>" role="status">
                    <?php
                    if ( $notice === 'connected' ) {
                        esc_html_e( 'Your Strava account is now connected.', 'talenttrack' );
                    } elseif ( $notice === 'denied' ) {
                        esc_html_e( 'Strava connection was cancelled.', 'talenttrack' );
                    } else {
                        esc_html_e( 'Something went wrong connecting to Strava. Please try again.', 'talenttrack' );
                    }
                    ?>
                </div>
            <?php endif; ?>

            <p class="tt-strava__intro">
                <?php esc_html_e( 'Connect a Strava account to bring training runs, rides and conditioning work onto this player\'s timeline. Distance, duration, pace and elevation only — no heart-rate data is imported.', 'talenttrack' ); ?>
            </p>

            <div class="tt-strava__status" data-tt-strava-status aria-live="polite">
                <p class="tt-strava__loading"><?php esc_html_e( 'Loading…', 'talenttrack' ); ?></p>
            </div>

            <!-- Not-connected panel (shown by JS when status.connected is false) -->
            <div class="tt-strava__connect" data-tt-strava-connect hidden>
                <label class="tt-strava__consent">
                    <input type="checkbox" data-tt-strava-consent>
                    <span><?php esc_html_e( 'I agree to share this player\'s Strava activity data (distance, duration, pace, elevation) with the academy.', 'talenttrack' ); ?></span>
                </label>
                <button type="button" class="tt-btn tt-btn-primary tt-strava__connect-btn" data-tt-strava-connect-btn disabled>
                    <?php esc_html_e( 'Connect with Strava', 'talenttrack' ); ?>
                </button>
            </div>

            <!-- Connected panel (shown by JS when status.connected is true) -->
            <div class="tt-strava__connected" data-tt-strava-connected hidden>
                <p class="tt-strava__connected-meta" data-tt-strava-meta></p>
                <button type="button" class="tt-btn tt-btn-secondary tt-strava__disconnect-btn" data-tt-strava-disconnect-btn>
                    <?php esc_html_e( 'Disconnect', 'talenttrack' ); ?>
                </button>
            </div>

            <div class="tt-strava__msg" data-tt-strava-msg role="status" aria-live="polite"></div>

            <h3 class="tt-strava__activities-title"><?php esc_html_e( 'Recent activities', 'talenttrack' ); ?></h3>
            <ul class="tt-strava__activities" data-tt-strava-activities>
                <li class="tt-strava__empty"><?php esc_html_e( 'No activities imported yet.', 'talenttrack' ); ?></li>
            </ul>
        </div>
        <?php
    }

    private static function enqueueViewAssets( int $player_id ): void {
        wp_enqueue_style(
            'tt-frontend-strava',
            TT_PLUGIN_URL . 'assets/css/frontend-strava.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-strava',
            TT_PLUGIN_URL . 'assets/js/frontend-strava.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-frontend-strava', 'TT_Strava', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'player_id'  => $player_id,
            'i18n'       => [
                'connecting'    => __( 'Connecting…', 'talenttrack' ),
                'disconnecting' => __( 'Disconnecting…', 'talenttrack' ),
                'disconnect'    => __( 'Disconnect', 'talenttrack' ),
                'connected_meta'=> __( 'Connected. Last synced: %s', 'talenttrack' ),
                'never_synced'  => __( 'Connected. No activities synced yet.', 'talenttrack' ),
                'not_connected' => __( 'Not connected.', 'talenttrack' ),
                'not_configured'=> __( 'Strava is not set up for this academy yet.', 'talenttrack' ),
                'error'         => __( 'Something went wrong. Please try again.', 'talenttrack' ),
                'confirm_disc'  => __( 'Disconnect this Strava account? Imported activities will be archived.', 'talenttrack' ),
                'no_activities' => __( 'No activities imported yet.', 'talenttrack' ),
                'km'            => __( 'km', 'talenttrack' ),
                'min'           => __( 'min', 'talenttrack' ),
            ],
        ] );
    }
}
