<?php
/**
 * Migration 0332 — goals without a status are stored as pending.
 *
 * A goal whose `status` is NULL, empty, or `open` in any case or padding
 * holds no status the `goal_status` lookup knows, so `GoalLifecycle`
 * counted it as not open while the goal screens showed it as live. This
 * stores those rows as `pending` (`GoalStatus::PENDING`), the status a new
 * goal starts in, which `GoalStatus::OPEN` counts as open. Every other
 * status is left exactly as it is.
 *
 * Idempotent: a second run finds no such row. Forward-only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0332_goals_without_status_pending';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_goals';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_goals is missing' );
            return;
        }

        $changed = $this->exec(
            "UPDATE `{$table}`
                SET status = 'pending'
              WHERE status IS NULL
                 OR TRIM( status ) = ''
                 OR LOWER( TRIM( status ) ) = 'open'"
        );

        $this->report(
            $changed,
            $changed > 0 ? sprintf( '%d goal(s) without a status stored as pending', $changed ) : 'every goal already has a status'
        );
    }

    public function down(): void {
        // Forward-only. Which goals held no status is not recorded, and a
        // goal without one is the state this repair removes.
    }
};
