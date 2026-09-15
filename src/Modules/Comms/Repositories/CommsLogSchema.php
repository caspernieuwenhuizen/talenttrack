<?php
namespace TT\Modules\Comms\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\MigrationHelpers;

/**
 * CommsLogSchema (#3383) — what this install's `tt_comms_log` actually has.
 *
 * Migration 0264 adds `reachable`. Between a plugin update and the
 * migration run, the code is new and the table is not — and on that
 * install an INSERT naming the column is rejected outright, which would
 * cost the audit row rather than the column. `CommsAuditLogger` already
 * treats a missing table as "log loudly, don't crash"; this is the same
 * courtesy one column down.
 *
 * Cached per request: the answer cannot change inside one, and the check
 * would otherwise run on every send in a broadcast.
 */
final class CommsLogSchema {

    private static ?bool $hasReachable = null;

    public static function hasReachable(): bool {
        if ( self::$hasReachable === null ) {
            global $wpdb;
            self::$hasReachable = MigrationHelpers::columnExists( $wpdb->prefix . 'tt_comms_log', 'reachable' );
        }
        return self::$hasReachable;
    }

    /** Test seam — forget what was cached. */
    public static function forget(): void {
        self::$hasReachable = null;
    }
}
