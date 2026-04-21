<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Activator;

/**
 * SchemaStatus — v3.0.0 migration UX.
 *
 * Historically admins had to deactivate + reactivate TalentTrack after
 * plugin updates to trigger migrations (because activation was the
 * only path that called the migration steps). This class surfaces the
 * migration as a first-class admin action:
 *
 *   1. After every successful migration, stores TT_VERSION in the
 *      `tt_installed_version` option.
 *   2. On every admin load, compares that option to the current
 *      TT_VERSION. Mismatch = migration pending.
 *   3. When pending, shows a persistent admin notice at the top of
 *      every page with a "Run now" button. One click → migration
 *      runs → banner disappears.
 *   4. Always shows a "Run Migrations" action link next to the
 *      TalentTrack plugin row on the Plugins page, for manual
 *      re-runs (diagnostic / recovery use).
 *
 * Both triggers call the same Activator::runMigrations() routine,
 * which is idempotent. Safe to invoke at any time.
 */
class SchemaStatus {

    private const OPTION_KEY = 'tt_installed_version';
    private const ACTION     = 'tt_run_migrations';

    public static function init(): void {
        add_action( 'admin_notices',                    [ __CLASS__, 'renderNotice' ] );
        add_action( 'admin_post_' . self::ACTION,       [ __CLASS__, 'handleRun' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( TT_FILE ), [ __CLASS__, 'filterActionLinks' ] );
    }

    /**
     * True when the stored installed version is missing or doesn't
     * match the running TT_VERSION. Missing option means the plugin
     * was never activated (fresh install, or activated before the
     * tracking existed — treat as pending).
     */
    public static function isPending(): bool {
        $stored = get_option( self::OPTION_KEY, '' );
        return $stored === '' || $stored !== TT_VERSION;
    }

    /**
     * Admin notice. Only shown to users who can actually run
     * migrations (administrators / tt_manage_settings).
     */
    public static function renderNotice(): void {
        if ( ! self::isPending() ) return;
        if ( ! current_user_can( 'tt_manage_settings' ) && ! current_user_can( 'activate_plugins' ) ) return;

        $stored = (string) get_option( self::OPTION_KEY, '' );
        $stored_display = $stored === '' ? __( '(never installed)', 'talenttrack' ) : $stored;

        $run_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=' . self::ACTION ),
            self::ACTION
        );

        ?>
        <div class="notice notice-warning" style="border-left-color:#d68c2a;">
            <p style="font-weight:600; margin-bottom:6px;">
                <?php esc_html_e( 'TalentTrack schema needs updating.', 'talenttrack' ); ?>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: current version, 2: previously installed version */
                    esc_html__( 'Plugin version %1$s is loaded but the installed schema is %2$s. Run the migration to bring the database up to date.', 'talenttrack' ),
                    '<code>' . esc_html( TT_VERSION ) . '</code>',
                    '<code>' . esc_html( $stored_display ) . '</code>'
                );
                ?>
            </p>
            <p style="margin-bottom:10px;">
                <a href="<?php echo esc_url( $run_url ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Run migrations now', 'talenttrack' ); ?>
                </a>
                <span style="margin-left:12px; color:#666; font-size:12px;">
                    <?php esc_html_e( 'Idempotent — safe to run repeatedly.', 'talenttrack' ); ?>
                </span>
            </p>
        </div>
        <?php
    }

    /**
     * Add a "Run Migrations" link to the plugin row on the Plugins
     * page. Always present (not only when pending) so admins can
     * force-rerun for diagnostic reasons if they suspect a prior
     * run failed partially.
     *
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public static function filterActionLinks( array $links ): array {
        if ( ! current_user_can( 'activate_plugins' ) ) return $links;
        $run_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=' . self::ACTION ),
            self::ACTION
        );
        $custom = [
            'tt-migrate'  => '<a href="' . esc_url( $run_url ) . '" title="' . esc_attr__( 'Run TalentTrack database migrations. Idempotent.', 'talenttrack' ) . '">' . esc_html__( 'Run Migrations', 'talenttrack' ) . '</a>',
            'tt-settings' => '<a href="' . esc_url( admin_url( 'admin.php?page=talenttrack' ) ) . '">' . esc_html__( 'Dashboard', 'talenttrack' ) . '</a>',
        ];
        // Prepend custom links before the standard Deactivate / Edit links.
        return $custom + $links;
    }

    /**
     * Admin-post handler for the "Run now" button. Verifies nonce +
     * capability, invokes runMigrations(), redirects back with a
     * success flag in the query string.
     */
    public static function handleRun(): void {
        check_admin_referer( self::ACTION );
        if ( ! current_user_can( 'tt_manage_settings' ) && ! current_user_can( 'activate_plugins' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'talenttrack' ) );
        }

        $error = '';
        try {
            Activator::runMigrations();
        } catch ( \Throwable $e ) {
            $error = $e->getMessage();
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[TalentTrack] Migration button failed: ' . $error );
            }
        }

        $back = wp_get_referer() ?: admin_url( 'admin.php?page=talenttrack' );
        $args = [
            'tt_migrated' => $error === '' ? '1' : '0',
        ];
        if ( $error !== '' ) {
            $args['tt_migrate_error'] = rawurlencode( substr( $error, 0, 200 ) );
        }
        wp_safe_redirect( add_query_arg( $args, $back ) );
        exit;
    }

    /**
     * One-shot confirmation notice after runMigrations() completes,
     * displayed when the redirect lands back on the admin with
     * ?tt_migrated=1|0 in the URL.
     */
    public static function renderResultNotice(): void {
        if ( ! isset( $_GET['tt_migrated'] ) ) return;
        $ok = (string) $_GET['tt_migrated'] === '1';
        if ( $ok ) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'TalentTrack migrations completed successfully.', 'talenttrack' ); ?></p>
            </div>
            <?php
        } else {
            $err = isset( $_GET['tt_migrate_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_migrate_error'] ) ) : '';
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <?php esc_html_e( 'TalentTrack migrations encountered an error.', 'talenttrack' ); ?>
                    <?php if ( $err !== '' ) : ?>
                        <br /><code><?php echo esc_html( $err ); ?></code>
                    <?php endif; ?>
                </p>
            </div>
            <?php
        }
    }
}
