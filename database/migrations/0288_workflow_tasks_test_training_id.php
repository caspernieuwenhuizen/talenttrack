<?php
/**
 * Migration 0288 — `tt_workflow_tasks.test_training_id` (#3940).
 *
 * A completed `invite_to_test_training` task is the link between a prospect
 * and the test training they were invited to. Until now the session id lived
 * only inside the task's `response_json`, while `CascadeRegistry` declared a
 * `set_null` on a `test_training_id` column that no migration ever created.
 * So a permanent delete of a test training ran an UPDATE against a column
 * that does not exist, the cascade rolled back, and the delete failed.
 *
 * The link becomes a column, like every other entity link on this table
 * (`player_id`, `team_id`, `evaluation_id`, `goal_id`, `trial_case_id`,
 * `prospect_id`), with an index. The registry entry is then true as written.
 *
 * Backfill: completed invite tasks whose response carries a
 * `test_training_id` that still names an existing test training. Rows are
 * decoded in PHP one at a time, so one malformed `response_json` is skipped
 * rather than aborting the migration. A response pointing at a session that
 * is already gone is left NULL: a dangling link is what this fixes.
 *
 * Idempotent: the column and the index are added only when absent, and the
 * backfill only touches rows whose column is still NULL. Forward-only.
 * Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0288_workflow_tasks_test_training_id';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_workflow_tasks";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_workflow_tasks is missing; nothing to add' );
            return;
        }

        if ( ! MigrationHelpers::columnExists( $table, 'test_training_id' ) ) {
            $after = MigrationHelpers::columnExists( $table, 'prospect_id' ) ? ' AFTER prospect_id' : '';
            $this->exec( "ALTER TABLE {$table} ADD COLUMN test_training_id BIGINT UNSIGNED DEFAULT NULL{$after}" );
        }

        $has_index = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              LIMIT 1",
            $table,
            'idx_test_training'
        ) );
        if ( ! $has_index ) {
            $this->exec( "ALTER TABLE {$table} ADD KEY idx_test_training (test_training_id)" );
        }

        $sessions = "{$p}tt_test_trainings";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ) !== $sessions ) {
            $this->report( 0, 'column added; tt_test_trainings is missing, so there is nothing to backfill' );
            return;
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, response_json FROM {$table}
              WHERE template_key = %s
                AND status = %s
                AND test_training_id IS NULL
                AND response_json IS NOT NULL",
            'invite_to_test_training',
            'completed'
        ), ARRAY_A );

        $written = 0;
        $skipped = 0;
        foreach ( (array) $rows as $row ) {
            $decoded = json_decode( (string) ( $row['response_json'] ?? '' ), true );
            $tt_id   = is_array( $decoded ) && isset( $decoded['test_training_id'] ) ? (int) $decoded['test_training_id'] : 0;
            if ( $tt_id <= 0 ) {
                $skipped++;
                continue;
            }
            $exists = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$sessions} WHERE id = %d LIMIT 1",
                $tt_id
            ) );
            if ( ! $exists ) {
                $skipped++;
                continue;
            }
            $written += $this->exec( $wpdb->prepare(
                "UPDATE {$table} SET test_training_id = %d WHERE id = %d AND test_training_id IS NULL",
                $tt_id,
                (int) ( $row['id'] ?? 0 )
            ) );
        }

        $this->report( $written, sprintf(
            'column present; %d completed invite task(s) linked, %d without a live test training left NULL',
            $written,
            $skipped
        ) );
    }
};
