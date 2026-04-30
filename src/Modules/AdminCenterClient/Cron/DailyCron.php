<?php
namespace TT\Modules\AdminCenterClient\Cron;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\AdminCenterClient\PayloadBuilder;
use TT\Modules\AdminCenterClient\Sender;

/**
 * DailyCron — schedules and runs the daily phone-home
 * (#0065 / TTA #0001).
 *
 * Cadence is daily (±2h slack — wp-cron's ordinary jitter). One
 * payload per 24h with `trigger: "daily"`. Failure is silent and
 * retried on the next tick.
 */
final class DailyCron {

    public const HOOK = 'tt_admin_center_daily_phone_home';

    public static function init(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
        add_action( 'init',     [ self::class, 'ensureScheduled' ] );
    }

    public static function ensureScheduled(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 3600, 'daily', self::HOOK );
        }
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled( self::HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::HOOK );
    }

    public static function run(): void {
        Sender::send( PayloadBuilder::TRIGGER_DAILY );
    }
}
