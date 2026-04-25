<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\MigrationRunner;
use TT\Modules\Configuration\Admin\MigrationsPage;

/**
 * MenuExtension — adds the Migrations submenu page AND a pending-migration
 * warning notice to the TalentTrack admin area, without modifying the existing
 * Menu class. Safer than patching Menu.php because it avoids any risk of
 * losing menu items added by later sprints.
 *
 * Hooks:
 *   - admin_menu (priority 20) — runs after the main Menu::register() at
 *     priority 10, so the 'talenttrack' top-level slug exists.
 *   - admin_notices — shows a warning banner on every TalentTrack admin page
 *     when pending migrations exist.
 */
class MenuExtension {

    public static function init(): void {
        add_action( 'admin_menu',    [ __CLASS__, 'register_submenu' ], 20 );
        add_action( 'admin_notices', [ __CLASS__, 'render_pending_notice' ] );
        MigrationsPage::init();
    }

    public static function register_submenu(): void {
        // #0019 Sprint 6 — gated on the same legacy-menu toggle as the
        // rest of the migrated wp-admin pages. Direct URL still works.
        if ( ! \TT\Shared\Admin\Menu::shouldShowLegacyMenus() ) return;

        // Permissions: either tt_manage_settings OR administrator.
        $cap = current_user_can( 'tt_view_settings' ) ? 'tt_view_settings' : 'administrator';

        add_submenu_page(
            'talenttrack',
            __( 'Database Migrations', 'talenttrack' ),
            self::menu_label(),
            $cap,
            'tt-migrations',
            [ MigrationsPage::class, 'render_page' ]
        );
    }

    /**
     * Menu label — includes a pending count badge when pending migrations exist.
     */
    private static function menu_label(): string {
        $pending = self::pending_count();
        $label   = __( 'Migrations', 'talenttrack' );

        if ( $pending > 0 ) {
            $label .= ' <span class="awaiting-mod count-' . (int) $pending . '"><span class="pending-count">' . (int) $pending . '</span></span>';
        }
        return $label;
    }

    /**
     * Admin-wide notice: show a warning banner on every TalentTrack page
     * if there are pending migrations. This is the primary visibility surface
     * since auto-update + auto-migration can't always be relied on.
     */
    public static function render_pending_notice(): void {
        if ( ! current_user_can( 'tt_view_settings' ) && ! current_user_can( 'administrator' ) ) {
            return;
        }

        // Only show on TalentTrack admin pages to avoid polluting the WP global admin.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'talenttrack' ) === false && strpos( (string) $screen->id, 'tt-' ) === false ) {
            return;
        }

        // But don't show it on the Migrations page itself — you're already there.
        if ( isset( $_GET['page'] ) && sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) === 'tt-migrations' ) {
            return;
        }

        $pending = self::pending_count();
        if ( $pending === 0 ) return;

        $url = admin_url( 'admin.php?page=tt-migrations' );
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'TalentTrack: database migrations pending.', 'talenttrack' ); ?></strong>
                <?php echo esc_html( sprintf(
                    _n(
                        '%d migration is waiting to be applied. Some features may not work until you apply it.',
                        '%d migrations are waiting to be applied. Some features may not work until you apply them.',
                        $pending,
                        'talenttrack'
                    ),
                    $pending
                ) ); ?>
                <a href="<?php echo esc_url( $url ); ?>" class="button button-primary button-small" style="margin-left:8px;">
                    <?php esc_html_e( 'Review and Apply', 'talenttrack' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private static function pending_count(): int {
        static $cached = null;
        if ( $cached !== null ) return $cached;

        try {
            $runner = new MigrationRunner();
            $state  = $runner->inspect();
            $cached = count( $state['pending'] );
        } catch ( \Throwable $e ) {
            $cached = 0;
        }
        return $cached;
    }
}
