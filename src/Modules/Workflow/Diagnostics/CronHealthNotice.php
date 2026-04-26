<?php
namespace TT\Modules\Workflow\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CronHealthNotice — wp-admin banner that flags hosts where WP-cron
 * has stopped firing. Renders on TalentTrack admin pages only,
 * dismissible per-user, returns if the underlying condition persists
 * for more than 7 days after dismissal.
 *
 * Detection signal: at least one open or in_progress task with
 * `due_at < NOW() - 24 hours`. The reasoning: a deadline that's a
 * full day past while the task is still actionable strongly suggests
 * the hourly tick (CronDispatcher::tick) hasn't been moving tasks to
 * `overdue` either. Both states point to WP-cron not firing.
 *
 * The notice links to docs/workflow-engine-cron-setup.md.
 */
class CronHealthNotice {

    private const DISMISS_META  = '_tt_workflow_cron_health_dismissed_at';
    private const REAPPEAR_DAYS = 7;

    public static function init(): void {
        add_action( 'admin_notices', [ self::class, 'maybeRender' ] );
        add_action( 'admin_post_tt_workflow_dismiss_cron_health', [ self::class, 'handleDismiss' ] );
    }

    public static function maybeRender(): void {
        if ( ! current_user_can( 'tt_view_tasks_dashboard' ) ) return;
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) return;
        // Only on TalentTrack pages — avoids polluting unrelated screens.
        if ( strpos( (string) $screen->id, 'talenttrack' ) === false ) return;

        $user_id = get_current_user_id();
        $dismissed_at = (int) get_user_meta( $user_id, self::DISMISS_META, true );
        if ( $dismissed_at > 0 && ( time() - $dismissed_at ) < ( self::REAPPEAR_DAYS * DAY_IN_SECONDS ) ) {
            return;
        }

        if ( ! self::isUnhealthy() ) return;

        $docs_url = function_exists( 'admin_url' )
            ? admin_url( 'admin.php?page=talenttrack&tt_help=workflow-engine-cron-setup' )
            : '#';
        $dismiss_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=tt_workflow_dismiss_cron_health' ),
            'tt_workflow_dismiss_cron_health'
        );

        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'TalentTrack workflow:', 'talenttrack' ); ?></strong>
                <?php esc_html_e( 'Scheduled tasks don\'t appear to be running reliably on this install. Your host\'s WordPress cron may need attention.', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( $docs_url ); ?>"><?php esc_html_e( 'Learn how to fix →', 'talenttrack' ); ?></a>
                &nbsp;<a href="<?php echo esc_url( $dismiss_url ); ?>" style="color:#666;"><?php esc_html_e( 'Dismiss for 7 days', 'talenttrack' ); ?></a>
            </p>
        </div>
        <?php
    }

    public static function handleDismiss(): void {
        if ( ! current_user_can( 'tt_view_tasks_dashboard' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'talenttrack' ), 403 );
        }
        check_admin_referer( 'tt_workflow_dismiss_cron_health' );
        update_user_meta( get_current_user_id(), self::DISMISS_META, time() );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=talenttrack' ) );
        exit;
    }

    /**
     * True when at least one open / in_progress task has a deadline
     * older than 24 hours. The hourly cron tick should have moved
     * those to `overdue` and (separately) the deadline reminder mails
     * should have fired — neither happens if WP-cron is broken.
     */
    private static function isUnhealthy(): bool {
        global $wpdb;
        // Schema may not exist yet on a partial install — fail closed.
        $table = $wpdb->prefix . 'tt_workflow_tasks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return false;

        $threshold = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status IN ('open','in_progress')
               AND due_at < %s",
            $threshold
        ) );
        return $count > 0;
    }
}
