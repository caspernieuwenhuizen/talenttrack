<?php
namespace TT\Modules\Backup\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Backup\BackupRunner;
use TT\Modules\Backup\BackupSettings;
use TT\Modules\Backup\Destinations\LocalDestination;
use TT\Modules\Backup\PresetRegistry;
use TT\Modules\Backup\Scheduler;

/**
 * BackupSettingsPage — renders inside the Configuration screen as a
 * `Backups` tab plus owns its own admin-post handlers.
 *
 * Splits naturally into three blocks:
 *   1. Preset / schedule / retention form
 *   2. Destinations (local + email)
 *   3. Stored backups list with download / restore / delete actions
 *
 * Restore goes through a typed-confirmation flow modeled on the demo
 * wipe form: the user must type "RESTORE" before the destructive
 * action fires.
 */
class BackupSettingsPage {

    public const CAP    = 'tt_manage_backups';

    public static function init(): void {
        add_action( 'admin_post_tt_backup_save_settings', [ self::class, 'handleSaveSettings' ] );
        add_action( 'admin_post_tt_backup_run_now',       [ self::class, 'handleRunNow' ] );
        add_action( 'admin_post_tt_backup_delete',        [ self::class, 'handleDelete' ] );
        add_action( 'admin_post_tt_backup_download',      [ self::class, 'handleDownload' ] );
        add_action( 'admin_post_tt_backup_restore',       [ self::class, 'handleRestore' ] );
    }

    /* ═══════════════ Render ═══════════════ */

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $settings = BackupSettings::get();
        $last_run = BackupRunner::lastRun();
        $local    = new LocalDestination();
        $list     = $local->listBackups();
        ?>
        <h2><?php esc_html_e( 'Backups', 'talenttrack' ); ?></h2>
        <p style="max-width:760px;">
            <?php esc_html_e( 'Schedule snapshots of your TalentTrack data and restore them when needed. Backups cover the plugin\'s own tables only — not WordPress users or media uploads.', 'talenttrack' ); ?>
        </p>

