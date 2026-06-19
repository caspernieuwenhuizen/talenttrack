<?php
/**
 * Migration 0157 — `tt_activities.created_by` / `updated_by` (#1471).
 *
 * Audit columns backing the "Created by X · Last changed by Y" line on
 * the activity detail page. Nullable: existing rows have no recorded
 * author and stay blank (forward-only population — no history to
 * backfill). Distinct from `coach_id`, which is business data.
 *
 * Idempotent — SHOW COLUMNS guard per column.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0157_activities_audit_columns';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_activities";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $created = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'created_by' ) );
        if ( $created !== 'created_by' ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN created_by BIGINT UNSIGNED DEFAULT NULL AFTER updated_at" );
        }

        $updated = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'updated_by' ) );
        if ( $updated !== 'updated_by' ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN updated_by BIGINT UNSIGNED DEFAULT NULL AFTER created_by" );
        }
    }

    public function down(): void {
        // Forward-only.
    }
};
