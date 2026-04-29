<?php
/**
 * Migration 0043 — PDP planning windows (#0054).
 *
 * Adds `planning_window_start` + `planning_window_end` to
 * `tt_pdp_conversations`. Backfills existing rows with a 21-day window
 * centred on `scheduled_at`, clamped to the season's start/end. Future
 * `createCycle()` writes the window directly.
 *
 * Idempotent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0043_pdp_planning_windows';
    }

    public function up(): void {
        $this->addColumns();
        $this->backfillExistingRows();
    }

    private function addColumns(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_pdp_conversations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $cols = [
            'planning_window_start' => 'DATE DEFAULT NULL',
            'planning_window_end'   => 'DATE DEFAULT NULL',
        ];

        foreach ( $cols as $name => $defn ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table, $name
            ) );
            if ( $exists === null ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$defn}" );
            }
        }
    }

    /**
     * Backfill: every existing conversation gets a window of
     * `scheduled_at ± 10 days`, clamped to the parent season's bounds.
     * Only rows missing the window get filled — re-running the migration
     * is a no-op.
     */
    private function backfillExistingRows(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT c.id, c.scheduled_at, s.start_date, s.end_date
               FROM {$p}tt_pdp_conversations c
          LEFT JOIN {$p}tt_pdp_files f ON f.id = c.pdp_file_id AND f.club_id = c.club_id
          LEFT JOIN {$p}tt_seasons s   ON s.id = f.season_id  AND s.club_id = c.club_id
              WHERE c.planning_window_start IS NULL OR c.planning_window_end IS NULL"
        );
        if ( ! is_array( $rows ) ) return;

        foreach ( $rows as $row ) {
            $sched = strtotime( (string) ( $row->scheduled_at ?? '' ) );
            if ( ! $sched ) continue;

            $window_start = strtotime( '-10 days', $sched );
            $window_end   = strtotime( '+10 days', $sched );

            $season_start = isset( $row->start_date ) ? strtotime( $row->start_date . ' 00:00:00' ) : false;
            $season_end   = isset( $row->end_date )   ? strtotime( $row->end_date   . ' 23:59:59' ) : false;
            if ( $season_start && $window_start < $season_start ) $window_start = $season_start;
            if ( $season_end   && $window_end   > $season_end   ) $window_end   = $season_end;

            $wpdb->update(
                "{$p}tt_pdp_conversations",
                [
                    'planning_window_start' => gmdate( 'Y-m-d', $window_start ),
                    'planning_window_end'   => gmdate( 'Y-m-d', $window_end ),
                ],
                [ 'id' => (int) $row->id ]
            );
        }
    }
};
