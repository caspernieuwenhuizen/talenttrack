<?php
/**
 * Migration 0264 — `tt_comms_log` records whether the recipient was
 * reachable at all, beside why the send stopped (#3383).
 *
 * `status` was being asked two questions at once. A parent with no email
 * address on file logged `failed / no_address` at 10:00 and `quiet_hours`
 * at 22:00 — the same recipient, the same missing detail, two different
 * rows, and only one of them tells the sender what to fix. The fix is not
 * to pick a winner between the two statuses: it is that a row carries two
 * facts, not one.
 *
 * `reachable` is nullable on purpose. NULL means *not established* — the
 * send stopped before contact details were looked at, or the row predates
 * this column. 0 and 1 are claims; NULL is the absence of one, and the log
 * view renders it as such rather than as "reachable".
 *
 * NO BACKFILL, DELIBERATELY
 *
 * A row from last month cannot be re-asked: the contact details it would
 * be judged against are today's, not the ones that were on file when the
 * send ran. Inventing an answer for it would make the log say something it
 * did not say at the time. Migration 0258 refused the same thing under
 * #3382, for the same reason.
 *
 * Additive + idempotent — column-add on an existing table goes through
 * MigrationHelpers::addColumnIfMissing (never dbDelta, per the base-class
 * note). Forward-only. Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0264_comms_log_reachable';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_comms_log';

        // 0075 creates the table; an install that has not reached it yet
        // gets the column when this migration runs after it.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing(
            $table,
            'reachable',
            'TINYINT(1) DEFAULT NULL',
            'status'
        );
    }
};
