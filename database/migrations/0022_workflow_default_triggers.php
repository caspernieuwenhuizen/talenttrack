<?php
/**
 * Migration 0022 — Workflow default trigger seeds (#0022 Sprint 3).
 *
 * Seeds the default trigger rows in tt_workflow_triggers for the
 * Phase 1 templates whose firing is automatic (cron-based):
 *
 *   - player_self_evaluation: cron `0 18 * * 0` (Sundays 18:00).
 *
 * Templates whose firing is manual or event-based don't need a seeded
 * trigger row in v1:
 *   - post_match_evaluation: manual in v1; an event-trigger row will
 *     be added once SessionsModule starts firing `tt_session_completed`
 *     (deferred behind the parallel #0026 branch — adding the
 *     `do_action` there now would conflict with that work).
 *
 * Idempotent: only seeds when no row exists for the template_key.
 * Admins can disable / change cadence via the Sprint 5 admin UI without
 * fear of this migration overwriting their choices on plugin update.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0022_workflow_default_triggers';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_workflow_triggers';

        // Schema guard — bail if the table doesn't exist (e.g. partial install).
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $seeds = [
            [
                'template_key'    => 'player_self_evaluation',
                'trigger_type'    => 'cron',
                'cron_expression' => '0 18 * * 0',
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
