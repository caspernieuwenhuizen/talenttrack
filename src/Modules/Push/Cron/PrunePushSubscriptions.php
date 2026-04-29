<?php
namespace TT\Modules\Push\Cron;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Push\PushSubscriptionsRepository;

/**
 * PrunePushSubscriptions — daily cleanup of stale Web Push rows
 * (#0042). Subscriptions whose `last_seen_at` is older than 90 days
 * are removed; the user's browser will re-register on next dashboard
 * visit if they're still active.
 *
 * Lives next to (rather than inside) the workflow engine's
 * diagnostic cron so a Push-module-only disable cleanly stops this
 * task too.
 */
final class PrunePushSubscriptions {

    public const HOOK            = 'tt_push_prune_subscriptions';
    public const INACTIVITY_DAYS = 90;

    public static function init(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
        add_action( 'init',     [ self::class, 'ensureScheduled' ] );
    }

    public static function ensureScheduled(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 3600, 'daily', self::HOOK );
        }
    }

    public static function run(): void {
        ( new PushSubscriptionsRepository() )->pruneOlderThan( self::INACTIVITY_DAYS );
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled( self::HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::HOOK );
    }
}
