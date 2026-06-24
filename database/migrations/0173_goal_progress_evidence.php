<?php
/**
 * Migration 0173 — POP per-goal progress + evidence (#1717).
 *
 * The POP goal cards (#1686/#1754) reserve a progress bar + a "Bewijslast"
 * evidence row but render neither, because the data didn't exist. This adds:
 *
 *   - `tt_goals.progress_pct` (0–100, nullable) — an explicit per-goal
 *     completion percentage a coach sets on the goal form.
 *   - `tt_goal_evidence` — links specific evaluations to a goal as evidence
 *     (scored "Beoordeling <date> · <score>" chips). A dedicated table, not
 *     `tt_goal_links` (which is methodology-only and whose sync replaces the
 *     whole link set), so evidence and methodology links stay independent and
 *     evidence can be ordered by creation.
 *
 * Idempotent; additive; forward-only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0173_goal_progress_evidence';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $goals = $p . 'tt_goals';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $goals ) ) === $goals
            && ! $this->columnExists( $goals, 'progress_pct' ) ) {
            $wpdb->query( "ALTER TABLE {$goals} ADD COLUMN progress_pct TINYINT UNSIGNED NULL DEFAULT NULL" );
        }

        $charset = $wpdb->get_charset_collate();
        $ev = $p . 'tt_goal_evidence';
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$ev} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                goal_id BIGINT UNSIGNED NOT NULL,
                evaluation_id BIGINT UNSIGNED NOT NULL,
                created_by BIGINT UNSIGNED NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_goal_eval (goal_id, evaluation_id),
                KEY idx_goal (goal_id)
            ) {$charset}"
        );
    }

    private function columnExists( string $table, string $column ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME, $table, $column
        ) ) > 0;
    }
};
