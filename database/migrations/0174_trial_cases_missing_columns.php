<?php
/**
 * Migration 0174 — add the missing opened_by + overall_rating columns to
 * tt_trial_cases (#1840).
 *
 * Both columns are referenced by code — the trials list COUNT
 * (FrontendTrialsManageView), the trial standard reports
 * (FrontendStandardReportsView), and the case overall rating — but were
 * never created by a forward migration on installs that ran 0036 before
 * these columns were added to its CREATE statement. The result is
 * "Unknown column 'opened_by' / 'overall_rating'" database errors on the
 * trials list + reports, which halt the page before wp_footer and leave
 * the surface unstyled and formless.
 *
 * This forward ALTER repairs every install. opened_by is backfilled from
 * created_by — the user who opened the case. overall_rating matches the
 * DECIMAL(3,2) type 0036's CREATE statement already declares.
 *
 * Forward-only + idempotent: guarded by columnExists(); re-running is a
 * no-op.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0174_trial_cases_missing_columns';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_trial_cases';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        if ( ! $this->columnExists( $table, 'opened_by' ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN opened_by BIGINT UNSIGNED NULL DEFAULT NULL" );
            // Backfill from created_by — the actor who opened the case.
            $wpdb->query( "UPDATE {$table} SET opened_by = created_by WHERE opened_by IS NULL" );
        }

        if ( ! $this->columnExists( $table, 'overall_rating' ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN overall_rating DECIMAL(3,2) NULL DEFAULT NULL" );
        }
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
