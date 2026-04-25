<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Scheduler — WP-cron wrapper around BackupRunner.
 *
 * Two responsibilities:
 *   1. Hook the `tt_backup_run` action so cron invocation actually runs
 *      a backup.
 *   2. Reconcile the registered cron schedule with the configured
 *      schedule on every settings save (and on plugin boot, in case
 *      the option drifted via direct DB edit).
 *
 * Schedules:
 *   - daily      → wp_schedule_event( 'daily' ),  starting tonight at 02:00 site-local
 *   - weekly     → wp_schedule_event( 'weekly' ), starting next Sunday 02:00
 *   - on_demand  → cron event removed; "Run backup now" button is the only invocation
 *
 * WP-cron is unreliable on low-traffic sites — settings page surfaces
 * a prominent "Run backup now" button so the admin doesn't depend on
 * cron firing.
 */
class Scheduler {

    public const HOOK = 'tt_backup_run';

    public static function init(): void {
        add_action( self::HOOK, [ self::class, 'cronHandler' ] );
        // Reconcile on every admin pageload so a settings save (or a
        // raw DB tweak) always converges the cron event to match.
        if ( is_admin() ) {
            add_action( 'admin_init', [ self::class, 'reconcile' ] );
        } else {
            // Non-admin requests still need at least one chance to
            // reconcile in case the only WP-cron consumer is a frontend
            // visitor.
            add_action( 'init', [ self::class, 'reconcile' ] );
        }
    }

    /**
     * Cron handler — runs a backup with the current settings.
     */
    public static function cronHandler(): void {
        BackupRunner::run();
    }

    /**
     * Match the registered cron event to the configured schedule.
     */
    public static function reconcile(): void {
        $settings = BackupSettings::get();
        $schedule = $settings['schedule'];
        $next     = wp_next_scheduled( self::HOOK );

        if ( $schedule === 'on_demand' ) {
            if ( $next ) wp_unschedule_event( $next, self::HOOK );
            return;
        }

        $recurrence = $schedule === 'weekly' ? 'weekly' : 'daily';
        if ( ! $next ) {
            wp_schedule_event( self::firstFireTimestamp( $recurrence ), $recurrence, self::HOOK );
        }
    }

    /**
     * On (de)activation we want a clean slate. Called from BackupModule.
     */
    public static function clear(): void {
        $next = wp_next_scheduled( self::HOOK );
        if ( $next ) wp_unschedule_event( $next, self::HOOK );
    }

    /**
     * Compute the timestamp of the first run for a given recurrence.
     * Daily: tonight at 02:00 site-local; weekly: next Sunday 02:00.
     */
    private static function firstFireTimestamp( string $recurrence ): int {
        // 02:00 in site-local is converted to UTC for wp_schedule_event,
        // which expects a UTC timestamp.
        $offset = (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
        $today_local_2am = strtotime( 'today 02:00:00' ) ?: time();
        // strtotime returns a server-tz timestamp; bring to UTC.
        $today_utc_2am   = $today_local_2am - $offset;

        $now = time();
        if ( $recurrence === 'weekly' ) {
            $sunday = strtotime( 'next Sunday 02:00:00' ) ?: ( $now + WEEK_IN_SECONDS );
            return $sunday - $offset;
        }
        return $today_utc_2am > $now ? $today_utc_2am : $today_utc_2am + DAY_IN_SECONDS;
    }
}
