<?php
/**
 * Migration 0220 — widen `tt_comms_log.status` (#2603, epic #2600).
 *
 * Migration 0075 declared `status VARCHAR(16)`, sized for the five
 * statuses that existed then (`queued` / `sent` / `delivered` /
 * `bounced` / `failed`). The vocabulary has since grown, and
 * `template_disabled` is 17 characters — one over.
 *
 * That is not a cosmetic overflow. On a strict-mode MySQL the INSERT is
 * rejected outright, so switching a template off would have produced no
 * audit row at all: the exact silent failure #2602 set out to remove,
 * reintroduced by a column width. `$wpdb->insert()` reports that by
 * returning false rather than throwing, so the audit logger's catch
 * block never saw it either (it now checks the return value — see
 * CommsAuditLogger).
 *
 * Widened to 32 rather than 17 so the next status added to the
 * vocabulary does not need a migration. The column stays VARCHAR rather
 * than becoming an ENUM for the same reason: statuses are added by code
 * as use cases ship, and an ENUM would make every addition a schema
 * change.
 *
 * Idempotent: re-running is a no-op once the column is already wide
 * enough, and the table-missing case short-circuits so an install that
 * has not run 0075 yet is not an error.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0220_comms_log_status_width';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_comms_log';

        // 0075 may not have run on this install yet; it creates the column
        // at its current width and a later run of this migration widens it.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'status'" );
        if ( ! $column ) {
            return;
        }

        // Already wide enough — nothing to do.
        if ( preg_match( '/varchar\((\d+)\)/i', (string) $column->Type, $m ) && (int) $m[1] >= 32 ) {
            return;
        }

        $wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'queued'" );
    }
};