        <?php if ( $last_run ) : ?>
            <div class="notice notice-info" style="margin:16px 0;">
                <p>
                    <strong><?php esc_html_e( 'Last run:', 'talenttrack' ); ?></strong>
                    <?php
                    if ( ! empty( $last_run['ok'] ) ) {
                        printf(
                            /* translators: %s is human-readable time-since */
                            esc_html__( '%s ago — success.', 'talenttrack' ),
                            esc_html( human_time_diff( (int) $last_run['at'], time() ) )
                        );
                    } else {
                        printf(
                            /* translators: 1: time-since, 2: error message */
                            esc_html__( '%1$s ago — failed (%2$s).', 'talenttrack' ),
                            esc_html( human_time_diff( (int) $last_run['at'], time() ) ),
                            esc_html( (string) ( $last_run['error'] ?? '' ) )
                        );
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <?php self::renderSettingsForm( $settings ); ?>
        <?php self::renderRunNowButton(); ?>
        <?php self::renderBackupsList( $list ); ?>
        <?php
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function renderSettingsForm( array $settings ): void {
        $preset           = (string) $settings['preset'];
        $schedule         = (string) $settings['schedule'];
        $retention        = (int) $settings['retention'];
        $custom_tables    = is_array( $settings['selected_tables'] ?? null ) ? $settings['selected_tables'] : [];
        $email_recipients = (array) ( $settings['destinations']['email']['recipients'] ?? [] );
        $local_on         = ! empty( $settings['destinations']['local']['enabled'] );
        $email_on         = ! empty( $settings['destinations']['email']['enabled'] );
        $custom_join      = implode( "\n", $custom_tables );
        $rec_join         = implode( ', ', $email_recipients );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_backup_save_settings', 'tt_backup_nonce' ); ?>
            <input type="hidden" name="action" value="tt_backup_save_settings" />
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="tt_bk_preset"><?php esc_html_e( 'Preset', 'talenttrack' ); ?></label></th>
                    <td>
                        <select id="tt_bk_preset" name="preset">
                            <?php foreach ( PresetRegistry::all() as $p ) : ?>
                                <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $preset, $p ); ?>>
                                    <?php echo esc_html( PresetRegistry::label( $p ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Minimal: core data only. Standard: everyday operational data. Thorough: everything including audit log and lookups.', 'talenttrack' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tt_bk_custom_tables"><?php esc_html_e( 'Custom tables', 'talenttrack' ); ?></label></th>
                    <td>
                        <textarea id="tt_bk_custom_tables" name="selected_tables" rows="6" cols="40" placeholder="tt_players&#10;tt_teams&#10;..."><?php echo esc_textarea( $custom_join ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Only used when preset is "Custom". One table per line, including the tt_ prefix but not the WordPress prefix.', 'talenttrack' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tt_bk_schedule"><?php esc_html_e( 'Schedule', 'talenttrack' ); ?></label></th>
                    <td>
                        <select id="tt_bk_schedule" name="schedule">
                            <option value="daily"     <?php selected( $schedule, 'daily' );     ?>><?php esc_html_e( 'Daily',     'talenttrack' ); ?></option>
                            <option value="weekly"    <?php selected( $schedule, 'weekly' );    ?>><?php esc_html_e( 'Weekly',    'talenttrack' ); ?></option>
                            <option value="on_demand" <?php selected( $schedule, 'on_demand' ); ?>><?php esc_html_e( 'On demand', 'talenttrack' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tt_bk_retention"><?php esc_html_e( 'Retention', 'talenttrack' ); ?></label></th>
                    <td>
                        <input type="number" id="tt_bk_retention" name="retention" min="1" max="365" value="<?php echo esc_attr( (string) $retention ); ?>" />
                        <p class="description">
                            <?php esc_html_e( 'Keep this many local backups before purging the oldest.', 'talenttrack' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Local destination', 'talenttrack' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="dest_local" value="1" <?php checked( $local_on ); ?> />
                            <?php
                            $dir = LocalDestination::dir();
                            echo $dir !== ''
                                ? esc_html( sprintf( __( 'Save backups to %s', 'talenttrack' ), $dir ) )
                                : esc_html__( 'Save backups to wp-content/uploads/talenttrack-backups/', 'talenttrack' );
                            ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Email destination', 'talenttrack' ); ?></th>
                    <td>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="checkbox" name="dest_email" value="1" <?php checked( $email_on ); ?> />
                            <?php esc_html_e( 'Email each backup to the recipients below', 'talenttrack' ); ?>
                        </label>
                        <input type="text" name="email_recipients" class="regular-text" value="<?php echo esc_attr( $rec_join ); ?>" />
                        <p class="description">
                            <?php esc_html_e( 'Comma-separated list. Files larger than 10 MB will not be attached — recipients receive a notice instead.', 'talenttrack' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save backup settings', 'talenttrack' ) ); ?>
        </form>
        <?php
    }

    private static function renderRunNowButton(): void {
        ?>
        <h3 style="margin-top:32px;"><?php esc_html_e( 'Run a backup now', 'talenttrack' ); ?></h3>
        <p style="max-width:680px;">
            <?php esc_html_e( 'Triggers a backup with the current settings without waiting for the scheduled run. Useful for testing, before risky operations, or on low-traffic sites where WP-cron does not fire reliably.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_backup_run_now', 'tt_backup_nonce' ); ?>
            <input type="hidden" name="action" value="tt_backup_run_now" />
            <?php submit_button( __( 'Run backup now', 'talenttrack' ), 'primary', '', false ); ?>
        </form>
        <?php
    }

    /** @param array<int,array<string,mixed>> $list */
    private static function renderBackupsList( array $list ): void {
        ?>
        <h3 style="margin-top:32px;"><?php esc_html_e( 'Stored backups (local)', 'talenttrack' ); ?></h3>
        <?php if ( empty( $list ) ) : ?>
            <p><em><?php esc_html_e( 'No local backups yet.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:980px;">
                <thead><tr>
                    <th><?php esc_html_e( 'Filename', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Preset',  'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Size',    'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $list as $row ) :
                    $id   = (string) ( $row['id']         ?? '' );
                    $size = (int)    ( $row['size']       ?? 0 );
                    $when = (string) ( $row['created_at'] ?? '' );
                    $prst = (string) ( $row['preset']     ?? '' );
                    ?>
                    <tr>
                        <td><code><?php echo esc_html( $id ); ?></code></td>
                        <td><?php echo esc_html( $when ); ?></td>
                        <td><?php echo esc_html( $prst ); ?></td>
                        <td><?php echo esc_html( size_format( $size ) ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( self::actionUrl( 'tt_backup_download', [ 'id' => $id ] ) ); ?>"><?php esc_html_e( 'Download', 'talenttrack' ); ?></a>
                            |
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-config&tab=backups&restore=' . rawurlencode( $id ) ) ); ?>"><?php esc_html_e( 'Restore', 'talenttrack' ); ?></a>
                            |
                            <a href="<?php echo esc_url( self::actionUrl( 'tt_backup_delete', [ 'id' => $id ] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this backup file?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php
        if ( isset( $_GET['restore'] ) ) self::renderRestoreConfirmation( sanitize_text_field( wp_unslash( (string) $_GET['restore'] ) ) );
    }

    private static function renderRestoreConfirmation( string $id ): void {
        $local = new LocalDestination();
        $path  = $local->fetchLocalPath( $id );
        if ( $path === '' ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Backup not found.', 'talenttrack' ) . '</p></div>';
            return;
        }
        $preview = \TT\Modules\Backup\BackupRestorer::preview( $path );
        ?>
        <h3 style="margin-top:32px;"><?php esc_html_e( 'Restore from backup', 'talenttrack' ); ?></h3>
        <?php if ( empty( $preview['ok'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( (string) ( $preview['error'] ?? 'Unknown error' ) ); ?></p></div>
        <?php else : ?>
            <p style="max-width:760px;">
                <strong><?php esc_html_e( 'This action will replace the current data with the contents of the backup.', 'talenttrack' ); ?></strong>
                <?php
                printf(
                    /* translators: 1: backup created date, 2: plugin version */
                    esc_html__( ' Snapshot created %1$s on plugin version %2$s.', 'talenttrack' ),
                    esc_html( (string) $preview['created_at'] ),
                    esc_html( (string) $preview['plugin_version'] )
                );
                ?>
            </p>
            <table class="widefat striped" style="max-width:520px;">
                <thead><tr><th><?php esc_html_e( 'Table', 'talenttrack' ); ?></th><th style="text-align:right;"><?php esc_html_e( 'Rows', 'talenttrack' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( (array) ( $preview['summary'] ?? [] ) as $tbl => $count ) : ?>
                    <tr><td><code><?php echo esc_html( (string) $tbl ); ?></code></td><td style="text-align:right;"><?php echo (int) $count; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px;">
                <?php wp_nonce_field( 'tt_backup_restore', 'tt_backup_nonce' ); ?>
                <input type="hidden" name="action" value="tt_backup_restore" />
                <input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
                <label>
                    <?php esc_html_e( 'Type RESTORE to confirm:', 'talenttrack' ); ?>
                    <input type="text" name="confirm_text" placeholder="RESTORE" class="regular-text" required />
                </label>
                <p>
                    <?php submit_button( __( 'Restore from this backup', 'talenttrack' ), 'delete', '', false ); ?>
                </p>
            </form>
        <?php endif;
    }

    /* ═══════════════ Handlers ═══════════════ */

    public static function handleSaveSettings(): void {
        self::guard( 'tt_backup_save_settings' );

        $preset           = sanitize_key( (string) wp_unslash( $_POST['preset'] ?? '' ) );
        $schedule         = sanitize_key( (string) wp_unslash( $_POST['schedule'] ?? '' ) );
        $retention        = (int) ( $_POST['retention'] ?? 30 );
        $selected_tables  = preg_split( '/\s+/', (string) wp_unslash( $_POST['selected_tables'] ?? '' ) ) ?: [];
        $local_on         = ! empty( $_POST['dest_local'] );
        $email_on         = ! empty( $_POST['dest_email'] );
        $email_recipients = (string) wp_unslash( $_POST['email_recipients'] ?? '' );

        BackupSettings::save( [
            'preset'          => $preset,
            'selected_tables' => $selected_tables,
            'schedule'        => $schedule,
            'retention'       => $retention,
            'destinations'    => [
                'local' => [ 'enabled' => $local_on ],
                'email' => [
                    'enabled'    => $email_on,
                    'recipients' => $email_recipients,
                ],
            ],
        ] );

        Scheduler::reconcile();
        self::redirectBack( [ 'tt_msg' => 'saved' ] );
    }

    public static function handleRunNow(): void {
        self::guard( 'tt_backup_run_now' );
        $result = BackupRunner::run();
        self::redirectBack( [ 'tt_bk_msg' => $result['ok'] ? 'ran' : 'run_failed' ] );
    }

    public static function handleDelete(): void {
        self::guard( 'tt_backup_delete' );
        $id    = sanitize_text_field( wp_unslash( (string) ( $_GET['id'] ?? '' ) ) );
        $local = new LocalDestination();
        $local->purge( $id );
        self::redirectBack( [ 'tt_bk_msg' => 'deleted' ] );
    }

    public static function handleDownload(): void {
        self::guard( 'tt_backup_download' );
        $id    = sanitize_text_field( wp_unslash( (string) ( $_GET['id'] ?? '' ) ) );
        $local = new LocalDestination();
        $path  = $local->fetchLocalPath( $id );
        if ( $path === '' ) {
            wp_die( esc_html__( 'Backup not found.', 'talenttrack' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/gzip' );
        header( 'Content-Disposition: attachment; filename="' . basename( $id ) . '"' );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        readfile( $path );
        exit;
    }

    public static function handleRestore(): void {
        self::guard( 'tt_backup_restore' );
        $id      = sanitize_text_field( wp_unslash( (string) ( $_POST['id'] ?? '' ) ) );
        $confirm = trim( (string) wp_unslash( $_POST['confirm_text'] ?? '' ) );
        if ( $confirm !== 'RESTORE' ) {
            self::redirectBack( [ 'tt_bk_msg' => 'restore_unconfirmed' ] );
        }
        $local = new LocalDestination();
        $path  = $local->fetchLocalPath( $id );
        if ( $path === '' ) {
            self::redirectBack( [ 'tt_bk_msg' => 'restore_missing' ] );
        }
        $r = \TT\Modules\Backup\BackupRestorer::restore( $path );
        self::redirectBack( [ 'tt_bk_msg' => $r['ok'] ? 'restored' : 'restore_failed' ] );
    }

    /* ═══════════════ Helpers ═══════════════ */

    private static function guard( string $action ): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( $action, 'tt_backup_nonce' );
    }

    /** @param array<string,scalar> $extra */
    private static function actionUrl( string $action, array $extra = [] ): string {
        return wp_nonce_url(
            add_query_arg(
                array_merge( [ 'action' => $action ], $extra ),
                admin_url( 'admin-post.php' )
            ),
            $action,
            'tt_backup_nonce'
        );
    }

    /** @param array<string,scalar> $extra */
    private static function redirectBack( array $extra = [] ): void {
        $url = add_query_arg(
            array_merge( [ 'page' => 'tt-config', 'tab' => 'backups' ], $extra ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }
}
