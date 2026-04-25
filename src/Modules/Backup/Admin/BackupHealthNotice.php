<?php
namespace TT\Modules\Backup\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Backup\BackupRunner;
use TT\Modules\Backup\BackupSettings;
use TT\Modules\Backup\Scheduler;

/**
 * BackupHealthNotice — small admin-notice on the TalentTrack dashboard
 * surfacing whether backups are healthy.
 *
 * Three states:
 *   - GREEN:   last successful run within 24h (or schedule = on_demand
 *              and at least one successful run exists)
 *   - YELLOW:  last successful run between 24h and 7d ago, OR no run
 *              yet but schedule is configured
 *   - RED:     last successful run > 7d ago, OR last run failed, OR
 *              no destination is enabled
 *
 * The notice always links to the Backups settings tab so an admin
 * staring at red can fix it in one click.
 */
class BackupHealthNotice {

    public static function init(): void {
        add_action( 'admin_notices', [ self::class, 'maybeRender' ] );
    }

    public static function maybeRender(): void {
        if ( ! current_user_can( BackupSettingsPage::CAP ) ) return;

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) return;
        // Dashboard parent page only — same approach as OnboardingBanner.
        if ( strpos( (string) $screen->id, 'toplevel_page_talenttrack' ) === false ) return;

        $h         = self::status();
        $level     = $h['level'];
        $message   = $h['message'];
        $css_class = $level === 'green' ? 'notice-success' : ( $level === 'yellow' ? 'notice-warning' : 'notice-error' );

        $next_at = wp_next_scheduled( Scheduler::HOOK );
        $next    = $next_at ? sprintf(
            /* translators: %s is a human time-since string like "in 4 hours" */
            __( 'Next backup: %s.', 'talenttrack' ),
            human_time_diff( time(), (int) $next_at )
        ) : '';

        $url = admin_url( 'admin.php?page=tt-config&tab=backups' );
        ?>
        <div class="notice <?php echo esc_attr( $css_class ); ?>">
            <p>
                <strong><?php esc_html_e( 'Backups:', 'talenttrack' ); ?></strong>
                <?php echo esc_html( $message ); ?>
                <?php if ( $next !== '' ) : ?>
                    <span style="color:#666;"> · <?php echo esc_html( $next ); ?></span>
                <?php endif; ?>
                <a href="<?php echo esc_url( $url ); ?>" style="margin-left:10px;">
                    <?php esc_html_e( 'Backup settings →', 'talenttrack' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * @return array{level:string, message:string}
     */
    public static function status(): array {
        $settings = BackupSettings::get();
        $last     = BackupRunner::lastRun();
        $any_dest = ! empty( $settings['destinations']['local']['enabled'] ) || ! empty( $settings['destinations']['email']['enabled'] );

        if ( ! $any_dest ) {
            return [ 'level' => 'red', 'message' => __( 'No destinations enabled.', 'talenttrack' ) ];
        }

        if ( ! $last ) {
            return [ 'level' => 'yellow', 'message' => __( 'No backup has run yet.', 'talenttrack' ) ];
        }

        if ( empty( $last['ok'] ) ) {
            return [
                'level'   => 'red',
                'message' => sprintf(
                    /* translators: 1: time-since, 2: error message */
                    __( 'Last backup failed %1$s ago (%2$s).', 'talenttrack' ),
                    human_time_diff( (int) $last['at'], time() ),
                    (string) ( $last['error'] ?? '' )
                ),
            ];
        }

        $age = time() - (int) $last['at'];
        if ( $age <= DAY_IN_SECONDS ) {
            return [
                'level'   => 'green',
                'message' => sprintf(
                    /* translators: %s is a time-since string like "3 hours" */
                    __( 'Last backup %s ago.', 'talenttrack' ),
                    human_time_diff( (int) $last['at'], time() )
                ),
            ];
        }
        if ( $age <= 7 * DAY_IN_SECONDS ) {
            return [
                'level'   => 'yellow',
                'message' => sprintf(
                    /* translators: %s is a time-since string */
                    __( 'Last backup %s ago — past schedule.', 'talenttrack' ),
                    human_time_diff( (int) $last['at'], time() )
                ),
            ];
        }
        return [
            'level'   => 'red',
            'message' => sprintf(
                /* translators: %s is a time-since string */
                __( 'Last backup %s ago — backups are stale.', 'talenttrack' ),
                human_time_diff( (int) $last['at'], time() )
            ),
        ];
    }
}
