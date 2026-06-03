<?php
/**
 * Migration 0147 — Academy admin workflow takeover scope (#1152).
 *
 * Flips academy_admin's `workflow_tasks` + `task_completion` matrix
 * rows from `self` to `global` scope so the operator can view + act
 * on tasks assigned to others (operational continuity when the
 * assignee is ill or unresponsive).
 *
 * Pattern matches migration 0136 (the AC scope-tightening migration):
 * conservative — only flips `is_default = 1` rows so operator
 * customisations (`is_default = 0` flipped via the Authorization
 * admin) keep whatever scope they were given. The Authorization
 * admin's "Reset to defaults" button picks up the new shape on
 * next click.
 *
 * Idempotent — re-running on installs that already have the global
 * row is a no-op.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public function getName(): string {
        return '0147_academy_admin_workflow_global_scope';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $module_class = 'TT\\Modules\\Workflow\\WorkflowModule';

        // Entities + activities to flip. workflow_tasks gains 'read'
        // at global; task_completion gains 'read' + 'create'. Other
        // personas keep 'self' so this only widens admin reach.
        $targets = [
            [ 'entity' => 'workflow_tasks',  'activity' => 'read' ],
            [ 'entity' => 'task_completion', 'activity' => 'read' ],
            [ 'entity' => 'task_completion', 'activity' => 'create' ],
        ];

        foreach ( $targets as $t ) {
            // Drop the old self-scoped row (if it's the seeded default).
            // Operator-tweaked rows (is_default=0) are NOT removed.
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table}
                  WHERE persona = %s
                    AND entity = %s
                    AND activity = %s
                    AND scope_kind = %s
                    AND is_default = 1",
                'academy_admin', $t['entity'], $t['activity'], 'self'
            ) );

            // Insert the global row if not already present.
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                  WHERE persona = %s
                    AND entity = %s
                    AND activity = %s
                    AND scope_kind = %s",
                'academy_admin', $t['entity'], $t['activity'], 'global'
            ) );
            if ( $exists === 0 ) {
                $wpdb->insert( $table, [
                    'persona'      => 'academy_admin',
                    'entity'       => $t['entity'],
                    'activity'     => $t['activity'],
                    'scope_kind'   => 'global',
                    'module_class' => $module_class,
                    'is_default'   => 1,
                ] );
            }
        }

        // Flush the read cache so admin sessions pick up the new
        // shape on their next request without a forced refresh.
        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }

    public function down(): void {
        // Forward-only. Reverting would re-strand admin from completing
        // tasks for absent assignees — the operational regression this
        // migration is closing.
    }
};
