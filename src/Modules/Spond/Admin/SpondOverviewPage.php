<?php
namespace TT\Modules\Spond\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Spond\CredentialsManager;
use TT\Modules\Spond\SpondClient;
use TT\Modules\Spond\SpondSync;

/**
 * SpondOverviewPage (#0061 follow-up, rewritten via #0062) — admin
 * entry point for the Spond integration.
 *
 * #0062 swapped the per-team iCal URL for a per-club login + per-team
 * `spond_group_id`. This page is now the single home for both:
 *
 *   - Top section: Spond account credentials (email + password). Saved
 *     via `CredentialsManager` (encrypted at rest). A "Test connection"
 *     submit attempts a live login and reports the result.
 *   - Table: every team, its currently-picked Spond group, last sync
 *     status, and a per-row "Refresh now" button. Group selection
 *     itself happens on the team-edit form.
 *
 * Cap-gated on `tt_edit_teams`. Reachable at `admin.php?page=tt-spond`
 * and from the wp-admin Configuration sidebar group.
 */
final class SpondOverviewPage {

    public const SLUG       = 'tt-spond';
    public const NONCE_NAME = '_tt_spond_admin_nonce';
    public const NONCE_KEY  = 'tt_spond_admin_action';

    public static function init(): void {
        // v3.90.0 — submenu registration moved to AdminMenuRegistry via
        // CoreSurfaceRegistration so the entry sits inside the
        // Configuration group instead of visually trailing the Access
        // Control items. Only the admin-post handlers stay here.
        add_action( 'admin_post_tt_spond_refresh_team', [ self::class, 'handleRefresh' ] );
        add_action( 'admin_post_tt_spond_save_credentials', [ self::class, 'handleSaveCredentials' ] );
        add_action( 'admin_post_tt_spond_test_connection', [ self::class, 'handleTestConnection' ] );
        add_action( 'admin_post_tt_spond_clear_credentials', [ self::class, 'handleClearCredentials' ] );
        // v3.110.108 — API base URL override so operators can redirect
        // to a new Spond endpoint without a code release.
        add_action( 'admin_post_tt_spond_save_base_url', [ self::class, 'handleSaveBaseUrl' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'tt_edit_teams' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'talenttrack' ) );
        }
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
            if ( ! empty( $row->spond_group_id ) ) $configured++;
            if ( in_array( (string) $row->spond_last_sync_status, [ 'failed', 'error' ], true ) ) $errored++;
        }
        $total = is_array( $rows ) ? count( $rows ) : 0;

        $next_run = wp_next_scheduled( \TT\Modules\Spond\SpondModule::CRON_HOOK );

        $email      = CredentialsManager::getEmail();
        $has_creds  = CredentialsManager::hasCredentials();
        $groups     = [];
        $group_map  = [];
        if ( $has_creds ) {
            $g = SpondClient::fetchGroups();
            if ( ! empty( $g['ok'] ) ) {
                $groups = (array) $g['groups'];
                foreach ( $groups as $gr ) {
                    $gid = (string) ( $gr['id']   ?? '' );
                    if ( $gid !== '' ) $group_map[ $gid ] = (string) ( $gr['name'] ?? '' );
                }
            }
        }

        $flash     = isset( $_GET['tt_spond_msg'] ) ? sanitize_key( (string) $_GET['tt_spond_msg'] ) : '';
        $flash_msg = isset( $_GET['tt_spond_detail'] ) ? wp_unslash( (string) $_GET['tt_spond_detail'] ) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Spond integration', 'talenttrack' ); ?></h1>

            <?php self::renderFlash( $flash, $flash_msg ); ?>

            <?php
            // v3.110.108 — API base URL override. Hidden in a <details>
            // so it doesn't compete with the Account section visually;
            // operators rarely need it, but when Spond moves the endpoint
            // they need a way to redirect without a code release.
            $current_base = \TT\Modules\Spond\SpondClient::baseUrl();
            $default_base = rtrim( \TT\Modules\Spond\SpondClient::DEFAULT_BASE_URL, '/' );
            $override     = \TT\Infrastructure\Query\QueryHelpers::get_config( 'spond.api_base_url', '' );
            ?>
            <details style="margin-top:18px; border:1px solid #e5e7ea; border-radius:6px; padding:10px 14px; background:#fafbfc;">
                <summary style="cursor:pointer; font-weight:600;">
                    <?php esc_html_e( 'API endpoint', 'talenttrack' ); ?>
                    <span style="font-weight:normal; color:#5b6e75; font-size:12px;">
                        — <?php echo esc_html( $current_base ); ?><?php if ( $override === '' ) echo ' ' . esc_html__( '(default)', 'talenttrack' ); ?>
                    </span>
                </summary>
                <p style="color:#5b6e75; margin-top:10px; max-width:60em;">
                    <?php
                    printf(
                        /* translators: %s: default Spond API URL the plugin ships with. */
                        esc_html__( 'Override the Spond API base URL. Default is %s. Change only if Spond announces a new endpoint, or for testing against a private mock — a wrong URL will cause every sync to fail.', 'talenttrack' ),
                        '<code>' . esc_html( $default_base ) . '</code>'
                    );
                    ?>
                </p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( self::NONCE_KEY, self::NONCE_NAME ); ?>
                    <input type="hidden" name="action" value="tt_spond_save_base_url" />
                    <p>
                        <label for="tt_spond_base_url" style="display:block; margin-bottom:4px;"><?php esc_html_e( 'API base URL', 'talenttrack' ); ?></label>
                        <input type="url" id="tt_spond_base_url" name="api_base_url" class="regular-text code" style="width:520px;"
                               value="<?php echo esc_attr( $override ); ?>"
                               placeholder="<?php echo esc_attr( $default_base ); ?>"
                               inputmode="url" autocomplete="off" />
                    </p>
                    <p>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Save endpoint', 'talenttrack' ); ?></button>
                        <?php if ( $override !== '' ) : ?>
                            <span style="margin-left:8px; color:#5b6e75; font-size:12px;">
                                <?php esc_html_e( 'Leave blank and save to revert to the default.', 'talenttrack' ); ?>
                            </span>
                        <?php endif; ?>
                    </p>
                </form>
            </details>

            <h2 style="margin-top:18px;"><?php esc_html_e( 'Account', 'talenttrack' ); ?></h2>
            <p style="color:#5b6e75; max-width:60em;">
                <?php esc_html_e( 'One Spond login per club. Use a dedicated coach/manager account that\'s a member of every Spond group you want to sync. Two-factor authentication is not supported in v1 — disable it on this account or use a non-2FA account.', 'talenttrack' ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px;">
                <?php wp_nonce_field( self::NONCE_KEY, self::NONCE_NAME ); ?>
                <input type="hidden" name="action" value="tt_spond_save_credentials" />
                <table class="form-table" style="max-width:640px;">
                    <tr>
                        <th><label for="tt_spond_email"><?php esc_html_e( 'Spond email', 'talenttrack' ); ?></label></th>
                        <td><input type="email" id="tt_spond_email" name="email" autocomplete="off" class="regular-text" value="<?php echo esc_attr( $email ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="tt_spond_password"><?php esc_html_e( 'Spond password', 'talenttrack' ); ?></label></th>
                        <td>
                            <input type="password" id="tt_spond_password" name="password" autocomplete="new-password" class="regular-text" value="" placeholder="<?php echo $has_creds ? esc_attr__( '••••••••  (leave blank to keep)', 'talenttrack' ) : ''; ?>" />
                            <p class="description"><?php esc_html_e( 'Stored encrypted at rest. Rotating the WordPress AUTH_KEY salt invalidates the stored value and requires re-entry.', 'talenttrack' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save credentials', 'talenttrack' ); ?></button>
                    <?php if ( $has_creds ) : ?>
                        <button type="submit" class="button button-secondary" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" name="action" value="tt_spond_test_connection">
                            <?php esc_html_e( 'Test connection', 'talenttrack' ); ?>
                        </button>
                        <button type="submit" class="button button-link-delete" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" name="action" value="tt_spond_clear_credentials" onclick="return confirm('<?php echo esc_js( __( 'Disconnect Spond? Existing imported activities are kept.', 'talenttrack' ) ); ?>');">
                            <?php esc_html_e( 'Disconnect', 'talenttrack' ); ?>
                        </button>
                    <?php endif; ?>
                </p>
            </form>

            <h2 style="margin-top:24px;"><?php esc_html_e( 'Teams', 'talenttrack' ); ?></h2>
            <p style="color:#5b6e75;">
                <?php
                printf(
                    /* translators: 1: total teams 2: connected 3: errored */
                    esc_html__( '%1$d teams. %2$d connected to a Spond group. %3$d with the last sync errored.', 'talenttrack' ),
                    (int) $total,
                    (int) $configured,
                    (int) $errored
                );
                if ( $next_run ) {
                    echo ' &middot; ';
                    printf(
                        /* translators: %s: relative time until next cron */
                        esc_html__( 'Next automatic sync in about %s.', 'talenttrack' ),
                        esc_html( human_time_diff( time(), (int) $next_run ) )
                    );
                }
                ?>
            </p>

            <table class="widefat striped" style="margin-top:12px;">
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
                    $team_url  = admin_url( 'admin.php?page=tt-teams&action=edit&id=' . (int) $row->id );
                    $group_nm  = $has_group ? ( $group_map[ $gid ] ?? $gid ) : '';
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url( $team_url ); ?>"><?php echo esc_html( (string) $row->name ); ?></a></strong>
                            <?php if ( ! empty( $row->age_group ) ) : ?>
                                <span style="color:#5b6e75; font-size:12px;"> · <?php echo esc_html( (string) $row->age_group ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $has_group ) : ?>
                                <span style="color:#137d1d;"><?php echo esc_html( $group_nm ); ?></span>
                            <?php elseif ( ! $has_creds ) : ?>
                                <span style="color:#5b6e75;"><?php esc_html_e( 'Connect account first', 'talenttrack' ); ?></span>
                            <?php else : ?>
                                <span style="color:#5b6e75;"><?php esc_html_e( 'Not connected', 'talenttrack' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            if ( $last_sync === '' ) {
                                echo '—';
                            } else {
                                $ts = strtotime( $last_sync );
                                if ( $ts ) {
                                    echo esc_html( human_time_diff( $ts, time() ) );
                                    echo ' ' . esc_html__( 'ago', 'talenttrack' );
                                } else {
                                    echo esc_html( $last_sync );
                                }
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ( $status === 'ok' ) : ?>
                                <span style="color:#137d1d;"><?php esc_html_e( 'OK', 'talenttrack' ); ?></span>
                            <?php elseif ( in_array( $status, [ 'failed', 'error' ], true ) ) : ?>
                                <span style="color:#b32d2e;" title="<?php echo esc_attr( $message ); ?>"><?php esc_html_e( 'Error', 'talenttrack' ); ?></span>
                            <?php elseif ( $status === 'partial' ) : ?>
                                <span style="color:#b45309;" title="<?php echo esc_attr( $message ); ?>"><?php esc_html_e( 'Partial', 'talenttrack' ); ?></span>
                            <?php elseif ( $status === 'disabled' ) : ?>
                                <span style="color:#5b6e75;"><?php esc_html_e( 'Disabled', 'talenttrack' ); ?></span>
                            <?php else : ?>
                                <span style="color:#5b6e75;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $has_group ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0; display:inline;">
                                    <?php wp_nonce_field( self::NONCE_KEY, self::NONCE_NAME ); ?>
                                    <input type="hidden" name="action" value="tt_spond_refresh_team" />
                                    <input type="hidden" name="team_id" value="<?php echo (int) $row->id; ?>" />
                                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Refresh now', 'talenttrack' ); ?></button>
                                </form>
                            <?php else : ?>
                                <a class="button button-secondary" href="<?php echo esc_url( $team_url ); ?>"><?php esc_html_e( 'Pick group', 'talenttrack' ); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $total === 0 ) : ?>
                <p style="margin-top:12px;color:#5b6e75;">
                    <?php esc_html_e( 'No teams yet. Add a team first, then come back to pick a Spond group on the team edit form.', 'talenttrack' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    // -----------------------------------------------------------------
    // Handlers
    // -----------------------------------------------------------------

    public static function handleRefresh(): void {
        if ( ! current_user_can( 'tt_edit_teams' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( self::NONCE_KEY, self::NONCE_NAME );

        $team_id = isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0;
        if ( $team_id <= 0 ) {
            wp_safe_redirect( add_query_arg( 'tt_spond_msg', 'no_team', admin_url( 'admin.php?page=' . self::SLUG ) ) );
            exit;
        }

        SpondSync::syncTeam( $team_id );

        wp_safe_redirect( add_query_arg( 'tt_spond_msg', 'refreshed', admin_url( 'admin.php?page=' . self::SLUG ) ) );
        exit;
    }

    public static function handleSaveCredentials(): void {
        if ( ! current_user_can( 'tt_edit_teams' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( self::NONCE_KEY, self::NONCE_NAME );

        $email    = isset( $_POST['email'] )    ? sanitize_email( wp_unslash( (string) $_POST['email'] ) )    : '';
        $password = isset( $_POST['password'] ) ? trim( (string) wp_unslash( $_POST['password'] ) )           : '';

        if ( $password === '' && CredentialsManager::hasCredentials() ) {
            $password = CredentialsManager::getPassword();
        }

        CredentialsManager::save( $email, $password );

        wp_safe_redirect( add_query_arg( 'tt_spond_msg', 'creds_saved', admin_url( 'admin.php?page=' . self::SLUG ) ) );
        exit;
    }

    public static function handleTestConnection(): void {
        if ( ! current_user_can( 'tt_edit_teams' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( self::NONCE_KEY, self::NONCE_NAME );

        $email = CredentialsManager::getEmail();
        if ( isset( $_POST['email'] ) && (string) $_POST['email'] !== '' ) {
            $email = sanitize_email( wp_unslash( (string) $_POST['email'] ) );
        }
        $password = (string) wp_unslash( (string) ( $_POST['password'] ?? '' ) );
        if ( $password === '' ) $password = CredentialsManager::getPassword();

        $result = SpondClient::login( $email, $password );

        if ( $result['ok'] ) {
            CredentialsManager::cacheToken( $result['token'] );
            wp_safe_redirect( add_query_arg(
                [ 'tt_spond_msg' => 'test_ok' ],
                admin_url( 'admin.php?page=' . self::SLUG )
            ) );
        } else {
            wp_safe_redirect( add_query_arg(
                [
                    'tt_spond_msg'    => 'test_failed',
                    'tt_spond_detail' => rawurlencode( (string) ( $result['error_message'] ?? '' ) ),
                ],
                admin_url( 'admin.php?page=' . self::SLUG )
            ) );
        }
        exit;
    }

    public static function handleClearCredentials(): void {
        if ( ! current_user_can( 'tt_edit_teams' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( self::NONCE_KEY, self::NONCE_NAME );

        CredentialsManager::clear();

        wp_safe_redirect( add_query_arg( 'tt_spond_msg', 'creds_cleared', admin_url( 'admin.php?page=' . self::SLUG ) ) );
        exit;
    }

    /**
     * v3.110.108 — save/clear the Spond API base URL override stored
     * in `tt_config[spond.api_base_url]`. Empty input clears the
     * override and reverts to the constant default.
     */
    public static function handleSaveBaseUrl(): void {
        if ( ! current_user_can( 'tt_edit_teams' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( self::NONCE_KEY, self::NONCE_NAME );

        $raw = isset( $_POST['api_base_url'] ) ? trim( (string) wp_unslash( $_POST['api_base_url'] ) ) : '';
        $sanitised = $raw === '' ? '' : esc_url_raw( $raw );
        // Sanity: reject anything that isn't a plausible https/http URL.
        if ( $sanitised !== '' && ! preg_match( '#^https?://[^\s]+$#i', $sanitised ) ) {
            $url = add_query_arg(
                [ 'tt_spond_msg' => 'base_url_invalid', 'tt_spond_detail' => $raw ],
                admin_url( 'admin.php?page=' . self::SLUG )
            );
            wp_safe_redirect( $url );
            exit;
        }

        \TT\Infrastructure\Query\QueryHelpers::set_config( 'spond.api_base_url', $sanitised );

        $msg = $sanitised === '' ? 'base_url_cleared' : 'base_url_saved';
        wp_safe_redirect( add_query_arg( 'tt_spond_msg', $msg, admin_url( 'admin.php?page=' . self::SLUG ) ) );
        exit;
    }

    private static function renderFlash( string $flash, string $detail ): void {
        if ( $flash === '' ) return;
        $msg   = '';
        $class = 'notice-success';
        switch ( $flash ) {
            case 'refreshed':
                $msg = __( 'Sync triggered. Reload to see the updated status.', 'talenttrack' );
                break;
            case 'no_team':
                $msg   = __( 'That team could not be found.', 'talenttrack' );
                $class = 'notice-error';
                break;
            case 'creds_saved':
                $msg = __( 'Spond credentials saved.', 'talenttrack' );
                break;
            case 'creds_cleared':
                $msg = __( 'Spond credentials cleared. Per-team group selections are kept on file but will not sync until a new account is connected.', 'talenttrack' );
                break;
            case 'test_ok':
                $msg = __( 'Spond login successful — token cached.', 'talenttrack' );
                break;
            case 'test_failed':
                $msg   = __( 'Spond login failed.', 'talenttrack' )
                    . ( $detail !== '' ? ' (' . $detail . ')' : '' );
                $class = 'notice-error';
                break;
            case 'base_url_saved':
                $msg = __( 'Spond API endpoint saved.', 'talenttrack' );
                break;
            case 'base_url_cleared':
                $msg = __( 'Spond API endpoint reverted to the default.', 'talenttrack' );
                break;
            case 'base_url_invalid':
                $msg = __( 'That URL does not look like a valid http(s) endpoint. Endpoint left unchanged.', 'talenttrack' );
                $class = 'notice-error';
                break;
            default:
                return;
        }
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr( $class ),
            esc_html( $msg )
        );
    }
}
