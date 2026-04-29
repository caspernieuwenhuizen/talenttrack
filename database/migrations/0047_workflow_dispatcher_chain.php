<?php
/**
 * Migration 0047 — Per-template dispatcher chain (#0042 Sprint 5).
 *
 * Adds a `dispatcher_chain` column to `tt_workflow_template_config`
 * so the admin UI can pick a notification posture per workflow
 * template. Values are an enum parsed by `DispatcherChain::PRESETS`:
 *
 *   email              — current default, no behaviour change
 *   push_parent_email  — push if available, fall back to parent email
 *   push_own_email     — push if available, fall back to own email
 *   push_only          — push only; no email fallback
 *
 * NULL means "use the engine default" (currently email-only). Existing
 * rows are left untouched, so installed clubs see no behaviour change
 * after running this migration. Idempotent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0047_workflow_dispatcher_chain';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_workflow_template_config';

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'dispatcher_chain'",
            $table
        ) );

        if ( $exists === 0 ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN dispatcher_chain VARCHAR(64) DEFAULT NULL AFTER assignee_override_json" );
        }
    }

    public function down(): void {
        // No-op. Schema migrations are forward-only in this project.
    }
};
