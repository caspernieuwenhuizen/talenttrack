<?php
namespace TT\Modules\Spond\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Spond\SpondSync;

/**
 * SpondOverviewPage — admin entry point for the Spond integration
 * (#0061 follow-up).
 *
 * Spond settings until now lived per-team on the Team edit form, with
 * no aggregate view of "which teams are connected, when did each last
 * sync, did it succeed". This page closes that discoverability gap:
 * one row per team, current URL-configured state, last sync at +
 * status, and a per-row "Refresh now" button.
 *
 * The actual URL editing still happens on the Team edit form — this
 * page links across rather than duplicating the encrypted-credential
 * UX. Cap-gated on `tt_edit_teams` (the same cap that reads/writes
 * the `spond_ical_url` column).
 *
 * Reachable at admin.php?page=tt-spond and from the wp-admin
 * Configuration sidebar group.
 */
final class SpondOverviewPage {

    public const SLUG       = 'tt-spond';
    public const NONCE_NAME = '_tt_spond_admin_nonce';
    public const NONCE_KEY  = 'tt_spond_admin_action';

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register' ], 30 );
        add_action( 'admin_post_tt_spond_refresh_team', [ self::class, 'handleRefresh' ] );
    }

    public static function register(): void {
        add_submenu_page(
            'talenttrack',
            __( 'Spond integration', 'talenttrack' ),
            __( 'Spond', 'talenttrack' ),
            'tt_edit_teams',
            self::SLUG,
            [ self::class, 'render' ]
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'tt_edit_teams' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'talenttrack' ) );
        }
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, age_group, spond_ical_url, spond_last_sync_at, spond_last_sync_status, spond_last_sync_message
               FROM {$wpdb->prefix}tt_teams
              WHERE club_id = %d AND ( archived_at IS NULL OR archived_at = '' )
              ORDER BY name ASC",
            CurrentClub::id()
        ) );

        $configured = 0;
        $errored    = 0;
        foreach ( (array) $rows as $row ) {
            if ( ! empty( $row->spond_ical_url ) ) $configured++;
            if ( (string) $row->spond_last_sync_status === 'error' ) $errored++;
        }
        $total = is_array( $rows ) ? count( $rows ) : 0;

        $next_run = wp_next_scheduled( \TT\Modules\Spond\SpondModule::CRON_HOOK );

        $flash = isset( $_GET['tt_spond_msg'] ) ? sanitize_key( (string) $_GET['tt_spond_msg'] ) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Spond integration', 'talenttrack' ); ?></h1>

            <?php if ( $flash === 'refreshed' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sync triggered. Reload to see the updated status.', 'talenttrack' ); ?></p></div>
            <?php elseif ( $flash === 'no_url' ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That team does not have a Spond iCal URL on file.', 'talenttrack' ); ?></p></div>
            <?php endif; ?>

            <p style="color:#5b6e75;">
                <?php
                printf(
                    /* translators: 1: total teams 2: configured 3: errored */
                    esc_html__( '%1$d teams. %2$d connected to Spond. %3$d with the last sync errored.', 'talenttrack' ),
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
                        <th><?php esc_html_e( 'Connected', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Last sync', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( (array) $rows as $row ) :
                    $has_url   = ! empty( $row->spond_ical_url );
                    $last_sync = (string) ( $row->spond_last_sync_at ?: '' );
                    $status    = (string) ( $row->spond_last_sync_status ?: '' );
                    $message   = (string) ( $row->spond_last_sync_message ?: '' );
                    $team_url  = admin_url( 'admin.php?page=tt-teams&action=edit&id=' . (int) $row->id );
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url( $team_url ); ?>"><?php echo esc_html( (string) $row->name ); ?></a></strong>
                            <?php if ( ! empty( $row->age_group ) ) : ?>
                                <span style="color:#5b6e75; font-size:12px;"> · <?php echo esc_html( (string) $row->age_group ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $has_url ) : ?>
                                <span style="color:#137d1d;">&#10003; <?php esc_html_e( 'Yes', 'talenttrack' ); ?></span>
                            <?php else : ?>
                                <span style="color:#5b6e75;"><?php esc_html_e( 'Not configured', 'talenttrack' ); ?></span>
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
                            <?php elseif ( $status === 'error' ) : ?>
                                <span style="color:#b32d2e;" title="<?php echo esc_attr( $message ); ?>"><?php esc_html_e( 'Error', 'talenttrack' ); ?></span>
                            <?php elseif ( $status === 'partial' ) : ?>
                                <span style="color:#b45309;" title="<?php echo esc_attr( $message ); ?>"><?php esc_html_e( 'Partial', 'talenttrack' ); ?></span>
                            <?php else : ?>
                                <span style="color:#5b6e75;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $has_url ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0; display:inline;">
                                    <?php wp_nonce_field( self::NONCE_KEY, self::NONCE_NAME ); ?>
                                    <input type="hidden" name="action" value="tt_spond_refresh_team" />
                                    <input type="hidden" name="team_id" value="<?php echo (int) $row->id; ?>" />
                                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Refresh now', 'talenttrack' ); ?></button>
                                </form>
                            <?php else : ?>
                                <a class="button button-secondary" href="<?php echo esc_url( $team_url ); ?>"><?php esc_html_e( 'Add URL', 'talenttrack' ); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $total === 0 ) : ?>
                <p style="margin-top:12px;color:#5b6e75;">
                    <?php esc_html_e( 'No teams yet. Spond pulls per team — add a team first, then paste the Spond iCal URL on the team edit form.', 'talenttrack' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handleRefresh(): void {
        if ( ! current_user_can( 'tt_edit_teams' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( self::NONCE_KEY, self::NONCE_NAME );

        $team_id = isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0;
        if ( $team_id <= 0 ) {
            wp_safe_redirect( add_query_arg( 'tt_spond_msg', 'no_url', admin_url( 'admin.php?page=' . self::SLUG ) ) );
            exit;
        }

        // SpondSync::syncTeam returns silently — it writes the result
        // back into the team's spond_last_sync_* columns, so the
        // refreshed state is whatever the user sees on the next page load.
        SpondSync::syncTeam( $team_id );

        wp_safe_redirect( add_query_arg( 'tt_spond_msg', 'refreshed', admin_url( 'admin.php?page=' . self::SLUG ) ) );
        exit;
    }
}
