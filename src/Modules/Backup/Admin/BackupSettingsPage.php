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
        add_action( 'admin_post_tt_backup_save_settings',     [ self::class, 'handleSaveSettings' ] );
        add_action( 'admin_post_tt_backup_run_now',           [ self::class, 'handleRunNow' ] );
        add_action( 'admin_post_tt_backup_delete',            [ self::class, 'handleDelete' ] );
        add_action( 'admin_post_tt_backup_download',          [ self::class, 'handleDownload' ] );
        add_action( 'admin_post_tt_backup_restore',           [ self::class, 'handleRestore' ] );
        add_action( 'admin_post_tt_backup_bulk_undo',         [ self::class, 'handleBulkUndo' ] );
        add_action( 'admin_post_tt_backup_bulk_undo_dismiss', [ self::class, 'handleBulkUndoDismiss' ] );
        add_action( 'admin_post_tt_backup_partial_execute',   [ self::class, 'handlePartialExecute' ] );
        // #1464 phase 1 — data migration export.
        add_action( 'admin_post_tt_migration_export',         [ self::class, 'handleMigrationExport' ] );
        // #1464 phase 2 — data migration import (upload + read-only preview).
        add_action( 'admin_post_tt_migration_import_preview', [ self::class, 'handleMigrationImportPreview' ] );
        // #1464 phase 3-4 — dry-run preview + typed-confirm commit.
        add_action( 'admin_post_tt_migration_import_dryrun',  [ self::class, 'handleMigrationImportDryRun' ] );
        add_action( 'admin_post_tt_migration_import_commit',  [ self::class, 'handleMigrationImportCommit' ] );
    }

    // Render

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        self::enqueuePageAssets();

        // Partial restore mode is reachable via ?partial=<backup-id>
        // from the stored-backups list. Render that view in place of
        // the settings form so the admin focuses on the restore.
        if ( isset( $_GET['partial'] ) ) {
            self::renderPartialRestore( sanitize_text_field( wp_unslash( (string) $_GET['partial'] ) ) );
            return;
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

        <?php self::renderMigrationSection(); ?>

        <?php
        // #0063 — show actual next-run from wp_cron next to last-run.
        // The previous version computed "next run" as last + interval,
        // which drifts from the real cron event when WP-cron fires
        // late (heavy traffic / missed pings). wp_next_scheduled is
        // the source of truth.
        $next_ts = function_exists( 'wp_next_scheduled' ) ? (int) wp_next_scheduled( \TT\Modules\Backup\Scheduler::HOOK ) : 0;
        ?>
        <?php if ( $last_run || $next_ts > 0 ) : ?>
            <div class="notice notice-info" style="margin:16px 0;">
                <p>
                    <?php if ( $last_run ) : ?>
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
                    <?php endif; ?>
                    <?php if ( $next_ts > 0 ) : ?>
                        <?php if ( $last_run ) echo ' &middot; '; ?>
                        <strong><?php esc_html_e( 'Next run:', 'talenttrack' ); ?></strong>
                        <?php
                        if ( $next_ts <= time() ) {
                            esc_html_e( 'overdue (will fire on the next WP-cron tick)', 'talenttrack' );
                        } else {
                            printf(
                                /* translators: %s is human-readable time-until */
                                esc_html__( 'in about %s', 'talenttrack' ),
                                esc_html( human_time_diff( time(), $next_ts ) )
                            );
                        }
                        ?>
                    <?php elseif ( ! empty( $settings['enabled'] ) ) : ?>
                        &middot;
                        <em><?php esc_html_e( 'Next run is not scheduled yet — save settings once to schedule the cron event.', 'talenttrack' ); ?></em>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <?php self::renderSettingsForm( $settings ); ?>
        <?php self::renderRunNowButton(); ?>
        <?php self::renderBackupsList( $list ); ?>
        <?php self::renderRunningOverlay(); ?>
        <?php
    }

    /**
     * Enqueue the small backup-page.js + the per-preset description data
     * it consumes. Confirm.js + admin-confirm.js are already enqueued by
     * Menu::enqueue() on every TT-prefixed admin page.
     */
    private static function enqueuePageAssets(): void {
        wp_enqueue_script(
            'tt-backup-page',
            TT_PLUGIN_URL . 'assets/js/backup-page.js',
            [],
            TT_VERSION,
            true
        );
        $descriptions = [];
        foreach ( PresetRegistry::all() as $p ) {
            $descriptions[ $p ] = PresetRegistry::description( $p );
        }
        wp_localize_script( 'tt-backup-page', 'TT_BACKUP', [
            'preset_descriptions' => $descriptions,
        ] );
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
                        <select id="tt_bk_preset" name="preset" data-tt-bk-preset-select>
                            <?php foreach ( PresetRegistry::all() as $p ) : ?>
                                <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $preset, $p ); ?>>
                                    <?php echo esc_html( PresetRegistry::label( $p ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description" data-tt-bk-preset-description>
                            <?php echo esc_html( PresetRegistry::description( $preset ) ); ?>
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
                        <input type="number" inputmode="numeric" id="tt_bk_retention" name="retention" min="1" max="365" value="<?php echo esc_attr( (string) $retention ); ?>" />
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
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-tt-bk-run-now-form>
            <?php wp_nonce_field( 'tt_backup_run_now', 'tt_backup_nonce' ); ?>
            <input type="hidden" name="action" value="tt_backup_run_now" />
            <?php submit_button( __( 'Run backup now', 'talenttrack' ), 'primary', '', false ); ?>
        </form>
        <?php
    }

    /**
     * Full-screen "in progress" overlay shown by JS while a Run-Now or
     * Restore submit is in flight. Non-dismissible by design — once the
     * server responds (admin-post redirects back), the page reloads
     * and the overlay is gone naturally.
     */
    private static function renderRunningOverlay(): void {
        ?>
        <div class="tt-bk-overlay" data-tt-bk-overlay hidden role="alertdialog" aria-live="assertive" aria-labelledby="tt-bk-overlay-title">
            <div class="tt-bk-overlay-card">
                <div class="tt-bk-spinner" aria-hidden="true"></div>
                <h3 id="tt-bk-overlay-title" class="tt-bk-overlay-title">
                    <?php esc_html_e( 'Backup in progress…', 'talenttrack' ); ?>
                </h3>
                <p class="tt-bk-overlay-msg" data-tt-bk-overlay-msg>
                    <?php esc_html_e( 'Hang on while we snapshot your TalentTrack tables. This usually takes a few seconds — please don\'t close this tab.', 'talenttrack' ); ?>
                </p>
            </div>
        </div>
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
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-config&tab=backups&restore=' . rawurlencode( $id ) ) ); ?>"
                               data-tt-confirm-message="<?php echo esc_attr__( 'Open the restore preview for this backup? You will be asked to confirm again before any data is replaced.', 'talenttrack' ); ?>"
                               data-tt-confirm-title="<?php echo esc_attr__( 'Open restore preview?', 'talenttrack' ); ?>"
                               data-tt-confirm-confirm-label="<?php echo esc_attr__( 'Open preview', 'talenttrack' ); ?>"><?php esc_html_e( 'Restore', 'talenttrack' ); ?></a>
                            |
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-config&tab=backups&partial=' . rawurlencode( $id ) ) ); ?>"><?php esc_html_e( 'Partial restore', 'talenttrack' ); ?></a>
                            |
                            <a href="<?php echo esc_url( self::actionUrl( 'tt_backup_delete', [ 'id' => $id ] ) ); ?>"
                               data-tt-confirm-message="<?php echo esc_attr__( 'Delete this backup file? This cannot be undone.', 'talenttrack' ); ?>"
                               data-tt-confirm-title="<?php echo esc_attr__( 'Delete backup file?', 'talenttrack' ); ?>"
                               data-tt-confirm-confirm-label="<?php echo esc_attr__( 'Delete', 'talenttrack' ); ?>"
                               data-tt-confirm-danger
                               style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a>
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
            <div class="notice notice-error"><p><?php echo esc_html( (string) ( $preview['error'] ?? __( 'Unknown error', 'talenttrack' ) ) ); ?></p></div>
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
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px;"
                  data-tt-bk-restore-form
                  data-tt-bk-restore-msg="<?php echo esc_attr__( 'Restoring your TalentTrack tables from the snapshot. This usually takes a few seconds — please don\'t close this tab.', 'talenttrack' ); ?>">
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

    // Handlers

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

    /**
     * #1464 phase 1 — "Data migration" export section. Lets an operator
     * pick which data sets to bundle into a downloadable `.ttmig` archive
     * to import on another TalentTrack install. Import is a later phase.
     */
    private static function renderMigrationSection(): void {
        $groups       = \TT\Modules\Backup\MigrationExporter::entityGroups();
        $record_ents  = \TT\Modules\Backup\MigrationExporter::recordEntities();
        ?>
        <hr style="margin:24px 0;">
        <h3><?php esc_html_e( 'Data migration', 'talenttrack' ); ?></h3>
        <p style="max-width:760px;">
            <?php esc_html_e( 'Move data to another TalentTrack install. Choose which data sets to include; you get a .ttmig file to import on the other install. Data only — WordPress users and media are not included.', 'talenttrack' ); ?>
        </p>
        <p style="max-width:760px; color:#5b6e75;">
            <?php esc_html_e( 'Expand a data set to leave individual records behind (e.g. test players). Everything is included by default; untick a record to exclude it.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_migration_export', 'tt_backup_nonce' ); ?>
            <input type="hidden" name="action" value="tt_migration_export" />
            <fieldset style="margin:8px 0 16px; max-width:760px;">
                <?php foreach ( $groups as $key => $g ) : ?>
                    <label style="display:flex; align-items:center; gap:8px; min-height:48px;">
                        <input type="checkbox" name="entities[]" value="<?php echo esc_attr( $key ); ?>" checked style="width:20px; height:20px;" />
                        <span><?php echo esc_html( $g['label'] ); ?></span>
                    </label>
                    <?php
                    if ( isset( $record_ents[ $key ] ) ) {
                        self::renderMigrationRecordsExpander( (string) $key, (string) $g['label'] );
                    }
                    ?>
                <?php endforeach; ?>
            </fieldset>
            <?php submit_button( __( 'Export for migration', 'talenttrack' ), 'secondary', 'submit', false ); ?>
        </form>

        <?php self::renderMigrationImportForm(); ?>
        <?php
    }

    /**
     * #1464 phase 2 — upload form for a `.ttmig` archive. Submitting runs a
     * read-only preview (validation + per-entity counts + stable-key
     * conflict analysis); it never writes. Applying the import — id
     * remapping, conflict resolution and wp_user mapping — lands in a later
     * phase, so the form is explicit that this step only inspects the file.
     */
    private static function renderMigrationImportForm(): void {
        $max = size_format( \TT\Modules\Backup\MigrationImporter::MAX_UPLOAD_BYTES );
        ?>
        <h4 style="margin:24px 0 6px;"><?php esc_html_e( 'Import from another install', 'talenttrack' ); ?></h4>
        <p style="max-width:760px;">
            <?php esc_html_e( 'Upload a .ttmig file exported from another TalentTrack install to preview what it contains. This step only inspects the archive and reports what would be imported — it does not change any data yet.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field( 'tt_migration_import', 'tt_backup_nonce' ); ?>
            <input type="hidden" name="action" value="tt_migration_import_preview" />
            <p>
                <label for="tt_mig_file" style="display:block; margin-bottom:6px;">
                    <?php esc_html_e( 'Migration archive (.ttmig)', 'talenttrack' ); ?>
                </label>
                <input type="file" id="tt_mig_file" name="migration_file" accept=".ttmig,application/gzip" required style="min-height:44px;" />
                <span class="description" style="display:block; margin-top:4px;">
                    <?php
                    /* translators: %s is a human-readable file size, e.g. "25 MB". */
                    echo esc_html( sprintf( __( 'Maximum %s. Larger datasets are a later phase.', 'talenttrack' ), $max ) );
                    ?>
                </span>
            </p>
            <?php submit_button( __( 'Preview import', 'talenttrack' ), 'secondary', 'submit', false ); ?>
        </form>
        <?php
    }

    /**
     * #1517 — per-record include/exclude expander for one record-bearing
     * entity group. A collapsed `<details>` keeps the form compact; inside,
     * each record is an "include" checkbox checked by default. A hidden
     * `mig_all[<entity>]` field carries every rendered id so the handler can
     * derive the excluded set (unchecked checkboxes aren't submitted).
     */
    private static function renderMigrationRecordsExpander( string $entity, string $label ): void {
        $records = self::migrationRecordsFor( $entity );
        if ( empty( $records['rows'] ) ) return;

        $all_ids = implode( ',', array_map( static fn ( array $r ): int => (int) $r['id'], $records['rows'] ) );
        ?>
        <details style="margin:0 0 10px 28px;">
            <summary style="cursor:pointer; color:#1d7874; min-height:44px; line-height:44px;">
                <?php
                /* translators: %d: number of records. */
                echo esc_html( sprintf( _n( 'Show %d record', 'Show %d records', count( $records['rows'] ), 'talenttrack' ), count( $records['rows'] ) ) );
                ?>
            </summary>
            <input type="hidden" name="mig_all[<?php echo esc_attr( $entity ); ?>]" value="<?php echo esc_attr( $all_ids ); ?>" />
            <?php if ( $records['truncated'] ) : ?>
                <p style="color:#b06000; margin:6px 0;"><em>
                    <?php
                    /* translators: %d: number of records shown. */
                    echo esc_html( sprintf( __( 'Showing the first %d records; any beyond that are always included.', 'talenttrack' ), count( $records['rows'] ) ) );
                    ?>
                </em></p>
            <?php endif; ?>
            <ul style="list-style:none; margin:6px 0; padding:0;">
                <?php foreach ( $records['rows'] as $rec ) : ?>
                    <li>
                        <label style="display:flex; align-items:center; gap:8px; min-height:44px;">
                            <input type="checkbox" name="mig_keep[<?php echo esc_attr( $entity ); ?>][]" value="<?php echo (int) $rec['id']; ?>" checked style="width:20px; height:20px;" />
                            <span><code>#<?php echo (int) $rec['id']; ?></code> <?php echo esc_html( $rec['label'] ); ?></span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php
    }

    /**
     * Fetch the primary records of a record-bearing migration entity, with
     * a human display name. Capped to keep the expander manageable; the cap
     * is surfaced (`truncated`) rather than silently dropping records — rows
     * beyond the cap are still exported (they just can't be unticked here).
     *
     * @return array{rows: array<int, array{id:int, label:string}>, truncated: bool}
     */
    private static function migrationRecordsFor( string $entity ): array {
        $entities = \TT\Modules\Backup\MigrationExporter::recordEntities();
        if ( ! isset( $entities[ $entity ] ) ) return [ 'rows' => [], 'truncated' => false ];

        global $wpdb;
        $table = $wpdb->prefix . $entities[ $entity ]['table'];
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [ 'rows' => [], 'truncated' => false ];
        }

        $cap   = 500;
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $raw   = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC LIMIT " . ( $cap + 1 ), ARRAY_A );
        $raw   = is_array( $raw ) ? $raw : [];
        $truncated = count( $raw ) > $cap;
        if ( $truncated ) {
            $raw = array_slice( $raw, 0, $cap );
        }

        $rows = [];
        foreach ( $raw as $r ) {
            $id = (int) ( $r['id'] ?? 0 );
            if ( $id <= 0 ) continue;
            $rows[] = [ 'id' => $id, 'label' => self::migrationDisplayName( $r ) ];
        }
        return [ 'rows' => $rows, 'truncated' => $truncated, 'total' => $total ];
    }

    /**
     * Best-effort human label for a migration record row, from whichever
     * common name columns the table carries.
     *
     * @param array<string,mixed> $row
     */
    private static function migrationDisplayName( array $row ): string {
        $first = trim( (string) ( $row['first_name'] ?? '' ) );
        $last  = trim( (string) ( $row['last_name'] ?? '' ) );
        if ( $first !== '' || $last !== '' ) {
            return trim( $first . ' ' . $last );
        }
        foreach ( [ 'name', 'title', 'label' ] as $col ) {
            $val = trim( (string) ( $row[ $col ] ?? '' ) );
            if ( $val !== '' ) return $val;
        }
        // Evaluations and similar dated records: fall back to a date column.
        foreach ( [ 'eval_date', 'session_date', 'date', 'created_at' ] as $col ) {
            $val = trim( (string) ( $row[ $col ] ?? '' ) );
            if ( $val !== '' ) return $val;
        }
        return __( '(no label)', 'talenttrack' );
    }

    /**
     * #1464 phase 1 — stream a `.ttmig` export of the selected data sets.
     */
    public static function handleMigrationExport(): void {
        self::guard( 'tt_migration_export' );

        $requested = isset( $_POST['entities'] ) && is_array( $_POST['entities'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['entities'] ) )
            : [];
        $valid = array_values( array_intersect( $requested, \TT\Modules\Backup\MigrationExporter::entityKeys() ) );
        if ( empty( $valid ) ) {
            self::redirectBack( [ 'tt_bk_msg' => 'migration_empty' ] );
        }

        // #1517 — per-record exclusions. On the confirm re-submit they
        // arrive as a compact JSON blob; on first submit derive them from
        // the rendered ids (mig_all) minus the kept ids (mig_keep).
        $confirmed  = ! empty( $_POST['mig_confirm'] );
        $exclusions = $confirmed
            ? self::parseMigrationExclusionsJson( wp_unslash( (string) ( $_POST['mig_exclusions_json'] ?? '' ) ) )
            : self::parseMigrationExclusions();
        $exclusions = array_intersect_key( $exclusions, array_flip( $valid ) );

        // #1517 — warn (but allow) when an excluded record leaves included
        // dependents orphaned in another data set. Show a confirmation step
        // the first time; "Download anyway" re-submits with mig_confirm.
        if ( ! $confirmed && ! empty( $exclusions ) ) {
            $orphans = self::detectMigrationOrphans( $valid, $exclusions );
            if ( ! empty( $orphans ) ) {
                self::renderMigrationConfirm( $valid, $exclusions, $orphans );
                exit;
            }
        }

        $bytes = \TT\Modules\Backup\MigrationExporter::export( $valid, $exclusions );
        if ( $bytes === '' ) {
            self::redirectBack( [ 'tt_bk_msg' => 'migration_empty' ] );
        }

        $filename = \TT\Modules\Backup\MigrationExporter::filename( gmdate( 'Ymd-His' ) );
        nocache_headers();
        header( 'Content-Type: application/gzip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . (string) strlen( $bytes ) );
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary gzip stream.
        exit;
    }

    /**
     * #1517 — derive per-entity excluded ids from the export form:
     * (all rendered ids) − (kept/checked ids), per record-bearing entity.
     *
     * @return array<string, array<int,int>>
     */
    private static function parseMigrationExclusions(): array {
        $all  = isset( $_POST['mig_all'] ) && is_array( $_POST['mig_all'] ) ? wp_unslash( $_POST['mig_all'] ) : [];
        $keep = isset( $_POST['mig_keep'] ) && is_array( $_POST['mig_keep'] ) ? wp_unslash( $_POST['mig_keep'] ) : [];
        $record_keys = array_keys( \TT\Modules\Backup\MigrationExporter::recordEntities() );

        $out = [];
        foreach ( $all as $entity => $csv ) {
            $entity = sanitize_key( (string) $entity );
            if ( ! in_array( $entity, $record_keys, true ) ) continue;

            $all_ids  = array_filter( array_map( 'intval', explode( ',', (string) $csv ) ) );
            $kept_ids = isset( $keep[ $entity ] ) && is_array( $keep[ $entity ] )
                ? array_map( 'intval', $keep[ $entity ] )
                : [];
            $excluded = array_values( array_diff( $all_ids, $kept_ids ) );
            if ( ! empty( $excluded ) ) {
                $out[ $entity ] = $excluded;
            }
        }
        return $out;
    }

    /**
     * #1517 — decode the confirm step's serialized exclusion set.
     *
     * @return array<string, array<int,int>>
     */
    private static function parseMigrationExclusionsJson( string $json ): array {
        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) ) return [];
        $record_keys = array_keys( \TT\Modules\Backup\MigrationExporter::recordEntities() );

        $out = [];
        foreach ( $decoded as $entity => $ids ) {
            $entity = sanitize_key( (string) $entity );
            if ( ! in_array( $entity, $record_keys, true ) || ! is_array( $ids ) ) continue;
            $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
            if ( ! empty( $ids ) ) $out[ $entity ] = $ids;
        }
        return $out;
    }

    /**
     * #1517 — find included records that reference an excluded record in
     * another data set (e.g. an evaluation kept while its player is
     * excluded). Uses BackupDependencyMap to walk parent→child references.
     * Within-group children (which follow their parent's exclusion) and
     * tables not part of the export are skipped.
     *
     * @param string[] $valid                          exported entity keys
     * @param array<string, array<int,int>> $exclusions
     * @return array<int, array{parent_entity:string, child_table:string, count:int}>
     */
    private static function detectMigrationOrphans( array $valid, array $exclusions ): array {
        global $wpdb;
        $entities = \TT\Modules\Backup\MigrationExporter::recordEntities();
        $groups   = \TT\Modules\Backup\MigrationExporter::entityGroups();
        $inverse  = \TT\Modules\Backup\BackupDependencyMap::inverse();

        // Tables actually present in the export (any selected group's tables).
        $exported_tables = [];
        foreach ( $valid as $key ) {
            foreach ( $groups[ $key ]['tables'] ?? [] as $t ) $exported_tables[ $t ] = true;
        }
        // Excluded ids keyed by the table that owns them (primary tables).
        $excluded_by_table = [];
        foreach ( $exclusions as $entity => $ids ) {
            if ( isset( $entities[ $entity ] ) ) $excluded_by_table[ $entities[ $entity ]['table'] ] = array_map( 'intval', $ids );
        }

        $orphans = [];
        foreach ( $exclusions as $entity => $ids ) {
            if ( empty( $ids ) || ! isset( $entities[ $entity ] ) ) continue;
            $primary      = $entities[ $entity ]['table'];
            $own_children = $entities[ $entity ]['children'];
            $ids          = array_values( array_unique( array_map( 'intval', $ids ) ) );
            $id_list      = implode( ',', $ids );

            foreach ( $inverse[ $primary ] ?? [] as $child_table => $cols ) {
                if ( isset( $own_children[ $child_table ] ) ) continue;       // follows parent — already excluded
                if ( empty( $exported_tables[ $child_table ] ) ) continue;    // not in this export

                $table = $wpdb->prefix . $child_table;
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) continue;

                $count = 0;
                foreach ( $cols as $col ) {
                    $col   = preg_replace( '/[^a-z0-9_]/i', '', (string) $col );
                    if ( $col === '' ) continue;
                    $sql   = "SELECT COUNT(*) FROM {$table} WHERE {$col} IN ({$id_list})";
                    // Don't count child rows that are themselves excluded.
                    if ( ! empty( $excluded_by_table[ $child_table ] ) ) {
                        $sql .= ' AND id NOT IN (' . implode( ',', $excluded_by_table[ $child_table ] ) . ')';
                    }
                    $count += (int) $wpdb->get_var( $sql );
                }
                if ( $count > 0 ) {
                    $orphans[] = [ 'parent_entity' => (string) $entity, 'child_table' => (string) $child_table, 'count' => $count ];
                }
            }
        }
        return $orphans;
    }

    /**
     * #1517 — confirmation interstitial shown when excluding records would
     * orphan included dependents. Lists the orphan risks and offers
     * "Download anyway" (re-submits with mig_confirm + the serialized
     * exclusion set) or a Cancel link back to the Backups page.
     *
     * @param string[] $valid
     * @param array<string, array<int,int>> $exclusions
     * @param array<int, array{parent_entity:string, child_table:string, count:int}> $orphans
     */
    private static function renderMigrationConfirm( array $valid, array $exclusions, array $orphans ): void {
        $groups = \TT\Modules\Backup\MigrationExporter::entityGroups();
        $table_label = static function ( string $bare ) use ( $groups ): string {
            foreach ( $groups as $g ) {
                if ( in_array( $bare, $g['tables'], true ) ) return (string) $g['label'];
            }
            return $bare;
        };
        $back = wp_get_referer() ?: admin_url( 'admin.php?page=talenttrack' );

        if ( ! function_exists( 'get_admin_page_title' ) ) require_once ABSPATH . 'wp-admin/includes/template.php';
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Confirm migration export', 'talenttrack' ) . '</h1>';
        echo '<p style="max-width:760px;">' . esc_html__( 'Some records you excluded are still referenced by data you are exporting. These references will point at records that are not in the archive:', 'talenttrack' ) . '</p>';
        echo '<ul style="max-width:760px; list-style:disc; margin-left:20px;">';
        foreach ( $orphans as $o ) {
            echo '<li>' . esc_html( sprintf(
                /* translators: 1: number of records, 2: data set the records live in, 3: excluded data set. */
                _n( '%1$d %2$s record references an excluded %3$s record.', '%1$d %2$s records reference an excluded %3$s record.', (int) $o['count'], 'talenttrack' ),
                (int) $o['count'],
                $table_label( (string) $o['child_table'] ),
                $table_label( \TT\Modules\Backup\MigrationExporter::recordEntities()[ $o['parent_entity'] ]['table'] ?? $o['parent_entity'] )
            ) ) . '</li>';
        }
        echo '</ul>';
        echo '<p style="max-width:760px;">' . esc_html__( 'You can export exactly as selected — the dependents will be imported without their referenced record — or go back and adjust your selection.', 'talenttrack' ) . '</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tt_migration_export', 'tt_backup_nonce' );
        echo '<input type="hidden" name="action" value="tt_migration_export" />';
        echo '<input type="hidden" name="mig_confirm" value="1" />';
        foreach ( $valid as $key ) {
            echo '<input type="hidden" name="entities[]" value="' . esc_attr( $key ) . '" />';
        }
        echo '<input type="hidden" name="mig_exclusions_json" value="' . esc_attr( (string) wp_json_encode( $exclusions ) ) . '" />';
        submit_button( __( 'Download anyway', 'talenttrack' ), 'primary', 'submit', false );
        echo ' <a class="button button-secondary" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'talenttrack' ) . '</a>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * #1464 phase 2 — handle a `.ttmig` upload and render a read-only
     * preview. Never writes: it validates the envelope, summarises the
     * contents and runs the stable-key conflict analysis, then renders the
     * result inline. Validation failures render in the same view so the
     * operator always sees an outcome.
     */
    public static function handleMigrationImportPreview(): void {
        self::guard( 'tt_migration_import' );

        // PHP-level upload checks first — surface them as the validation
        // error so the preview page can show a single, consistent message.
        $err = (int) ( $_FILES['migration_file']['error'] ?? UPLOAD_ERR_NO_FILE );
        $tmp = isset( $_FILES['migration_file']['tmp_name'] ) ? (string) $_FILES['migration_file']['tmp_name'] : '';
        $size = (int) ( $_FILES['migration_file']['size'] ?? 0 );

        $fail_msg = '';
        if ( $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || $size > \TT\Modules\Backup\MigrationImporter::MAX_UPLOAD_BYTES ) {
            $fail_msg = sprintf(
                /* translators: %s is a human-readable file size, e.g. "25 MB". */
                __( 'The file is larger than the %s upload limit. Importing larger datasets is a later phase.', 'talenttrack' ),
                size_format( \TT\Modules\Backup\MigrationImporter::MAX_UPLOAD_BYTES )
            );
        } elseif ( $err !== UPLOAD_ERR_OK || $tmp === '' || ! is_uploaded_file( $tmp ) ) {
            $fail_msg = __( 'No file was uploaded. Choose a .ttmig archive and try again.', 'talenttrack' );
        }

        if ( $fail_msg !== '' ) {
            self::renderMigrationImportPreview( [], [ 'ok' => false, 'error' => $fail_msg, 'warnings' => [], 'plugin_version' => '', 'created_at' => '', 'entities' => [] ] );
            exit;
        }

        $bytes      = (string) file_get_contents( $tmp );
        $snapshot   = \TT\Modules\Backup\BackupSerializer::fromGzippedJson( $bytes );
        $validation = \TT\Modules\Backup\MigrationImporter::validate( $snapshot );

        // #1464 phase 3 — on a valid upload, stage the archive so the
        // dry-run + commit steps can reload it without a re-upload. One
        // staging slot per operator; overwritten on each new upload.
        if ( ! empty( $validation['ok'] ) ) {
            self::stageMigrationUpload( $bytes );
        }

        self::renderMigrationImportPreview( is_array( $snapshot ) ? $snapshot : [], $validation );
        exit;
    }

    /**
     * #1464 phase 2 — render the read-only import preview page: validation
     * outcome, source metadata, per-entity row counts and the stable-key
     * conflict analysis (would-insert vs would-update once the write engine
     * lands). No commit affordance — applying the import is a later phase.
     *
     * @param array<string,mixed> $snapshot
     * @param array{ok:bool, error:string, warnings:string[], plugin_version:string, created_at:string, entities:string[]} $validation
     */
    private static function renderMigrationImportPreview( array $snapshot, array $validation ): void {
        if ( ! function_exists( 'get_admin_page_title' ) ) require_once ABSPATH . 'wp-admin/includes/template.php';
        $back = admin_url( 'admin.php?page=tt-config&tab=backups' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Import preview', 'talenttrack' ) . '</h1>';

        if ( empty( $validation['ok'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( (string) ( $validation['error'] ?? __( 'The migration archive could not be read.', 'talenttrack' ) ) ) . '</p></div>';
            echo '<p><a class="button button-secondary" href="' . esc_url( $back ) . '">' . esc_html__( 'Back to backups', 'talenttrack' ) . '</a></p>';
            echo '</div>';
            return;
        }

        foreach ( (array) ( $validation['warnings'] ?? [] ) as $w ) {
            echo '<div class="notice notice-warning"><p>' . esc_html( (string) $w ) . '</p></div>';
        }

        $created = (string) ( $validation['created_at'] ?? '' );
        $version = (string) ( $validation['plugin_version'] ?? '' );
        echo '<p style="max-width:760px;">';
        if ( $created !== '' || $version !== '' ) {
            printf(
                /* translators: 1: archive creation date, 2: source plugin version */
                esc_html__( 'Archive created %1$s on plugin version %2$s. Nothing below has been imported — this is a preview only.', 'talenttrack' ),
                esc_html( $created !== '' ? $created : __( '(unknown date)', 'talenttrack' ) ),
                esc_html( $version !== '' ? $version : __( '(unknown)', 'talenttrack' ) )
            );
        } else {
            esc_html_e( 'Nothing below has been imported — this is a preview only.', 'talenttrack' );
        }
        echo '</p>';

        // Per-entity contents.
        $summary = \TT\Modules\Backup\MigrationImporter::summarize( $snapshot );
        echo '<h2>' . esc_html__( 'Contents', 'talenttrack' ) . '</h2>';
        if ( empty( $summary ) ) {
            echo '<p><em>' . esc_html__( 'The archive contains no recognised data sets.', 'talenttrack' ) . '</em></p>';
        } else {
            echo '<table class="widefat striped" style="max-width:620px;">';
            echo '<thead><tr><th>' . esc_html__( 'Data set', 'talenttrack' ) . '</th><th style="text-align:right;">' . esc_html__( 'Rows', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $summary as $row ) {
                echo '<tr><td>' . esc_html( (string) $row['label'] ) . '</td><td style="text-align:right;">' . (int) $row['total'] . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        // Stable-key conflict analysis.
        $conflicts = \TT\Modules\Backup\MigrationImporter::analyzeConflicts( $snapshot );
        echo '<h2 style="margin-top:24px;">' . esc_html__( 'What would happen on import', 'talenttrack' ) . '</h2>';
        echo '<p style="max-width:760px; color:#5b6e75;">' . esc_html__( 'Incoming records are matched against this install by a stable key (not by id, which differs between installs). A match would be an update-or-insert choice in the interactive step; the rest would be inserted as new records.', 'talenttrack' ) . '</p>';
        if ( empty( $conflicts ) ) {
            echo '<p><em>' . esc_html__( 'No record-level data sets in this archive to compare.', 'talenttrack' ) . '</em></p>';
        } else {
            echo '<table class="widefat striped" style="max-width:820px;">';
            echo '<thead><tr>'
                . '<th>' . esc_html__( 'Data set', 'talenttrack' ) . '</th>'
                . '<th style="text-align:right;">' . esc_html__( 'Incoming', 'talenttrack' ) . '</th>'
                . '<th style="text-align:right;">' . esc_html__( 'Match existing', 'talenttrack' ) . '</th>'
                . '<th style="text-align:right;">' . esc_html__( 'New', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Matched on', 'talenttrack' ) . '</th>'
                . '</tr></thead><tbody>';
            foreach ( $conflicts as $row ) {
                echo '<tr>'
                    . '<td>' . esc_html( (string) $row['label'] ) . '</td>'
                    . '<td style="text-align:right;">' . (int) $row['incoming'] . '</td>'
                    . '<td style="text-align:right;">' . (int) $row['conflicts'] . '</td>'
                    . '<td style="text-align:right;">' . (int) $row['new'] . '</td>'
                    . '<td><code>' . esc_html( (string) $row['key'] ) . '</code></td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }

        // #1464 phase 3-4 — configuration form: choose data sets, per-entity
        // conflict strategy and WordPress-user mapping, then run a dry run.
        self::renderMigrationImportConfigForm( $snapshot, $summary, $conflicts );

        echo '<p style="margin-top:16px;"><a class="button button-secondary" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'talenttrack' ) . '</a></p>';
        echo '</div>';
    }

    /**
     * #1464 phase 3-4 — the import configuration form. Lets the operator pick
     * which data sets to import, how to resolve stable-key matches
     * (update existing vs insert as new) and how to map referenced WordPress
     * users, then submit to the dry-run step. No writes happen here.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string, array{label:string, tables:array<string,int>, total:int}> $summary
     * @param array<string, array{label:string, incoming:int, conflicts:int, new:int, key:string}> $conflicts
     */
    private static function renderMigrationImportConfigForm( array $snapshot, array $summary, array $conflicts ): void {
        $groups      = \TT\Modules\Backup\MigrationExporter::entityGroups();
        $importable  = \TT\Modules\Backup\MigrationImporter::importableGroupKeys();
        $user_refs   = \TT\Modules\Backup\MigrationImporter::userReferences( $snapshot );

        echo '<h2 style="margin-top:24px;">' . esc_html__( 'Configure import', 'talenttrack' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tt_migration_import', 'tt_backup_nonce' );
        echo '<input type="hidden" name="action" value="tt_migration_import_dryrun" />';

        // Data-set selection (only importable record groups present in the file).
        echo '<h3>' . esc_html__( 'Data sets to import', 'talenttrack' ) . '</h3>';
        echo '<fieldset style="margin:6px 0 14px;">';
        $any = false;
        foreach ( $importable as $key ) {
            if ( ! isset( $summary[ $key ] ) ) continue;
            $any = true;
            $label = (string) ( $groups[ $key ]['label'] ?? $key );
            $count = (int) $summary[ $key ]['total'];
            echo '<label style="display:flex; align-items:center; gap:8px; min-height:40px;">'
                . '<input type="checkbox" name="entities[]" value="' . esc_attr( $key ) . '" checked style="width:18px; height:18px;" />'
                . '<span>' . esc_html( $label ) . ' '
                . '<span style="color:#6b7280;">(' . esc_html( (string) $count ) . ')</span></span>'
                . '</label>';
        }
        echo '</fieldset>';
        if ( ! $any ) {
            echo '<p><em>' . esc_html__( 'This archive has no importable record data sets. (Lookups & configuration are used to match references, not imported.)', 'talenttrack' ) . '</em></p>';
            echo '</form>';
            return;
        }

        // Conflict strategy for stable-keyed entities that actually match.
        $has_conflict = false;
        foreach ( $conflicts as $row ) {
            if ( (int) $row['conflicts'] > 0 ) { $has_conflict = true; break; }
        }
        if ( $has_conflict ) {
            echo '<h3>' . esc_html__( 'When a record already exists', 'talenttrack' ) . '</h3>';
            echo '<p style="max-width:760px; color:#5b6e75;">' . esc_html__( 'Some incoming records match an existing record on this install (by their stable key). Choose what to do for each — the default is to keep both by inserting a new record.', 'talenttrack' ) . '</p>';
            $entity_for_table = [];
            foreach ( \TT\Modules\Backup\MigrationImporter::stableKeys() as $ent => $spec ) {
                $entity_for_table[ $ent ] = (string) ( $groups[ $ent ]['label'] ?? $ent );
            }
            foreach ( $conflicts as $entity => $row ) {
                if ( (int) $row['conflicts'] <= 0 ) continue;
                $label = (string) $row['label'];
                echo '<div style="margin:8px 0;">';
                echo '<strong>' . esc_html( $label ) . '</strong> '
                    . '<span style="color:#6b7280;">' . esc_html( sprintf(
                        /* translators: %d is a count of matching records. */
                        _n( '%d matching record', '%d matching records', (int) $row['conflicts'], 'talenttrack' ),
                        (int) $row['conflicts']
                    ) ) . '</span>';
                echo '<div style="margin:4px 0 0;">';
                echo '<label style="display:inline-flex; align-items:center; gap:6px; margin-right:16px; min-height:36px;">'
                    . '<input type="radio" name="conflict[' . esc_attr( (string) $entity ) . ']" value="insert" checked /> '
                    . esc_html__( 'Insert as new', 'talenttrack' ) . '</label>';
                echo '<label style="display:inline-flex; align-items:center; gap:6px; min-height:36px;">'
                    . '<input type="radio" name="conflict[' . esc_attr( (string) $entity ) . ']" value="update" /> '
                    . esc_html__( 'Update the existing record', 'talenttrack' ) . '</label>';
                echo '</div></div>';
            }
        }

        // WordPress-user mapping for referenced source users.
        if ( ! empty( $user_refs ) ) {
            echo '<h3>' . esc_html__( 'Link WordPress users', 'talenttrack' ) . '</h3>';
            echo '<p style="max-width:760px; color:#5b6e75;">' . esc_html__( 'These records reference user accounts on the source install. Pick the matching user here, or leave unlinked. Suggestions are matched by email.', 'talenttrack' ) . '</p>';
            echo '<table class="widefat striped" style="max-width:760px;"><thead><tr>'
                . '<th>' . esc_html__( 'Source reference', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Map to user on this install', 'talenttrack' ) . '</th>'
                . '</tr></thead><tbody>';
            foreach ( $user_refs as $ref ) {
                $sid  = (int) $ref['source_id'];
                $hint = (string) $ref['hint'];
                $dropdown = wp_dropdown_users( [
                    'name'             => 'user_map[' . $sid . ']',
                    'selected'         => (int) $ref['suggested_user_id'],
                    'show_option_none' => __( '— Leave unlinked —', 'talenttrack' ),
                    'option_none_value' => 0,
                    'echo'             => 0,
                ] );
                echo '<tr><td>' . esc_html( $hint !== '' ? $hint : ( '#' . $sid ) ) . '</td>'
                    . '<td>' . $dropdown // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wp_dropdown_users returns escaped markup.
                    . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<p style="margin-top:16px;">';
        submit_button( __( 'Preview changes (dry run)', 'talenttrack' ), 'primary', 'submit', false );
        echo '</p>';
        echo '</form>';
    }

    /**
     * #1464 phase 3-4 — dry-run the import: reload the staged archive, run
     * the engine in no-write mode and render the per-table preview plus the
     * typed-confirmation commit form.
     */
    public static function handleMigrationImportDryRun(): void {
        self::guard( 'tt_migration_import' );

        $snapshot = self::loadStagedMigration();
        if ( $snapshot === null ) {
            self::renderMigrationStagingLost();
            exit;
        }
        $opts = self::readMigrationImportOpts();
        $opts['dry_run'] = true;
        $result = \TT\Modules\Backup\MigrationImporter::commit( $snapshot, $opts );
        self::renderMigrationImportResult( $result, $opts, false );
        exit;
    }

    /**
     * #1464 phase 3-4 — apply the import. Requires the operator to type
     * IMPORT; otherwise it falls back to the dry-run preview. On success the
     * staged archive is removed.
     */
    public static function handleMigrationImportCommit(): void {
        self::guard( 'tt_migration_import' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'migration.import' );

        $snapshot = self::loadStagedMigration();
        if ( $snapshot === null ) {
            self::renderMigrationStagingLost();
            exit;
        }
        $opts    = self::readMigrationImportOpts();
        $confirm = trim( (string) wp_unslash( $_POST['confirm_text'] ?? '' ) );
        if ( $confirm !== 'IMPORT' ) {
            $opts['dry_run'] = true;
            $result = \TT\Modules\Backup\MigrationImporter::commit( $snapshot, $opts );
            self::renderMigrationImportResult( $result, $opts, false, __( 'Type IMPORT to confirm before the data is written.', 'talenttrack' ) );
            exit;
        }
        $opts['dry_run'] = false;
        $result = \TT\Modules\Backup\MigrationImporter::commit( $snapshot, $opts );
        if ( ! empty( $result['ok'] ) ) {
            self::clearStagedMigration();
        }
        self::renderMigrationImportResult( $result, $opts, true );
        exit;
    }

    /**
     * Parse the import configuration from the posted form.
     *
     * @return array{entities:string[], conflict:array<string,string>, user_map:array<int,int>}
     */
    private static function readMigrationImportOpts(): array {
        $entities = isset( $_POST['entities'] ) && is_array( $_POST['entities'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['entities'] ) )
            : [];

        $conflict = [];
        if ( isset( $_POST['conflict'] ) && is_array( $_POST['conflict'] ) ) {
            foreach ( wp_unslash( $_POST['conflict'] ) as $k => $v ) {
                $conflict[ sanitize_key( (string) $k ) ] = (string) $v === 'update' ? 'update' : 'insert';
            }
        }

        $user_map = [];
        if ( isset( $_POST['user_map'] ) && is_array( $_POST['user_map'] ) ) {
            foreach ( wp_unslash( $_POST['user_map'] ) as $src => $tgt ) {
                $user_map[ (int) $src ] = (int) $tgt;
            }
        }

        return [ 'entities' => $entities, 'conflict' => $conflict, 'user_map' => $user_map ];
    }

    /**
     * Render the dry-run / commit result page.
     *
     * @param array{ok:bool, error?:string, dry_run?:bool, tables:array<string,array{insert:int,update:int,skip:int}>, warnings:string[]} $result
     * @param array{entities:string[], conflict:array<string,string>, user_map:array<int,int>} $opts
     */
    private static function renderMigrationImportResult( array $result, array $opts, bool $committed, string $notice = '' ): void {
        if ( ! function_exists( 'get_admin_page_title' ) ) require_once ABSPATH . 'wp-admin/includes/template.php';
        $back = admin_url( 'admin.php?page=tt-config&tab=backups' );

        echo '<div class="wrap">';
        echo '<h1>' . ( $committed ? esc_html__( 'Import complete', 'talenttrack' ) : esc_html__( 'Dry run — preview of changes', 'talenttrack' ) ) . '</h1>';

        if ( $notice !== '' ) {
            echo '<div class="notice notice-warning"><p>' . esc_html( $notice ) . '</p></div>';
        }

        if ( empty( $result['ok'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( (string) ( $result['error'] ?? __( 'The import could not be completed.', 'talenttrack' ) ) ) . '</p></div>';
            echo '<p><a class="button button-secondary" href="' . esc_url( $back ) . '">' . esc_html__( 'Back to backups', 'talenttrack' ) . '</a></p></div>';
            return;
        }

        if ( $committed ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'The selected data was imported. Source ids were not preserved — references were remapped to this install.', 'talenttrack' ) . '</p></div>';
        } else {
            echo '<p style="max-width:760px;">' . esc_html__( 'This is a preview — nothing has been written yet. Review the counts below, then confirm to apply.', 'talenttrack' ) . '</p>';
        }

        foreach ( (array) ( $result['warnings'] ?? [] ) as $w ) {
            echo '<div class="notice notice-warning"><p>' . esc_html( (string) $w ) . '</p></div>';
        }

        echo '<table class="widefat striped" style="max-width:620px;"><thead><tr>'
            . '<th>' . esc_html__( 'Table', 'talenttrack' ) . '</th>'
            . '<th style="text-align:right;">' . esc_html__( 'Insert', 'talenttrack' ) . '</th>'
            . '<th style="text-align:right;">' . esc_html__( 'Update', 'talenttrack' ) . '</th>'
            . '<th style="text-align:right;">' . esc_html__( 'Skip', 'talenttrack' ) . '</th>'
            . '</tr></thead><tbody>';
        foreach ( (array) ( $result['tables'] ?? [] ) as $tbl => $c ) {
            echo '<tr><td><code>' . esc_html( (string) $tbl ) . '</code></td>'
                . '<td style="text-align:right;">' . (int) ( $c['insert'] ?? 0 ) . '</td>'
                . '<td style="text-align:right;">' . (int) ( $c['update'] ?? 0 ) . '</td>'
                . '<td style="text-align:right;">' . (int) ( $c['skip'] ?? 0 ) . '</td></tr>';
        }
        echo '</tbody></table>';

        if ( $committed ) {
            echo '<p style="margin-top:16px;"><a class="button button-primary" href="' . esc_url( $back ) . '">' . esc_html__( 'Back to backups', 'talenttrack' ) . '</a></p></div>';
            return;
        }

        // Commit form — carry the configuration forward + typed confirmation.
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:18px;">';
        wp_nonce_field( 'tt_migration_import', 'tt_backup_nonce' );
        echo '<input type="hidden" name="action" value="tt_migration_import_commit" />';
        foreach ( (array) $opts['entities'] as $e ) {
            echo '<input type="hidden" name="entities[]" value="' . esc_attr( (string) $e ) . '" />';
        }
        foreach ( (array) $opts['conflict'] as $k => $v ) {
            echo '<input type="hidden" name="conflict[' . esc_attr( (string) $k ) . ']" value="' . esc_attr( (string) $v ) . '" />';
        }
        foreach ( (array) $opts['user_map'] as $s => $t ) {
            echo '<input type="hidden" name="user_map[' . esc_attr( (string) $s ) . ']" value="' . esc_attr( (string) $t ) . '" />';
        }
        echo '<p><strong>' . esc_html__( 'This writes the data above into this install. It cannot be undone automatically — take a backup first if unsure.', 'talenttrack' ) . '</strong></p>';
        echo '<label>' . esc_html__( 'Type IMPORT to confirm:', 'talenttrack' )
            . ' <input type="text" name="confirm_text" placeholder="IMPORT" class="regular-text" required /></label>';
        echo '<p style="margin-top:12px;">';
        submit_button( __( 'Import now', 'talenttrack' ), 'delete', 'submit', false );
        echo ' <a class="button button-secondary" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'talenttrack' ) . '</a>';
        echo '</p></form></div>';
    }

    /** Render the "staged archive missing" recovery message. */
    private static function renderMigrationStagingLost(): void {
        if ( ! function_exists( 'get_admin_page_title' ) ) require_once ABSPATH . 'wp-admin/includes/template.php';
        $back = admin_url( 'admin.php?page=tt-config&tab=backups' );
        echo '<div class="wrap"><h1>' . esc_html__( 'Import preview', 'talenttrack' ) . '</h1>';
        echo '<div class="notice notice-error"><p>' . esc_html__( 'The uploaded migration archive could not be found. Please upload it again.', 'talenttrack' ) . '</p></div>';
        echo '<p><a class="button button-secondary" href="' . esc_url( $back ) . '">' . esc_html__( 'Back to backups', 'talenttrack' ) . '</a></p></div>';
    }

    // #1464 — migration upload staging (one slot per operator). Stored in the
    // system temp dir: always writable across requests (uploads can be
    // restrictive) and outside the webroot, so the player data it carries is
    // never directly fetchable.

    private static function stagedMigrationFile(): string {
        $dir = get_temp_dir();
        if ( $dir === '' ) return '';
        return trailingslashit( $dir ) . 'tt-migration-stage-' . get_current_user_id() . '.ttmig';
    }

    private static function stageMigrationUpload( string $bytes ): void {
        $path = self::stagedMigrationFile();
        if ( $path !== '' ) @file_put_contents( $path, $bytes );
    }

    /** @return array<string,mixed>|null */
    private static function loadStagedMigration(): ?array {
        $path = self::stagedMigrationFile();
        if ( $path === '' || ! is_readable( $path ) ) return null;
        $bytes = (string) file_get_contents( $path );
        return \TT\Modules\Backup\BackupSerializer::fromGzippedJson( $bytes );
    }

    private static function clearStagedMigration(): void {
        $path = self::stagedMigrationFile();
        if ( $path !== '' && file_exists( $path ) ) @unlink( $path );
    }

    public static function handleRestore(): void {
        self::guard( 'tt_backup_restore' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'backup.restore' );
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

    /**
     * Bulk-undo handler — pulls the pending payload from BulkSafetyHook
     * and runs a partial restore on the affected rows only. The
     * transient is consumed after a successful restore so the notice
     * disappears.
     */
    public static function handleBulkUndo(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_backup_bulk_undo', 'tt_backup_undo_nonce' );
        // #0080 Wave A — gate direct-URL bypass on free tier.
        if ( ! \TT\Modules\License\LicenseGate::allows( 'undo_bulk' ) ) {
            self::redirectBack( [ 'tt_bk_msg' => 'license_undo_bulk' ] );
        }
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'backup.bulk_undo' );

        $user_id = get_current_user_id();
        $payload = \TT\Modules\Backup\BulkSafetyHook::peekPending( $user_id );
        if ( ! $payload ) {
            self::redirectBack( [ 'tt_bk_msg' => 'undo_missing' ] );
        }

        $local = new LocalDestination();
        $path  = $local->fetchLocalPath( (string) ( $payload['backup_id'] ?? '' ) );
        if ( $path === '' ) {
            self::redirectBack( [ 'tt_bk_msg' => 'undo_missing' ] );
        }

        $bytes    = (string) file_get_contents( $path );
        $snapshot = \TT\Modules\Backup\BackupSerializer::fromGzippedJson( $bytes );
        if ( ! is_array( $snapshot ) || ! isset( $snapshot['tables'] ) ) {
            self::redirectBack( [ 'tt_bk_msg' => 'undo_failed' ] );
        }

        // Resolve the closure: just the affected ids in the entity's
        // table. (No down-walk for v1 — undo is targeted and small.)
        $entity_to_table = self::entityToTable();
        $bare = $entity_to_table[ (string) ( $payload['entity'] ?? '' ) ] ?? '';
        if ( $bare === '' ) {
            self::redirectBack( [ 'tt_bk_msg' => 'undo_failed' ] );
        }
        $closure = [ $bare => array_map( 'intval', (array) ( $payload['ids'] ?? [] ) ) ];

        // Restore = green → restore, yellow → overwrite. The whole
        // point of "undo" is to bring the rows back to their pre-bulk
        // state.
        $actions = [
            $bare => [
                'green'  => \TT\Modules\Backup\PartialRestorer::ACTION_RESTORE,
                'yellow' => \TT\Modules\Backup\PartialRestorer::ACTION_OVERWRITE,
            ],
        ];
        $result = \TT\Modules\Backup\PartialRestorer::execute( $snapshot, $closure, $actions );

        if ( ! empty( $result['ok'] ) ) {
            \TT\Modules\Backup\BulkSafetyHook::popPending( $user_id );
        }

        self::redirectBack( [ 'tt_bk_msg' => $result['ok'] ? 'undone' : 'undo_failed' ] );
    }

    public static function handleBulkUndoDismiss(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_backup_bulk_undo_dismiss', 'tt_backup_undo_nonce' );
        \TT\Modules\Backup\BulkSafetyHook::popPending( get_current_user_id() );
        $back = wp_get_referer() ?: admin_url( 'admin.php?page=talenttrack' );
        wp_safe_redirect( $back );
        exit;
    }

    /**
     * Map BulkActionsHelper entity slugs to bare tt_* table names.
     *
     * @return array<string,string>
     */
    private static function entityToTable(): array {
        return [
            'player'     => 'tt_players',
            'team'       => 'tt_teams',
            'evaluation' => 'tt_evaluations',
            'activity'    => 'tt_activities',
            'goal'       => 'tt_goals',
            'person'     => 'tt_people',
        ];
    }

    // Helpers

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

    // Partial restore

    /**
     * Render the partial-restore picker + diff view.
     *
     * Two-step flow stuffed into one page:
     *   1. No `?scope_table` yet → render the scope picker (entity table
     *      + optional id list).
     *   2. Scope picked → compute closure + diff, show counts, render
     *      the per-table action form, submit to handlePartialExecute.
     */
    private static function renderPartialRestore( string $backup_id ): void {
        // #0080 Wave A — partial restore is a Standard+ feature. Free
        // tier sees the upgrade nudge inline at the top of the page
        // instead of the picker; the link in the backups list stays
        // visible across all tiers so the upgrade path is discoverable.
        if ( ! \TT\Modules\License\LicenseGate::allows( 'partial_restore' ) ) {
            echo \TT\Modules\License\Admin\UpgradeNudge::inline( __( 'Partial restore', 'talenttrack' ), 'standard' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — UpgradeNudge returns escaped HTML
            return;
        }

        $local = new LocalDestination();
        $path  = $local->fetchLocalPath( $backup_id );
        if ( $path === '' ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Backup not found.', 'talenttrack' ) . '</p></div>';
            return;
        }
        $bytes    = (string) file_get_contents( $path );
        $snapshot = \TT\Modules\Backup\BackupSerializer::fromGzippedJson( $bytes );
        if ( ! is_array( $snapshot ) || ! isset( $snapshot['tables'] ) || ! is_array( $snapshot['tables'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Backup file is not valid.', 'talenttrack' ) . '</p></div>';
            return;
        }

        $tables       = $snapshot['tables'];
        $scope_table  = isset( $_GET['scope_table'] ) ? sanitize_key( (string) $_GET['scope_table'] ) : '';
        $scope_id_raw = isset( $_GET['scope_ids'] )   ? (string) wp_unslash( (string) $_GET['scope_ids'] ) : '';
        $include_kids = isset( $_GET['include_children'] ) && is_array( $_GET['include_children'] )
            ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['include_children'] ) )
            : [];

        ?>
        <h2><?php esc_html_e( 'Partial restore', 'talenttrack' ); ?></h2>
        <p style="max-width:760px;">
            <?php esc_html_e( 'Pick a scope from this backup. We resolve the dependency closure (parent rows the scope needs to be consistent) and show a per-table diff against the current database. Only the rows in scope are touched — other rows stay as they are.', 'talenttrack' ); ?>
        </p>
        <p>
            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=tt-config&tab=backups' ) ); ?>">
                ← <?php esc_html_e( 'Back to backups', 'talenttrack' ); ?>
            </a>
        </p>

        <?php
        // Step 1 — scope picker
        ?>
        <h3><?php esc_html_e( '1. Choose scope', 'talenttrack' ); ?></h3>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:16px; max-width:760px;">
            <input type="hidden" name="page"    value="tt-config" />
            <input type="hidden" name="tab"     value="backups" />
            <input type="hidden" name="partial" value="<?php echo esc_attr( $backup_id ); ?>" />
            <table class="form-table">
                <tr>
                    <th><label for="tt_scope_table"><?php esc_html_e( 'Entity table', 'talenttrack' ); ?></label></th>
                    <td>
                        <select id="tt_scope_table" name="scope_table">
                            <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                            <?php foreach ( array_keys( $tables ) as $tbl ) : ?>
                                <option value="<?php echo esc_attr( (string) $tbl ); ?>" <?php selected( $scope_table, $tbl ); ?>>
                                    <?php echo esc_html( (string) $tbl ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="tt_scope_ids"><?php esc_html_e( 'Row IDs', 'talenttrack' ); ?></label></th>
                    <td>
                        <input type="text" id="tt_scope_ids" name="scope_ids" class="regular-text" value="<?php echo esc_attr( $scope_id_raw ); ?>" />
                        <p class="description"><?php esc_html_e( 'Comma-separated. Leave empty to include every row of this table from the backup.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Include children', 'talenttrack' ); ?></th>
                    <td>
                        <?php foreach ( array_keys( \TT\Modules\Backup\BackupDependencyMap::inverse() ) as $parent ) : ?>
                            <?php if ( $parent !== $scope_table ) continue; ?>
                            <?php foreach ( ( \TT\Modules\Backup\BackupDependencyMap::inverse()[ $parent ] ?? [] ) as $child => $cols ) : ?>
                                <label style="display:block; margin-bottom:4px;">
                                    <input type="checkbox" name="include_children[]" value="<?php echo esc_attr( $child ); ?>" <?php checked( in_array( $child, $include_kids, true ) ); ?> />
                                    <code><?php echo esc_html( $child ); ?></code>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e( 'Tick the child tables to follow downward from the chosen scope.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Compute diff', 'talenttrack' ); ?></button>
            </p>
        </form>
        <?php

        // Step 2 — diff + execute
        if ( $scope_table === '' || ! isset( $tables[ $scope_table ] ) ) return;

        $scope_ids = self::parseIdList( $scope_id_raw );
        if ( empty( $scope_ids ) ) {
            // No ids picked → take every row of the chosen table.
            foreach ( $tables[ $scope_table ]['rows'] ?? [] as $r ) {
                if ( is_array( $r ) && isset( $r['id'] ) ) $scope_ids[] = (int) $r['id'];
            }
        }
        if ( empty( $scope_ids ) ) {
            echo '<p><em>' . esc_html__( 'No rows in this table.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $closure = \TT\Modules\Backup\PartialRestoreScope::compute(
            $tables,
            [ $scope_table => $scope_ids ],
            $include_kids
        );
        $diff = \TT\Modules\Backup\DiffComputer::compute( $tables, $closure );

        ?>
        <h3 style="margin-top:32px;"><?php esc_html_e( '2. Review diff and choose actions', 'talenttrack' ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:16px; max-width:980px;">
            <?php wp_nonce_field( 'tt_backup_partial_execute', 'tt_backup_nonce' ); ?>
            <input type="hidden" name="action"      value="tt_backup_partial_execute" />
            <input type="hidden" name="backup_id"   value="<?php echo esc_attr( $backup_id ); ?>" />
            <input type="hidden" name="closure"     value="<?php echo esc_attr( (string) wp_json_encode( $closure ) ); ?>" />
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Table', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'In scope', 'talenttrack' ); ?></th>
                    <th><span style="color:#1d7874;">●</span> <?php esc_html_e( 'New (green)', 'talenttrack' ); ?></th>
                    <th><span style="color:#c9962a;">●</span> <?php esc_html_e( 'Differ (yellow)', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Action for green', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Action for yellow', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $closure as $tbl => $ids ) :
                    $green  = (int) ( $diff[ $tbl ]['green']  ?? 0 );
                    $yellow = (int) ( $diff[ $tbl ]['yellow'] ?? 0 );
                    ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $tbl ); ?></code></td>
                        <td><?php echo (int) count( $ids ); ?></td>
                        <td><?php echo $green; ?></td>
                        <td><?php echo $yellow; ?></td>
                        <td>
                            <select name="actions[<?php echo esc_attr( $tbl ); ?>][green]">
                                <option value="restore"><?php esc_html_e( 'Restore', 'talenttrack' ); ?></option>
                                <option value="skip"><?php esc_html_e( 'Skip',    'talenttrack' ); ?></option>
                            </select>
                        </td>
                        <td>
                            <select name="actions[<?php echo esc_attr( $tbl ); ?>][yellow]">
                                <option value="keep-current"><?php esc_html_e( 'Keep current', 'talenttrack' ); ?></option>
                                <option value="overwrite"><?php esc_html_e( 'Overwrite with backup', 'talenttrack' ); ?></option>
                                <option value="skip"><?php esc_html_e( 'Skip', 'talenttrack' ); ?></option>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:14px;">
                <label>
                    <input type="checkbox" name="dry_run" value="1" />
                    <?php esc_html_e( 'Dry run (compute changes without writing)', 'talenttrack' ); ?>
                </label>
            </p>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Execute partial restore', 'talenttrack' ); ?></button>
            </p>
        </form>
        <?php
    }

    public static function handlePartialExecute(): void {
        self::guard( 'tt_backup_partial_execute' );
        // #0080 Wave A — direct-URL POST bypass on free tier returns to the page.
        if ( ! \TT\Modules\License\LicenseGate::allows( 'partial_restore' ) ) {
            self::redirectBack( [ 'tt_bk_msg' => 'license_partial_restore' ] );
        }

        $backup_id = sanitize_text_field( wp_unslash( (string) ( $_POST['backup_id'] ?? '' ) ) );
        $closure   = json_decode( (string) wp_unslash( $_POST['closure'] ?? '' ), true );
        $actions   = isset( $_POST['actions'] ) && is_array( $_POST['actions'] ) ? wp_unslash( $_POST['actions'] ) : [];
        $dry_run   = ! empty( $_POST['dry_run'] );

        if ( ! is_array( $closure ) ) {
            self::redirectBack( [ 'tt_bk_msg' => 'partial_failed' ] );
        }

        $local = new LocalDestination();
        $path  = $local->fetchLocalPath( $backup_id );
        if ( $path === '' ) {
            self::redirectBack( [ 'tt_bk_msg' => 'restore_missing' ] );
        }
        $bytes    = (string) file_get_contents( $path );
        $snapshot = \TT\Modules\Backup\BackupSerializer::fromGzippedJson( $bytes );
        if ( ! is_array( $snapshot ) ) {
            self::redirectBack( [ 'tt_bk_msg' => 'partial_failed' ] );
        }

        if ( $dry_run ) {
            // Dry run = just compute the diff again and surface counts
            // in a transient so the redirect target can render them.
            $diff = \TT\Modules\Backup\DiffComputer::compute( $snapshot['tables'] ?? [], $closure );
            set_transient( 'tt_partial_dry_run_' . get_current_user_id(), [
                'backup_id' => $backup_id,
                'diff'      => $diff,
            ], 5 * MINUTE_IN_SECONDS );
            self::redirectBack( [ 'tt_bk_msg' => 'partial_dry_run' ] );
        }

        $result = \TT\Modules\Backup\PartialRestorer::execute( $snapshot, $closure, $actions );
        self::redirectBack( [ 'tt_bk_msg' => $result['ok'] ? 'partial_done' : 'partial_failed' ] );
    }

    /** @return int[] */
    private static function parseIdList( string $raw ): array {
        $parts = preg_split( '/[\s,;]+/', $raw ) ?: [];
        $out   = [];
        foreach ( $parts as $p ) {
            $p = (int) $p;
            if ( $p > 0 ) $out[] = $p;
        }
        return array_values( array_unique( $out ) );
    }
}
