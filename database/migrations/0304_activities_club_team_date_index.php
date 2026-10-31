<?php
/**
 * Migration 0304 — a date index on `tt_activities` (#4392).
 *
 * `tt_activities` was indexed on the team (`idx_team`) but not on the date,
 * so "this team's activities between two dates" read every activity a team
 * ever had: the coach's "This week" panel, the team planner, the minutes
 * grid and the attendance grid. `idx_club_team_date (club_id, team_id,
 * session_date)` serves all of them; `idx_team` stays until a later
 * clean-up shows nothing else relies on it.
 *
 * Idempotent: the key is added only when `information_schema.STATISTICS`
 * does not already list it (0170 is the reference).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const INDEX = 'idx_club_team_date';

    public function getName(): string {
        return '0304_activities_club_team_date_index';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_activities';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_activities missing' );
            return;
        }

        $exists = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              LIMIT 1",
            $table, self::INDEX
        ) );
        if ( $exists ) return;

        $this->exec( "ALTER TABLE {$table} ADD KEY " . self::INDEX . ' (club_id, team_id, session_date)' );
        $this->report( 1, self::INDEX . ' added' );
    }
};
