<?php
namespace TT\Modules\Authorization\Impersonation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ImpersonationCron (#0071 child 5) — daily orphan cleanup. Closes
 * `tt_impersonation_log` rows older than 24h with `end_reason='expired'`
 * so the audit log doesn't accumulate dangling sessions when an admin
 * closes the browser without clicking Switch back.
 */
final class ImpersonationCron {

    public const HOOK = 'tt_impersonation_cleanup_cron';

    public static function init(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
        add_action( 'init',    [ self::class, 'ensureScheduled' ] );
    }

    public static function ensureScheduled(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 3600, 'daily', self::HOOK );
        }
    }

    public static function run(): void {
        ImpersonationService::cleanupOrphans();
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled( self::HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::HOOK );
    }
}
