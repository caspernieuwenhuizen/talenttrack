<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Backup\BackupRunner;
use TT\Modules\Backup\BackupSettings;
use TT\Modules\Backup\Destinations\LocalDestination;
use TT\Modules\Backup\MigrationExporter;
use TT\Modules\Backup\MigrationImporter;
use TT\Modules\Backup\PresetRegistry;
use TT\Modules\Backup\Scheduler;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendBackupsView — frontend port of the wp-admin Backups page
 * (Configuration → Backups, BackupSettingsPage), #1937, child of #1533.
 * Reachable at `?tt_view=backups`.
 *
 * Answers the academy question "Is our academy's data backed up, and can
 * we recover it?" — every player, team, evaluation and PDP row lives in
 * the tt_* tables this surface snapshots and restores, so a working
 * backup is the operational safety net for the whole player record.
 *
 * Sections, all without a wp-admin bounce:
 *   - Settings: preset / custom tables / schedule / retention / local +
 *     email destinations (Save + Cancel).
 *   - Run now: trigger an on-demand backup.
 *   - Stored backups: download (URL), full restore (typed-confirm
 *     RESTORE), partial restore (links to the wp-admin power-user path —
 *     see PR notes), delete.
 *   - Data migration: export selected data sets to a .ttmig file; import
 *     a .ttmig (upload → preview → dry-run → typed-confirm IMPORT commit).
 *
 * The view only COMPOSES data; the serializer, restore engine, migration
 * engine and scheduler all live in the Backup module services, driven via
 * BackupRestController. The two destructive writes (restore + import
 * commit) preserve the wp-admin typed-confirmation gate, refuse to run
 * while impersonating, and are audit-logged — see BackupRestController.
 *
 * Capability: `tt_manage_backups` (matches BackupSettingsPage::CAP) on
 * both the view and every REST route — matrix cap, never a role string.
 */
class FrontendBackupsView extends FrontendViewBase {

