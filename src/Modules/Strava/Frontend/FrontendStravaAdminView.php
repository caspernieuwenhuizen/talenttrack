<?php
namespace TT\Modules\Strava\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Strava\StravaConfig;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendStravaAdminView (#2127, epic #2002) — the operator console for the
 * club-wide Strava integration. Reachable at `?tt_view=strava-admin`, surfaced
 * from Configuration → Integrations next to the Spond tile.
 *
 * Where FrontendStravaView is the per-player connect panel, this is the
 * academy-wide setup + health view that answers "is Strava configured, is the
 * webhook live, and whose training is actually flowing in?". Three sections:
 *
 *   - App credentials — the developer-app Client ID + secret (POST /strava/app).
 *     The secret is write-only: never echoed, the field stays blank when set.
 *   - Webhook subscription — the single club-wide push subscription's status,
 *     with Create / Re-verify / Delete (…/strava/webhook/subscription).
 *   - Connected players — a read-only roster from GET /strava/connections.
 *
 * Compose-only (CLAUDE.md §4): the shell reads StravaConfig flags for first
 * paint and `frontend-strava-admin.js` drives every mutation + the roster load
 * against the REST API. No encryption, persistence, or token handling here.
 *
 * Matrix-gated (NOT manage_options): view on `tt_view_strava`, credential +
 * webhook edits on `tt_edit_strava_credentials`. The REST routes re-check the
 * same caps, so this gate is the affordance, not the enforcement.
 */
final class FrontendStravaAdminView extends FrontendViewBase {

    private const VIEW_CAP = 'tt_view_strava';
    private const EDIT_CAP = 'tt_edit_strava_credentials';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::VIEW_CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Strava', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueViewAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Strava', 'talenttrack' ) );
        self::renderHeader( __( 'Strava integration', 'talenttrack' ) );

        $can_edit        = current_user_can( self::EDIT_CAP );
        $client_id       = StravaConfig::clientId();
        $configured      = StravaConfig::hasCredentials();
        $has_sub         = StravaConfig::subscriptionId() !== '';
        $callback_url    = StravaConfig::webhookCallbackUrl();
        $redirect_uri    = StravaConfig::redirectUri();
        $callback_domain = (string) wp_parse_url( home_url(), PHP_URL_HOST );

        // Cancel target: the Configuration view this tile lives under.
        $cancel_url = add_query_arg(
            [ 'tt_view' => 'configuration' ],
            remove_query_arg( [ 'tt_view' ] )
        );
        ?>
        <div class="tt-strava-admin" data-tt-strava-admin>
            <p class="tt-strava-admin__intro">
                <?php esc_html_e( 'Register your academy\'s Strava developer app and webhook here, then players connect their own accounts from their profile. Distance, duration, pace and elevation are imported — never heart-rate data.', 'talenttrack' ); ?>
            </p>

            <details class="tt-strava-admin__setup"<?php echo $configured ? '' : ' open'; ?>>
                <summary class="tt-strava-admin__setup-toggle"><?php esc_html_e( 'Before you start — one-time Strava setup', 'talenttrack' ); ?></summary>
                <p class="tt-strava-admin__hint"><?php esc_html_e( 'These steps happen in your Strava account, not in TalentTrack.', 'talenttrack' ); ?></p>
                <ol class="tt-strava-admin__setup-steps">
                    <li><?php esc_html_e( 'Create an API application at strava.com/settings/api.', 'talenttrack' ); ?></li>
                    <li>
                        <?php
                        printf(
                            /* translators: %s: this site's domain to enter as the Strava app's Authorization Callback Domain. */
                            esc_html__( 'Set the Authorization Callback Domain to %s.', 'talenttrack' ),
                            '<code>' . esc_html( $callback_domain ) . '</code>'
                        );
                        ?>
                    </li>
                    <li><?php esc_html_e( 'Paste the Client ID and secret below, then save and verify.', 'talenttrack' ); ?></li>
                </ol>
            </details>

            <div class="tt-strava-admin__msg" data-tt-strava-admin-msg role="status" aria-live="polite"></div>

            <section class="tt-strava-admin__section">
                <h2><?php esc_html_e( 'App credentials', 'talenttrack' ); ?></h2>
                <p class="tt-strava-admin__hint">
                    <?php
                    printf(
                        /* translators: %s: the OAuth callback URL to register at Strava. */
                        esc_html__( 'Create an API application at strava.com/settings/api, then paste its Client ID and Client secret below. Set the app\'s Authorization Callback Domain to this site, and its redirect to %s.', 'talenttrack' ),
                        '<code>' . esc_html( $redirect_uri ) . '</code>'
                    );
                    ?>
                </p>

                <p class="tt-strava-admin__state">
                    <?php if ( $configured ) : ?>
                        <span class="tt-strava-admin__badge tt-strava-admin__badge--ok"><?php esc_html_e( 'Configured', 'talenttrack' ); ?></span>
                    <?php else : ?>
                        <span class="tt-strava-admin__badge tt-strava-admin__badge--muted"><?php esc_html_e( 'Not configured', 'talenttrack' ); ?></span>
                    <?php endif; ?>
                </p>

                <form data-tt-strava-admin-app-form>
                    <div class="tt-strava-admin__field">
                        <label class="tt-strava-admin__legend" for="tt-strava-client-id"><?php esc_html_e( 'Client ID', 'talenttrack' ); ?></label>
                        <input type="text" id="tt-strava-client-id" class="tt-strava-admin__input" name="client_id"
                            inputmode="numeric" autocomplete="off"
                            value="<?php echo esc_attr( $client_id ); ?>"
                            <?php disabled( ! $can_edit ); ?> />
                    </div>

                    <div class="tt-strava-admin__field">
                        <label class="tt-strava-admin__legend" for="tt-strava-client-secret"><?php esc_html_e( 'Client secret', 'talenttrack' ); ?></label>
                        <input type="password" id="tt-strava-client-secret" class="tt-strava-admin__input" name="client_secret"
                            value="" autocomplete="new-password"
                            placeholder="<?php echo $configured ? esc_attr__( 'Leave blank to keep current secret', 'talenttrack' ) : ''; ?>"
                            <?php disabled( ! $can_edit ); ?> />
                        <p class="tt-strava-admin__hint"><?php esc_html_e( 'Stored encrypted at rest and never shown again. Rotating the WordPress AUTH_KEY salt invalidates the stored value and requires re-entry.', 'talenttrack' ); ?></p>
                    </div>

                    <?php if ( $can_edit ) : ?>
                        <div class="tt-strava-admin__actions-row">
                            <?php echo FormSaveButton::render( [
                                'label'        => __( 'Save credentials', 'talenttrack' ),
                                'label_saving' => __( 'Saving…', 'talenttrack' ),
                                'label_saved'  => __( 'Saved', 'talenttrack' ),
                                'cancel_url'   => $cancel_url,
                                'cancel_label' => __( 'Cancel', 'talenttrack' ),
                            ] ); ?>
                        </div>
                    <?php endif; ?>
                </form>
            </section>

            <section class="tt-strava-admin__section">
                <h2><?php esc_html_e( 'Webhook subscription', 'talenttrack' ); ?></h2>
                <p class="tt-strava-admin__hint">
                    <?php
                    printf(
                        /* translators: %s: the webhook callback URL Strava pushes to. */
                        esc_html__( 'Strava allows one push subscription per app, covering every connected player. Activities sync as they happen instead of polling. Callback: %s', 'talenttrack' ),
                        '<code>' . esc_html( $callback_url ) . '</code>'
                    );
                    ?>
                </p>

                <p class="tt-strava-admin__state" data-tt-strava-admin-sub-state aria-live="polite">
                    <?php if ( $has_sub ) : ?>
                        <span class="tt-strava-admin__badge tt-strava-admin__badge--ok"><?php esc_html_e( 'Active', 'talenttrack' ); ?></span>
                    <?php else : ?>
                        <span class="tt-strava-admin__badge tt-strava-admin__badge--muted"><?php esc_html_e( 'Not created', 'talenttrack' ); ?></span>
                    <?php endif; ?>
                </p>

                <?php if ( $can_edit ) : ?>
                    <div class="tt-strava-admin__secondary-actions">
                        <button type="button" class="tt-btn tt-btn-secondary" data-tt-strava-admin-subscribe>
                            <?php esc_html_e( 'Create / re-verify', 'talenttrack' ); ?>
                        </button>
                        <button type="button" class="tt-btn tt-btn-danger" data-tt-strava-admin-unsubscribe<?php echo $has_sub ? '' : ' hidden'; ?>>
                            <?php esc_html_e( 'Delete subscription', 'talenttrack' ); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </section>

            <section class="tt-strava-admin__section">
                <h2><?php esc_html_e( 'Connected players', 'talenttrack' ); ?></h2>
                <p class="tt-strava-admin__summary" data-tt-strava-admin-summary aria-live="polite">
                    <?php esc_html_e( 'Loading…', 'talenttrack' ); ?>
                </p>

                <table class="tt-strava-admin__players">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Activities', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Last activity', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Last sync', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody data-tt-strava-admin-rows>
                        <tr><td colspan="5" class="tt-strava-admin__muted"><?php esc_html_e( 'Loading…', 'talenttrack' ); ?></td></tr>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    }

    private static function enqueueViewAssets(): void {
        wp_enqueue_style(
            'tt-frontend-strava-admin',
            TT_PLUGIN_URL . 'assets/css/frontend-strava-admin.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-strava-admin',
            TT_PLUGIN_URL . 'assets/js/frontend-strava-admin.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-frontend-strava-admin', 'TT_StravaAdmin', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'can_edit'   => current_user_can( self::EDIT_CAP ),
            'i18n'       => [
                'saving'           => __( 'Saving…', 'talenttrack' ),
                'saved'            => __( 'Credentials saved.', 'talenttrack' ),
                'sub_creating'     => __( 'Creating subscription…', 'talenttrack' ),
                'sub_created'      => __( 'Webhook subscription is active.', 'talenttrack' ),
                'sub_deleting'     => __( 'Deleting subscription…', 'talenttrack' ),
                'sub_deleted'      => __( 'Webhook subscription deleted.', 'talenttrack' ),
                'sub_failed'       => __( 'Could not create the subscription. Check the credentials and callback domain.', 'talenttrack' ),
                'confirm_unsub'    => __( 'Delete the Strava webhook subscription? Activities stop syncing until it is re-created.', 'talenttrack' ),
                'active'           => __( 'Active', 'talenttrack' ),
                'not_created'      => __( 'Not created', 'talenttrack' ),
                'configured'       => __( 'Configured', 'talenttrack' ),
                'not_configured'   => __( 'Not configured', 'talenttrack' ),
                'error'            => __( 'Something went wrong. Please try again.', 'talenttrack' ),
                'network_error'    => __( 'Network error. Please try again.', 'talenttrack' ),
                'no_connections'   => __( 'No players have connected a Strava account yet.', 'talenttrack' ),
                'never'            => __( 'Never', 'talenttrack' ),
                /* translators: 1: connected count 2: total connections */
                'summary'          => __( '%1$d connected of %2$d players who have started linking.', 'talenttrack' ),
                'status_connected' => __( 'Connected', 'talenttrack' ),
                'status_pending'   => __( 'Pending consent', 'talenttrack' ),
                'status_revoked'   => __( 'Revoked', 'talenttrack' ),
                'status_disconnected' => __( 'Disconnected', 'talenttrack' ),
            ],
        ] );
    }
}
