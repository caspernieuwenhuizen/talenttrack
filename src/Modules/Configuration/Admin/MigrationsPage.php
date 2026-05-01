<?php
namespace TT\Modules\Configuration\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\MigrationRunner;

/**
 * MigrationsPage — admin UI for database migrations.
 *
 * Lists all migration files on disk and shows, for each, whether it's applied,
 * pending, or (in an edge case) applied-but-file-missing. Pending migrations
 * get a per-row "Run" button; when multiple are pending, a "Run All Pending"
 * button appears at the top.
 *
 * Errors from migration runs are surfaced verbatim — no silent failures.
 */
class MigrationsPage {

    public static function init(): void {
        add_action( 'admin_post_tt_run_migration',     [ __CLASS__, 'handle_run_one' ] );
        add_action( 'admin_post_tt_run_all_migrations', [ __CLASS__, 'handle_run_all' ] );
    }

    public static function render_page(): void {
        // #0071 follow-up — `tt_view_migrations` is the specific sub-cap;
        // CapabilityAliases roll-up still grants it for legacy
        // `tt_view_settings` holders.
        if ( ! current_user_can( 'tt_view_migrations' ) && ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $runner = new MigrationRunner();
        $state  = $runner->inspect();

        $applied_names = array_column( $state['applied'], 'name' );
        $pending_count = count( $state['pending'] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Database Migrations', 'talenttrack' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'TalentTrack uses versioned database migrations to evolve its schema safely. This page shows every migration shipped with the plugin and its status.', 'talenttrack' ); ?>
            </p>

            <?php self::render_result_notices(); ?>

            <?php if ( ! empty( $state['missing_files'] ) ) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e( 'Warning: migrations recorded as applied but missing from disk:', 'talenttrack' ); ?></strong></p>
                    <ul style="margin-left:20px;list-style:disc;">
                        <?php foreach ( $state['missing_files'] as $m ) : ?>
                            <li><code><?php echo esc_html( $m ); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                    <p><?php esc_html_e( 'This usually means migration files were deleted or never copied during manual installation. No action required unless you need to re-apply.', 'talenttrack' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( $pending_count > 0 ) : ?>
                <div class="notice notice-warning" style="padding:15px;">
                    <h3 style="margin-top:0;">
                        <?php echo esc_html( sprintf(
                            _n( '%d pending migration', '%d pending migrations', $pending_count, 'talenttrack' ),
                            $pending_count
                        ) ); ?>
                    </h3>
                    <p><?php esc_html_e( 'Pending migrations typically ship new schema changes required by recent features. Apply them to keep your database in sync with the plugin code.', 'talenttrack' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                        <?php wp_nonce_field( 'tt_run_all_migrations', 'tt_nonce' ); ?>
                        <input type="hidden" name="action" value="tt_run_all_migrations" />
                        <?php submit_button( __( 'Run All Pending Migrations', 'talenttrack' ), 'primary', 'submit', false ); ?>
                    </form>
                </div>
            <?php else : ?>
                <div class="notice notice-success">
                    <p><strong><?php esc_html_e( 'Database is up to date.', 'talenttrack' ); ?></strong>
                    <?php echo esc_html( sprintf(
                        _n( '%d migration applied.', '%d migrations applied.', count( $applied_names ), 'talenttrack' ),
                        count( $applied_names )
                    ) ); ?></p>
                </div>
            <?php endif; ?>

            <h2 style="margin-top:30px;"><?php esc_html_e( 'All Migrations', 'talenttrack' ); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Migration', 'talenttrack' ); ?></th>
                        <th style="width:180px;"><?php esc_html_e( 'Applied At', 'talenttrack' ); ?></th>
                        <th style="width:140px;"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Build the combined, sorted list: all pending + all applied.
                    $rows = [];
                    foreach ( $state['applied'] as $a ) {
                        $rows[ $a['name'] ] = [ 'name' => $a['name'], 'applied' => true, 'applied_at' => $a['applied_at'] ];
                    }
                    foreach ( $state['pending'] as $name ) {
                        $rows[ $name ] = [ 'name' => $name, 'applied' => false, 'applied_at' => null ];
                    }
                    ksort( $rows );

                    if ( empty( $rows ) ) : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'No migrations found.', 'talenttrack' ); ?></td></tr>
                    <?php else : foreach ( $rows as $r ) : ?>
                        <tr>
                            <td>
                                <?php if ( $r['applied'] ) : ?>
                                    <span style="color:#00a32a;font-weight:bold;">✓</span>
                                <?php else : ?>
                                    <span style="color:#c9a227;font-weight:bold;">⏳</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html( $r['name'] ); ?></code></td>
                            <td>
                                <?php if ( $r['applied'] ) : ?>
                                    <?php echo esc_html( $r['applied_at'] ); ?>
                                <?php else : ?>
                                    <em style="color:#c9a227;"><?php esc_html_e( 'Pending', 'talenttrack' ); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! $r['applied'] ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                                        <?php wp_nonce_field( 'tt_run_migration_' . $r['name'], 'tt_nonce' ); ?>
                                        <input type="hidden" name="action"    value="tt_run_migration" />
                                        <input type="hidden" name="migration" value="<?php echo esc_attr( $r['name'] ); ?>" />
                                        <button type="submit" class="button button-primary button-small">
                                            <?php esc_html_e( 'Run', 'talenttrack' ); ?>
                                        </button>
                                    </form>
                                <?php else : ?>
                                    <span style="color:#666;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:30px;"><?php esc_html_e( 'Diagnostic Information', 'talenttrack' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Migrations directory', 'talenttrack' ); ?></th>
                    <td><code><?php echo esc_html( $state['migrations_dir'] ); ?></code>
                        <?php if ( ! is_dir( $state['migrations_dir'] ) ) : ?>
                            <span style="color:#b32d2e;margin-left:8px;"><?php esc_html_e( '(directory not found!)', 'talenttrack' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Tracking table', 'talenttrack' ); ?></th>
                    <td>
                        <?php if ( $state['tracking_table_exists'] ) : ?>
                            <span style="color:#00a32a;">✓</span> <code><?php global $wpdb; echo esc_html( $wpdb->prefix . 'tt_migrations' ); ?></code>
                        <?php else : ?>
                            <span style="color:#b32d2e;">✗</span> <?php esc_html_e( 'Tracking table does not exist yet.', 'talenttrack' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Plugin version', 'talenttrack' ); ?></th>
                    <td><code><?php echo esc_html( TT_VERSION ); ?></code></td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Display any run-results stashed in a transient by the handlers.
     */
    private static function render_result_notices(): void {
        $key = 'tt_migration_results_' . get_current_user_id();
        $results = get_transient( $key );
        if ( ! is_array( $results ) || empty( $results ) ) return;
        delete_transient( $key );

        foreach ( $results as $r ) {
            if ( ! is_array( $r ) ) continue;
            $name     = (string) ( $r['name'] ?? '(unknown)' );
            $ok       = ! empty( $r['ok'] );
            $skipped  = ! empty( $r['skipped'] );
            $error    = (string) ( $r['error'] ?? '' );
            $duration = (int) ( $r['duration_ms'] ?? 0 );

            if ( $ok ) {
                $class = 'notice-success';
                $msg   = $skipped
                    ? sprintf( __( 'Migration %s was already applied.', 'talenttrack' ), '<code>' . esc_html( $name ) . '</code>' )
                    : sprintf( __( 'Migration %1$s applied successfully in %2$dms.', 'talenttrack' ), '<code>' . esc_html( $name ) . '</code>', $duration );
            } else {
                $class = 'notice-error';
                $msg   = sprintf( __( 'Migration %s failed.', 'talenttrack' ), '<code>' . esc_html( $name ) . '</code>' );
            }
            ?>
            <div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
                <p><?php echo wp_kses( $msg, [ 'code' => [] ] ); ?></p>
                <?php if ( ! $ok && $error !== '' ) : ?>
                    <p style="font-family:monospace;font-size:12px;background:#fff7e0;padding:8px;border-left:3px solid #b32d2e;"><?php echo esc_html( $error ); ?></p>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    // Handlers

    public static function handle_run_one(): void {
        if ( ! current_user_can( 'tt_edit_migrations' ) && ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        $name = isset( $_POST['migration'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['migration'] ) ) : '';
        if ( $name === '' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tt-migrations' ) );
            exit;
        }
        check_admin_referer( 'tt_run_migration_' . $name, 'tt_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'migration.run_one' );

        $runner = new MigrationRunner();
        $result = $runner->runOne( $name );

        set_transient( 'tt_migration_results_' . get_current_user_id(), [ $result ], 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-migrations' ) );
        exit;
    }

    public static function handle_run_all(): void {
        if ( ! current_user_can( 'tt_edit_migrations' ) && ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_run_all_migrations', 'tt_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'migration.run_all' );

        $runner  = new MigrationRunner();
        $results = $runner->run();

        if ( empty( $results ) ) {
            $results = [ [ 'name' => '(none)', 'ok' => true, 'error' => null, 'duration_ms' => 0, 'skipped' => true ] ];
        }
        set_transient( 'tt_migration_results_' . get_current_user_id(), $results, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-migrations' ) );
        exit;
    }
}
