<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Spond\CredentialsManager;
use TT\Modules\Spond\SpondClient;
use TT\Modules\Spond\SpondModule;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendSpondView — frontend port of the wp-admin Spond integration
 * page (#1936, child of #1533). Reachable at `?tt_view=spond`.
 *
 * Answers the academy question "Are our teams' calendars syncing from
 * Spond, and is the connection healthy?" — the sync writes tt_activities
 * rows that surface on player timelines, so a broken connection means
 * schedule entry silently becomes manual.
 *
 * Three sections, all without a wp-admin bounce:
 *   - Account: per-club Spond credentials (email + password). The
 *     password is never pre-filled or echoed — when connected the form
 *     shows "Connected as <email>" and a blank password field. Test +
 *     Disconnect actions live next to Save.
 *   - Teams: per-team sync status (group, last sync, status badge) with
 *     a "Refresh now" button that POSTs the existing per-team sync
 *     endpoint.
 *   - API endpoint: a collapsible base-URL override for operators.
 *
 * The view only COMPOSES data: encryption, keep-on-blank password, the
 * live login, and the override write all live in CredentialsManager /
 * SpondClient / QueryHelpers, driven via SpondRestController. See
 * assets/js/frontend-spond.js.
 *
 * Capability: view + refresh gate on `tt_edit_teams` (matches the
 * existing sync endpoint); credential + base-url edits gate on
 * `tt_edit_spond_credentials` (matrix caps — never role strings).
 */
class FrontendSpondView extends FrontendViewBase {

    private const VIEW_CAP = 'tt_edit_teams';
    private const CRED_CAP  = 'tt_edit_spond_credentials';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::VIEW_CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Spond', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueViewAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Spond', 'talenttrack' ) );
        self::renderHeader( __( 'Spond integration', 'talenttrack' ) );

        $can_edit_creds = current_user_can( self::CRED_CAP );

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, age_group, spond_group_id, spond_last_sync_at, spond_last_sync_status, spond_last_sync_message
               FROM {$wpdb->prefix}tt_teams
              WHERE club_id = %d AND ( archived_at IS NULL OR archived_at = '' )
              ORDER BY name ASC",
            CurrentClub::id()
        ) );

        $configured = 0;
        $errored    = 0;
        foreach ( (array) $rows as $row ) {
            if ( ! empty( $row->spond_group_id ) ) {
                $configured++;
            }
            if ( in_array( (string) $row->spond_last_sync_status, [ 'failed', 'error' ], true ) ) {
                $errored++;
            }
        }
        $total = is_array( $rows ) ? count( $rows ) : 0;

        $next_run  = wp_next_scheduled( SpondModule::CRON_HOOK );
        $email     = CredentialsManager::getEmail();
        $has_creds = CredentialsManager::hasCredentials();

        // Group-name resolution for connected teams (best-effort: one
        // live fetch when credentialed). A failure leaves the raw id.
        $group_map = [];
        if ( $has_creds ) {
            $g = SpondClient::fetchGroups();
            if ( ! empty( $g['ok'] ) ) {
                foreach ( (array) $g['groups'] as $gr ) {
                    $gid = (string) ( $gr['id'] ?? '' );
                    if ( $gid !== '' ) {
                        $group_map[ $gid ] = (string) ( $gr['name'] ?? '' );
                    }
                }
            }
        }

        $current_base = SpondClient::baseUrl();
        $default_base = rtrim( SpondClient::DEFAULT_BASE_URL, '/' );
        $override     = QueryHelpers::get_config( 'spond.api_base_url', '' );

        // Cancel target: the Configuration view this tile lives under.
        $cancel_url = add_query_arg(
            [ 'tt_view' => 'configuration' ],
            remove_query_arg( [ 'tt_view' ] )
        );
        ?>
        <div class="tt-spond" data-tt-spond>
            <p class="tt-spond__intro">
                <?php esc_html_e( 'One Spond login per club syncs each connected team\'s calendar into the player timeline. Use a dedicated coach/manager account that\'s a member of every Spond group you want to sync. Two-factor authentication is not supported — disable it on this account or use a non-2FA account.', 'talenttrack' ); ?>
            </p>

            <div class="tt-spond__form-msg" data-tt-spond-msg role="status" aria-live="polite"></div>

            <section class="tt-spond__section">
                <h2><?php esc_html_e( 'Account', 'talenttrack' ); ?></h2>

                <?php if ( $has_creds ) : ?>
                    <p class="tt-spond__connected">
                        <span class="tt-spond__set-flag"><?php esc_html_e( 'Connected', 'talenttrack' ); ?></span>
                        <?php
                        printf(
                            /* translators: %s: the connected Spond account email. */
                            esc_html__( 'as %s', 'talenttrack' ),
                            '<strong>' . esc_html( $email ) . '</strong>'
                        );
                        ?>
                    </p>
                <?php endif; ?>

                <form data-tt-spond-creds-form>
                    <div class="tt-spond__field">
                        <label class="tt-spond__legend" for="tt-spond-email"><?php esc_html_e( 'Spond email', 'talenttrack' ); ?></label>
                        <input type="email" inputmode="email" id="tt-spond-email" class="tt-spond__input" name="email"
                            value="<?php echo esc_attr( $email ); ?>" autocomplete="off"
                            <?php disabled( ! $can_edit_creds ); ?> />
                    </div>

                    <div class="tt-spond__field">
                        <label class="tt-spond__legend" for="tt-spond-password"><?php esc_html_e( 'Spond password', 'talenttrack' ); ?></label>
                        <input type="password" id="tt-spond-password" class="tt-spond__input" name="password"
                            value="" autocomplete="new-password"
                            placeholder="<?php echo $has_creds ? esc_attr__( 'Leave blank to keep current password', 'talenttrack' ) : ''; ?>"
                            <?php disabled( ! $can_edit_creds ); ?> />
                        <p class="tt-spond__hint"><?php esc_html_e( 'Stored encrypted at rest. Rotating the WordPress AUTH_KEY salt invalidates the stored value and requires re-entry.', 'talenttrack' ); ?></p>
                    </div>

                    <?php if ( $can_edit_creds ) : ?>
                        <div class="tt-spond__actions-row">
                            <?php echo FormSaveButton::render( [
                                'label'        => __( 'Save credentials', 'talenttrack' ),
                                'label_saving' => __( 'Saving…', 'talenttrack' ),
                                'label_saved'  => __( 'Saved', 'talenttrack' ),
                                'cancel_url'   => $cancel_url,
                                'cancel_label' => __( 'Cancel', 'talenttrack' ),
                            ] ); ?>
                        </div>

                        <div class="tt-spond__secondary-actions">
                            <button type="button" class="tt-btn tt-btn-secondary" data-tt-spond-test>
                                <?php esc_html_e( 'Test connection', 'talenttrack' ); ?>
                            </button>
                            <?php if ( $has_creds ) : ?>
                                <button type="button" class="tt-btn tt-btn-danger" data-tt-spond-disconnect>
                                    <?php esc_html_e( 'Disconnect', 'talenttrack' ); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </form>
            </section>

            <section class="tt-spond__section">
                <h2><?php esc_html_e( 'Teams', 'talenttrack' ); ?></h2>
                <p class="tt-spond__summary">
                    <?php
                    printf(
                        /* translators: 1: total teams 2: connected 3: errored */
                        esc_html__( '%1$d teams. %2$d connected to a Spond group. %3$d with the last sync errored.', 'talenttrack' ),
                        (int) $total,
                        (int) $configured,
                        (int) $errored
                    );
                    if ( $next_run ) {
                        echo ' · ';
                        printf(
                            /* translators: %s: relative time until next cron */
                            esc_html__( 'Next automatic sync in about %s.', 'talenttrack' ),
                            esc_html( human_time_diff( time(), (int) $next_run ) )
                        );
                    }
                    ?>
                </p>

                <table class="tt-spond__teams">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Spond group', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Last sync', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( (array) $rows as $row ) :
                        $gid       = (string) ( $row->spond_group_id ?: '' );
                        $has_group = $gid !== '';
                        $last_sync = (string) ( $row->spond_last_sync_at ?: '' );
                        $status    = (string) ( $row->spond_last_sync_status ?: '' );
                        $message   = (string) ( $row->spond_last_sync_message ?: '' );
                        $group_nm  = $has_group ? ( $group_map[ $gid ] ?? $gid ) : '';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( (string) $row->name ); ?></strong>
                                <?php if ( ! empty( $row->age_group ) ) : ?>
                                    <span class="tt-spond__muted"> · <?php echo esc_html( (string) $row->age_group ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $has_group ) : ?>
                                    <span class="tt-spond__group"><?php echo esc_html( $group_nm ); ?></span>
                                <?php elseif ( ! $has_creds ) : ?>
                                    <span class="tt-spond__muted"><?php esc_html_e( 'Connect account first', 'talenttrack' ); ?></span>
                                <?php else : ?>
                                    <span class="tt-spond__muted"><?php esc_html_e( 'Not connected', 'talenttrack' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                if ( $last_sync === '' ) {
                                    echo '—';
                                } else {
                                    $ts = strtotime( $last_sync );
                                    if ( $ts ) {
                                        printf(
                                            /* translators: %s: human-readable elapsed time, e.g. "3 hours". */
                                            esc_html__( '%s ago', 'talenttrack' ),
                                            esc_html( human_time_diff( $ts, time() ) )
                                        );
                                    } else {
                                        echo esc_html( $last_sync );
                                    }
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ( $status === 'ok' ) : ?>
                                    <span class="tt-spond__badge tt-spond__badge--ok"><?php esc_html_e( 'OK', 'talenttrack' ); ?></span>
                                <?php elseif ( in_array( $status, [ 'failed', 'error' ], true ) ) : ?>
                                    <span class="tt-spond__badge tt-spond__badge--error" title="<?php echo esc_attr( $message ); ?>"><?php esc_html_e( 'Error', 'talenttrack' ); ?></span>
                                <?php elseif ( $status === 'partial' ) : ?>
                                    <span class="tt-spond__badge tt-spond__badge--partial" title="<?php echo esc_attr( $message ); ?>"><?php esc_html_e( 'Partial', 'talenttrack' ); ?></span>
                                <?php elseif ( $status === 'disabled' ) : ?>
                                    <span class="tt-spond__badge tt-spond__badge--muted"><?php esc_html_e( 'Disabled', 'talenttrack' ); ?></span>
                                <?php else : ?>
                                    <span class="tt-spond__muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $has_group ) : ?>
                                    <button type="button" class="tt-btn tt-btn-secondary" data-tt-spond-refresh data-team-id="<?php echo (int) $row->id; ?>">
                                        <?php esc_html_e( 'Refresh now', 'talenttrack' ); ?>
                                    </button>
                                <?php else : ?>
                                    <span class="tt-spond__muted"><?php esc_html_e( 'Pick a group on the team edit form', 'talenttrack' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $total === 0 ) : ?>
                    <p class="tt-spond__empty">
                        <?php esc_html_e( 'No teams yet. Add a team first, then come back to pick a Spond group on the team edit form.', 'talenttrack' ); ?>
                    </p>
                <?php endif; ?>
            </section>

            <?php if ( $can_edit_creds ) : ?>
                <details class="tt-spond__details">
                    <summary class="tt-spond__summary-toggle">
                        <?php esc_html_e( 'API endpoint', 'talenttrack' ); ?>
                        <span class="tt-spond__muted">
                            — <?php echo esc_html( $current_base ); ?><?php if ( $override === '' ) { echo ' ' . esc_html__( '(default)', 'talenttrack' ); } ?>
                        </span>
                    </summary>
                    <p class="tt-spond__hint">
                        <?php
                        printf(
                            /* translators: %s: default Spond API URL the plugin ships with. */
                            esc_html__( 'Override the Spond API base URL. Default is %s. Change only if Spond announces a new endpoint, or for testing against a private mock — a wrong URL will cause every sync to fail.', 'talenttrack' ),
                            '<code>' . esc_html( $default_base ) . '</code>'
                        );
                        ?>
                    </p>
                    <form data-tt-spond-baseurl-form>
                        <div class="tt-spond__field">
                            <label class="tt-spond__legend" for="tt-spond-base-url"><?php esc_html_e( 'API base URL', 'talenttrack' ); ?></label>
                            <input type="url" inputmode="url" id="tt-spond-base-url" class="tt-spond__input" name="api_base_url"
                                value="<?php echo esc_attr( $override ); ?>"
                                placeholder="<?php echo esc_attr( $default_base ); ?>" autocomplete="off" />
                            <p class="tt-spond__hint"><?php esc_html_e( 'Leave blank and save to revert to the default.', 'talenttrack' ); ?></p>
                        </div>
                        <button type="submit" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Save endpoint', 'talenttrack' ); ?></button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function enqueueViewAssets(): void {
        wp_enqueue_style(
            'tt-frontend-spond',
            TT_PLUGIN_URL . 'assets/css/frontend-spond.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-spond',
            TT_PLUGIN_URL . 'assets/js/frontend-spond.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-frontend-spond',
            'TT_Spond',
            [
                'i18n' => [
                    'saved'            => __( 'Credentials saved.', 'talenttrack' ),
                    'test_ok'          => __( 'Spond login successful.', 'talenttrack' ),
                    'test_failed'      => __( 'Spond login failed.', 'talenttrack' ),
                    'disconnected'     => __( 'Spond disconnected.', 'talenttrack' ),
                    'base_url_saved'   => __( 'API endpoint saved.', 'talenttrack' ),
                    'refreshing'       => __( 'Refreshing…', 'talenttrack' ),
                    'refreshed'        => __( 'Sync triggered. Reload to see the updated status.', 'talenttrack' ),
                    'error'            => __( 'Could not save. Please try again.', 'talenttrack' ),
                    'network_error'    => __( 'Network error. Please try again.', 'talenttrack' ),
                    'disconnect_confirm' => __( 'Disconnect Spond? Existing imported activities are kept; per-team group selections stay on file.', 'talenttrack' ),
                ],
            ]
        );
    }
}
