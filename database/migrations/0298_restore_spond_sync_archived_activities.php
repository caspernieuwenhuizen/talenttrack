<?php
/**
 * Migration 0298 — restore activities the Spond sync archived by mistake (#4182).
 *
 * The sync archived every Spond activity missing from its feed, but the
 * feed only covers 30 days back. Every played training and match older
 * than that was archived on the next sync, and with it its attendance,
 * minutes, match analysis and evaluations dropped out of every report.
 *
 * The sync archived with a raw UPDATE that left `archived_by` NULL; every
 * archive a person makes stamps their user id. So a row is restored when
 * it is a `spond` activity, archived with `archived_by IS NULL`, not in
 * the recycle bin, and either:
 *
 * - dated `SpondFetchWindow::PAST_DAYS` days or more before the day it was
 *   archived: it aged out of the window rather than being removed in
 *   Spond; or
 * - carrying recorded data (`ActivityRecordedData`): the sync must never
 *   archive such a row, wherever it is dated.
 *
 * Rows a person archived (`archived_by` set) stay archived. Idempotent: a
 * restored row is no longer archived, so a second run finds nothing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Activities\Services\ActivityRecordedData;
use TT\Modules\Spond\SpondFetchWindow;

return new class extends Migration {

    public function getName(): string {
        return '0298_restore_spond_sync_archived_activities';
    }

    public function up(): void {
        global $wpdb;
        $activities = $wpdb->prefix . 'tt_activities';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activities ) ) !== $activities ) {
            $this->report( 0, 'tt_activities missing' );
            return;
        }

        $past_days = (int) SpondFetchWindow::PAST_DAYS;
        $has_data  = ActivityRecordedData::sqlCondition( 'a' );

        $ids = $wpdb->get_col(
            "SELECT a.id FROM {$activities} a
              WHERE a.activity_source_key = 'spond'
                AND a.archived_at IS NOT NULL
                AND a.archived_by IS NULL
                AND a.trashed_at IS NULL
                AND (
                      ( a.session_date > '1000-01-01'
                        AND a.session_date <= DATE_SUB( DATE( a.archived_at ), INTERVAL {$past_days} DAY ) )
                   OR {$has_data}
                )"
        );
        $ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
        if ( ! $ids ) {
            $this->report( 0, 'no Spond activity archived by the sync needed restoring' );
            return;
        }

        $restored = 0;
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            $restored += $this->exec(
                "UPDATE {$activities}
                    SET archived_at = NULL, archived_by = NULL
                  WHERE id IN (" . implode( ',', $chunk ) . ')
                    AND archived_at IS NOT NULL
                    AND archived_by IS NULL'
            );
        }

        $this->report( $restored, 'Spond activities restored after a sync archived them' );
    }
};
