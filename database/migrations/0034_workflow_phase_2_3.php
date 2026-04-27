<?php
/**
 * Migration: 0034_workflow_phase_2_3
 *
 * #0022 Phase 2 + Phase 3 — engine extensions:
 *
 *   - tt_workflow_event_log     (Phase 3 — event bus with retry/replay)
 *   - tt_workflow_tasks.snoozed_until  (Phase 2 — assignee snooze)
 *   - tt_workflow_tasks.spawned_by_step (Phase 2 — chain step provenance)
 *
 * Idempotent. Safe to re-run.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0034_workflow_phase_2_3';
    }

    public function up(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Event log table — every event-typed trigger firing writes a
        // row here. Successful dispatches transition `processed`;
        // failures stay `failed` for replay via the admin retry button.
        $sql = "CREATE TABLE {$p}tt_workflow_event_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_hook VARCHAR(120) NOT NULL,
            template_key VARCHAR(64) NOT NULL,
            args_json LONGTEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            retries SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            error_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            tasks_created LONGTEXT,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_template (template_key),
            KEY idx_created (created_at)
        ) $c;";
        dbDelta( $sql );

        // tt_workflow_tasks column adds. dbDelta is unreliable for ALTER,
        // so guard each one with SHOW COLUMNS.
        $tasks = $p . 'tt_workflow_tasks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tasks ) ) === $tasks ) {
            self::ensureColumn( $tasks, 'snoozed_until', 'DATETIME DEFAULT NULL' );
            self::ensureColumn( $tasks, 'spawned_by_step', 'VARCHAR(64) DEFAULT NULL' );

            // Helpful index for inbox queries that filter out snoozed tasks.
            $has_idx = $wpdb->get_var(
                "SHOW INDEX FROM `$tasks` WHERE Key_name = 'idx_snoozed'"
            );
            if ( ! $has_idx ) {
                $wpdb->query( "ALTER TABLE `$tasks` ADD KEY `idx_snoozed` (`snoozed_until`)" );
            }
        }
    }

    private static function ensureColumn( string $table, string $column, string $definition ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SHOW COLUMNS FROM `$table` LIKE %s",
            $column
        ) );
        if ( $row !== null ) return;
        $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `$column` $definition" );
    }
};
