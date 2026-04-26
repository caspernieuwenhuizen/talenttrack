<?php
/**
 * Migration 0023 — Workflow quarterly trigger seeds (#0022 Sprint 4).
 *
 * Seeds the cron trigger rows for the two quarterly Phase 1 templates:
 *
 *   - quarterly_goal_setting  : 00:00 on the 1st of every 3rd month.
 *     Player drafts goals; on completion spawns a goal_approval task.
 *   - quarterly_hod_review    : same cadence, fans out per HoD.
 *
 * goal_approval has no seeded trigger — it's spawned only by
 * quarterly_goal_setting.onComplete via the engine.
 *
 * Idempotent: only seeds when no row exists for the template_key.
 * Admins can disable / change cadence via the Sprint 5 config UI
 * without fear of this migration overwriting their choices on plugin
 * update.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0023_workflow_quarterly_triggers';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_workflow_triggers';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $seeds = [
            [
                'template_key'    => 'quarterly_goal_setting',
                'trigger_type'    => 'cron',
                'cron_expression' => '0 0 1 */3 *',
                'event_hook'      => null,
                'enabled'         => 1,
                'config_json'     => null,
            ],
            [
                'template_key'    => 'quarterly_hod_review',
                'trigger_type'    => 'cron',
                'cron_expression' => '0 0 1 */3 *',
                'event_hook'      => null,
                'enabled'         => 1,
                'config_json'     => null,
            ],
        ];

        foreach ( $seeds as $seed ) {
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE template_key = %s LIMIT 1",
                $seed['template_key']
            ) );
            if ( $exists > 0 ) continue;
            $wpdb->insert( $table, $seed );
        }
    }
};
