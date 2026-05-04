<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\MigrationRunner;
use TT\Modules\Configuration\Admin\MigrationsPage;

/**
 * MenuExtension — boots the Migrations admin page handler and shows a
 * pending-migration warning banner across TalentTrack admin pages.
 *
 * v3.90.0 — submenu registration moved to AdminMenuRegistry via
 * CoreSurfaceRegistration so Migrations sits inside the Configuration
 * group rather than visually trailing the Access Control items.
 *
 * Hooks:
 *   - admin_notices — shows a warning banner on every TalentTrack admin page
 *     when pending migrations exist.
 */
class MenuExtension {

    public static function init(): void {
        add_action( 'admin_notices', [ __CLASS__, 'render_pending_notice' ] );
        MigrationsPage::init();
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
