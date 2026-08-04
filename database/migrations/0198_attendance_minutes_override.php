<?php
/**
 * Migration 0198 — per-player minute override on attendance.
 *
 * Adds nullable tt_attendance.minutes_override. When a match execution owns
 * an activity's minutes, minutes_played holds the sub-log-derived value and
 * minutes_override holds an explicit coach correction entered on the
 * match-execution surface. Effective minutes = COALESCE(minutes_override,
 * minutes_played). A separate column means the recompute that rewrites
 * minutes_played on every post-match edit can never clobber the override.
 *
 * Additive + idempotent (SHOW COLUMNS guard via MigrationHelpers). The
 * tt_attendance table is pre-existing and already carries club_id, so no
 * tenancy scaffold is added here. Forward-only. Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0198_attendance_minutes_override';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_attendance';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing(
            $table,
            'minutes_override',
            'SMALLINT UNSIGNED DEFAULT NULL',
            'minutes_played'
        );
    }
};