    private const CAP = 'tt_manage_backups';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::CAP ) ) {
            self::breadcrumb();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueViewAssets();
        self::breadcrumb();
        self::renderHeader( __( 'Backups', 'talenttrack' ) );

        $settings = BackupSettings::get();
        $last_run = BackupRunner::lastRun();
        $next_ts  = function_exists( 'wp_next_scheduled' ) ? (int) wp_next_scheduled( Scheduler::HOOK ) : 0;
        $local    = new LocalDestination();
        $list     = $local->listBackups();

        // Cancel target for the settings form: the Configuration view this
        // tile lives under. tt_back overrides this when present.
        $cancel_url = add_query_arg(
            [ 'tt_view' => 'configuration' ],
            remove_query_arg( [ 'tt_view' ] )
        );
        ?>
        <div class="tt-backups" data-tt-backups>
            <p class="tt-backups__intro">
                <?php esc_html_e( 'Schedule snapshots of your TalentTrack data and restore them when needed. Backups cover the plugin\'s own tables only — not WordPress users or media uploads.', 'talenttrack' ); ?>
            </p>

            <div class="tt-backups__msg" data-tt-backups-msg role="status" aria-live="polite"></div>

            <?php
        // A schedule other than "on demand" means automatic runs are
        // expected — used only to nudge "save once to schedule the cron".
        $scheduled = ( (string) ( $settings['schedule'] ?? '' ) ) !== 'on_demand';
        ?>
        <?php self::renderStatus( $last_run, $next_ts, $scheduled ); ?>

            <?php self::renderSettingsForm( $settings, $cancel_url ); ?>

            <section class="tt-backups__section">
                <h2><?php esc_html_e( 'Run a backup now', 'talenttrack' ); ?></h2>
                <p class="tt-backups__hint">
                    <?php esc_html_e( 'Triggers a backup with the current settings without waiting for the scheduled run. Useful for testing, before risky operations, or on low-traffic sites where WP-cron does not fire reliably.', 'talenttrack' ); ?>
                </p>
                <button type="button" class="tt-btn tt-btn-primary" data-tt-backups-run>
                    <?php esc_html_e( 'Run backup now', 'talenttrack' ); ?>
                </button>
            </section>

            <?php self::renderBackupsList( $list ); ?>

            <?php self::renderMigrationSection(); ?>
        </div>
        <?php
    }

    private static function breadcrumb(): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Backups', 'talenttrack' ) );
    }

    /**
     * @param array<string,mixed>|null $last_run
     */
    private static function renderStatus( ?array $last_run, int $next_ts, bool $enabled ): void {
        if ( ! $last_run && $next_ts <= 0 && ! $enabled ) {
            return;
        }
        ?>
        <p class="tt-backups__status">
            <?php if ( $last_run ) : ?>
                <strong><?php esc_html_e( 'Last run:', 'talenttrack' ); ?></strong>
                <?php
                if ( ! empty( $last_run['ok'] ) ) {
                    printf(
                        /* translators: %s is human-readable time-since. */
                        esc_html__( '%s ago — success.', 'talenttrack' ),
                        esc_html( human_time_diff( (int) $last_run['at'], time() ) )
                    );
                } else {
                    printf(
                        /* translators: 1: time-since, 2: error message. */
                        esc_html__( '%1$s ago — failed (%2$s).', 'talenttrack' ),
                        esc_html( human_time_diff( (int) $last_run['at'], time() ) ),
                        esc_html( (string) ( $last_run['error'] ?? '' ) )
                    );
                }
                ?>
            <?php endif; ?>
            <?php if ( $next_ts > 0 ) : ?>
                <?php if ( $last_run ) echo ' · '; ?>
                <strong><?php esc_html_e( 'Next run:', 'talenttrack' ); ?></strong>
                <?php
                if ( $next_ts <= time() ) {
                    esc_html_e( 'overdue (will fire on the next WP-cron tick)', 'talenttrack' );
                } else {
                    printf(
                        /* translators: %s is human-readable time-until. */
                        esc_html__( 'in about %s', 'talenttrack' ),
                        esc_html( human_time_diff( time(), $next_ts ) )
                    );
                }
                ?>
            <?php elseif ( $enabled ) : ?>
                · <em><?php esc_html_e( 'Next run is not scheduled yet — save settings once to schedule the cron event.', 'talenttrack' ); ?></em>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function renderSettingsForm( array $settings, string $cancel_url ): void {
        $preset        = (string) ( $settings['preset'] ?? '' );
        $schedule      = (string) ( $settings['schedule'] ?? 'daily' );
        $retention     = (int) ( $settings['retention'] ?? 30 );
        $custom_tables = is_array( $settings['selected_tables'] ?? null ) ? $settings['selected_tables'] : [];
        $recipients    = (array) ( $settings['destinations']['email']['recipients'] ?? [] );
        $local_on      = ! empty( $settings['destinations']['local']['enabled'] );
        $email_on      = ! empty( $settings['destinations']['email']['enabled'] );
        $custom_join   = implode( "\n", $custom_tables );
        $rec_join      = implode( ', ', $recipients );

        $descriptions = [];
        foreach ( PresetRegistry::all() as $p ) {
            $descriptions[ $p ] = PresetRegistry::description( $p );
        }
        $dir = LocalDestination::dir();
        ?>
        <section class="tt-backups__section">
            <h2><?php esc_html_e( 'Settings', 'talenttrack' ); ?></h2>
            <form data-tt-backups-settings-form>
                <div class="tt-backups__field">
                    <label class="tt-backups__legend" for="tt-bk-preset"><?php esc_html_e( 'Preset', 'talenttrack' ); ?></label>
                    <select id="tt-bk-preset" class="tt-backups__input" name="preset" data-tt-backups-preset
                        data-descriptions="<?php echo esc_attr( (string) wp_json_encode( $descriptions ) ); ?>">
                        <?php foreach ( PresetRegistry::all() as $p ) : ?>
                            <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $preset, $p ); ?>>
                                <?php echo esc_html( PresetRegistry::label( $p ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="tt-backups__hint" data-tt-backups-preset-desc>
                        <?php echo esc_html( PresetRegistry::description( $preset ) ); ?>
                    </p>
                </div>

                <div class="tt-backups__field">
                    <label class="tt-backups__legend" for="tt-bk-tables"><?php esc_html_e( 'Custom tables', 'talenttrack' ); ?></label>
                    <textarea id="tt-bk-tables" class="tt-backups__input tt-backups__textarea" name="selected_tables" rows="5"
                        placeholder="tt_players&#10;tt_teams"><?php echo esc_textarea( $custom_join ); ?></textarea>
                    <p class="tt-backups__hint"><?php esc_html_e( 'Only used when preset is "Custom". One table per line, including the tt_ prefix but not the WordPress prefix.', 'talenttrack' ); ?></p>
                </div>

                <div class="tt-backups__field">
                    <label class="tt-backups__legend" for="tt-bk-schedule"><?php esc_html_e( 'Schedule', 'talenttrack' ); ?></label>
                    <select id="tt-bk-schedule" class="tt-backups__input" name="schedule">
                        <option value="daily"     <?php selected( $schedule, 'daily' ); ?>><?php esc_html_e( 'Daily', 'talenttrack' ); ?></option>
                        <option value="weekly"    <?php selected( $schedule, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'talenttrack' ); ?></option>
                        <option value="on_demand" <?php selected( $schedule, 'on_demand' ); ?>><?php esc_html_e( 'On demand', 'talenttrack' ); ?></option>
                    </select>
                </div>

                <div class="tt-backups__field">
                    <label class="tt-backups__legend" for="tt-bk-retention"><?php esc_html_e( 'Retention', 'talenttrack' ); ?></label>
                    <input type="number" inputmode="numeric" id="tt-bk-retention" class="tt-backups__input tt-backups__input--narrow"
                        name="retention" min="1" max="365" value="<?php echo esc_attr( (string) $retention ); ?>" />
                    <p class="tt-backups__hint"><?php esc_html_e( 'Keep this many local backups before purging the oldest.', 'talenttrack' ); ?></p>
                </div>

                <div class="tt-backups__field">
                    <span class="tt-backups__legend"><?php esc_html_e( 'Local destination', 'talenttrack' ); ?></span>
                    <label class="tt-backups__check">
                        <input type="checkbox" name="dest_local" value="1" <?php checked( $local_on ); ?> />
                        <span>
                            <?php
                            echo $dir !== ''
                                ? esc_html( sprintf( __( 'Save backups to %s', 'talenttrack' ), $dir ) )
                                : esc_html__( 'Save backups to wp-content/uploads/talenttrack-backups/', 'talenttrack' );
                            ?>
                        </span>
                    </label>
                </div>

                <div class="tt-backups__field">
                    <span class="tt-backups__legend"><?php esc_html_e( 'Email destination', 'talenttrack' ); ?></span>
                    <label class="tt-backups__check">
                        <input type="checkbox" name="dest_email" value="1" <?php checked( $email_on ); ?> />
                        <span><?php esc_html_e( 'Email each backup to the recipients below', 'talenttrack' ); ?></span>
                    </label>
                    <input type="text" class="tt-backups__input" name="email_recipients"
                        value="<?php echo esc_attr( $rec_join ); ?>" autocomplete="off"
                        placeholder="name@example.com, other@example.com" />
                    <p class="tt-backups__hint"><?php esc_html_e( 'Comma-separated list. Files larger than 10 MB will not be attached — recipients receive a notice instead.', 'talenttrack' ); ?></p>
                </div>

                <?php echo FormSaveButton::render( [
                    'label'        => __( 'Save backup settings', 'talenttrack' ),
                    'label_saving' => __( 'Saving…', 'talenttrack' ),
                    'label_saved'  => __( 'Saved', 'talenttrack' ),
                    'cancel_url'   => $cancel_url,
                    'cancel_label' => __( 'Cancel', 'talenttrack' ),
                ] ); ?>
            </form>
        </section>
        <?php
    }

    /** @param array<int,array<string,mixed>> $list */
    private static function renderBackupsList( array $list ): void {
        ?>
        <section class="tt-backups__section">
            <h2><?php esc_html_e( 'Stored backups (local)', 'talenttrack' ); ?></h2>
            <?php if ( empty( $list ) ) : ?>
                <p class="tt-backups__empty"><em><?php esc_html_e( 'No local backups yet.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <table class="tt-backups__table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Filename', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Created', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Preset', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Size', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $list as $row ) :
                        $id   = (string) ( $row['id'] ?? '' );
                        if ( $id === '' ) continue;
                        $size = (int) ( $row['size'] ?? 0 );
                        $when = (string) ( $row['created_at'] ?? '' );
                        $prst = (string) ( $row['preset'] ?? '' );
                        $dl   = self::downloadUrl( $id );
                        $part = self::partialRestoreUrl( $id );
                        ?>
                        <tr data-backup-id="<?php echo esc_attr( $id ); ?>">
                            <td data-label="<?php echo esc_attr__( 'Filename', 'talenttrack' ); ?>"><code><?php echo esc_html( $id ); ?></code></td>
                            <td data-label="<?php echo esc_attr__( 'Created', 'talenttrack' ); ?>"><?php echo esc_html( $when ); ?></td>
                            <td data-label="<?php echo esc_attr__( 'Preset', 'talenttrack' ); ?>"><?php echo esc_html( $prst ); ?></td>
                            <td data-label="<?php echo esc_attr__( 'Size', 'talenttrack' ); ?>"><?php echo esc_html( size_format( $size ) ); ?></td>
                            <td data-label="<?php echo esc_attr__( 'Actions', 'talenttrack' ); ?>" class="tt-backups__row-actions">
                                <a class="tt-btn tt-btn-secondary tt-btn--sm" href="<?php echo esc_url( $dl ); ?>">
                                    <?php esc_html_e( 'Download', 'talenttrack' ); ?>
                                </a>
                                <button type="button" class="tt-btn tt-btn-secondary tt-btn--sm" data-tt-backups-restore data-id="<?php echo esc_attr( $id ); ?>">
                                    <?php esc_html_e( 'Restore', 'talenttrack' ); ?>
                                </button>
                                <a class="tt-btn tt-btn-secondary tt-btn--sm" href="<?php echo esc_url( $part ); ?>">
                                    <?php esc_html_e( 'Partial restore', 'talenttrack' ); ?>
                                </a>
                                <button type="button" class="tt-btn tt-btn-danger tt-btn--sm" data-tt-backups-delete data-id="<?php echo esc_attr( $id ); ?>">
                                    <?php esc_html_e( 'Delete', 'talenttrack' ); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Restore preview + typed-confirm panel, populated by JS. -->
            <div class="tt-backups__restore-panel" data-tt-backups-restore-panel hidden></div>
        </section>
        <?php
    }

    private static function renderMigrationSection(): void {
        $groups = MigrationExporter::entityGroups();
        $max    = size_format( MigrationImporter::MAX_UPLOAD_BYTES );
        ?>
        <section class="tt-backups__section tt-backups__section--migration">
            <h2><?php esc_html_e( 'Data migration', 'talenttrack' ); ?></h2>
            <p class="tt-backups__hint">
                <?php esc_html_e( 'Move data to another TalentTrack install. Choose which data sets to include; you get a .ttmig file to import on the other install. Data only — WordPress users and media are not included.', 'talenttrack' ); ?>
            </p>

            <!-- Export — streams a .ttmig download via admin-post; this is a
                 standard GET-style form submit so the browser handles the
                 binary download. Not wizard-bound (export is a single step). -->
            <h3><?php esc_html_e( 'Export for migration', 'talenttrack' ); ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-backups__export-form">
                <?php wp_nonce_field( 'tt_migration_export', 'tt_backup_nonce' ); ?>
                <input type="hidden" name="action" value="tt_migration_export" />
                <fieldset class="tt-backups__entities">
                    <?php foreach ( $groups as $key => $g ) : ?>
                        <label class="tt-backups__check">
                            <input type="checkbox" name="entities[]" value="<?php echo esc_attr( $key ); ?>" checked />
                            <span><?php echo esc_html( (string) $g['label'] ); ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <button type="submit" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Export for migration', 'talenttrack' ); ?></button>
            </form>

            <!-- Import — upload → preview → dry-run → typed-confirm commit,
                 all REST-driven below. See the Wizard plan exemption in the
                 PR: the bespoke preview/dry-run/commit chrome doesn't fit
                 the record-creation wizard step model. -->
            <h3><?php esc_html_e( 'Import from another install', 'talenttrack' ); ?></h3>
            <p class="tt-backups__hint">
                <?php esc_html_e( 'Upload a .ttmig file exported from another TalentTrack install. The preview only inspects the archive — nothing is written until you dry-run and confirm the import.', 'talenttrack' ); ?>
            </p>
            <form data-tt-backups-import-form>
                <div class="tt-backups__field">
                    <label class="tt-backups__legend" for="tt-bk-mig-file"><?php esc_html_e( 'Migration archive (.ttmig)', 'talenttrack' ); ?></label>
                    <input type="file" id="tt-bk-mig-file" class="tt-backups__input" name="migration_file" accept=".ttmig,application/gzip" required />
                    <p class="tt-backups__hint">
                        <?php
                        /* translators: %s is a human-readable file size, e.g. "25 MB". */
                        echo esc_html( sprintf( __( 'Maximum %s. Larger datasets are a later phase.', 'talenttrack' ), $max ) );
                        ?>
                    </p>
                </div>
                <button type="submit" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Preview import', 'talenttrack' ); ?></button>
            </form>

            <!-- Import preview / dry-run / commit, populated by JS. -->
            <div class="tt-backups__import-panel" data-tt-backups-import-panel hidden></div>
        </section>
        <?php
    }

    private static function downloadUrl( string $id ): string {
        return wp_nonce_url(
            add_query_arg(
                [ 'action' => 'tt_backup_download', 'id' => $id ],
                admin_url( 'admin-post.php' )
            ),
            'tt_backup_download',
            'tt_backup_nonce'
        );
    }

    /**
     * Partial restore stays on the wp-admin power-user path for now: it is
     * a Standard+ licensed two-step scope-picker + per-table diff flow that
     * does not fit the flat list/restore surface ported here. See PR notes.
     */
    private static function partialRestoreUrl( string $id ): string {
        return admin_url( 'admin.php?page=tt-config&tab=backups&partial=' . rawurlencode( $id ) );
    }

    private static function enqueueViewAssets(): void {
        wp_enqueue_style(
            'tt-frontend-backups',
            TT_PLUGIN_URL . 'assets/css/frontend-backups.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-backups',
            TT_PLUGIN_URL . 'assets/js/frontend-backups.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-frontend-backups',
            'TT_Backups',
            [
                'i18n' => [
                    'settings_saved'   => __( 'Backup settings saved.', 'talenttrack' ),
                    'running'          => __( 'Running backup…', 'talenttrack' ),
                    'run_ok'           => __( 'Backup created. Reload to see it in the list.', 'talenttrack' ),
                    'deleted'          => __( 'Backup deleted.', 'talenttrack' ),
                    'delete_confirm'   => __( 'Delete this backup file? This cannot be undone.', 'talenttrack' ),
                    'restoring'        => __( 'Restoring…', 'talenttrack' ),
                    'restored'         => __( 'Restore complete. Reload the page to see the restored data.', 'talenttrack' ),
                    'restore_intro'    => __( 'This action will replace the current data with the contents of the backup.', 'talenttrack' ),
                    'restore_meta'     => __( 'Snapshot created %1$s on plugin version %2$s.', 'talenttrack' ),
                    'restore_type'     => __( 'Type RESTORE to confirm:', 'talenttrack' ),
                    'restore_confirm'  => __( 'Restore from this backup', 'talenttrack' ),
                    'table'            => __( 'Table', 'talenttrack' ),
                    'rows'             => __( 'Rows', 'talenttrack' ),
                    'cancel'           => __( 'Cancel', 'talenttrack' ),
                    'previewing'       => __( 'Reading archive…', 'talenttrack' ),
                    'import_contents'  => __( 'Contents', 'talenttrack' ),
                    'import_dataset'   => __( 'Data set', 'talenttrack' ),
                    'import_would'     => __( 'What would happen on import', 'talenttrack' ),
                    'import_incoming'  => __( 'Incoming', 'talenttrack' ),
                    'import_match'     => __( 'Match existing', 'talenttrack' ),
                    'import_new'       => __( 'New', 'talenttrack' ),
                    'import_matched'   => __( 'Matched on', 'talenttrack' ),
                    'import_choose'    => __( 'Data sets to import', 'talenttrack' ),
                    'import_existing'  => __( 'When a record already exists', 'talenttrack' ),
                    'import_insert'    => __( 'Insert as new', 'talenttrack' ),
                    'import_update'    => __( 'Update the existing record', 'talenttrack' ),
                    'import_users'     => __( 'Link WordPress users', 'talenttrack' ),
                    'import_dryrun'    => __( 'Preview changes (dry run)', 'talenttrack' ),
                    'import_dryrunning'=> __( 'Running dry run…', 'talenttrack' ),
                    'import_insert_c'  => __( 'Insert', 'talenttrack' ),
                    'import_update_c'  => __( 'Update', 'talenttrack' ),
                    'import_skip_c'    => __( 'Skip', 'talenttrack' ),
                    'import_warn'      => __( 'This writes the data above into this install. It cannot be undone automatically — take a backup first if unsure.', 'talenttrack' ),
                    'import_type'      => __( 'Type IMPORT to confirm:', 'talenttrack' ),
                    'import_now'       => __( 'Import now', 'talenttrack' ),
                    'import_committing'=> __( 'Importing…', 'talenttrack' ),
                    'import_done'      => __( 'The selected data was imported. Source ids were not preserved — references were remapped to this install.', 'talenttrack' ),
                    'import_dry_note'  => __( 'This is a preview — nothing has been written yet. Review the counts below, then confirm to apply.', 'talenttrack' ),
                    'no_importable'    => __( 'This archive has no importable record data sets. (Lookups & configuration are used to match references, not imported.)', 'talenttrack' ),
                    'leave_unlinked'   => __( '— Leave unlinked —', 'talenttrack' ),
                    'error'            => __( 'Something went wrong. Please try again.', 'talenttrack' ),
                    'network_error'    => __( 'Network error. Please try again.', 'talenttrack' ),
                ],
            ]
        );
    }
}
