<?php
/**
 * Migration 0045 — PDP verdict columns for player-status integration
 * (#0057 Sprint 5).
 *
 * Adds three columns to `tt_pdp_verdicts`:
 *  - `system_recommended_status VARCHAR(16)` — the traffic-light colour
 *    captured at meeting time (green / amber / red / unknown).
 *  - `methodology_version_id VARCHAR(64)` — the methodology config id
 *    (`shipped` or `cfg:N`) under which the recommendation was made,
 *    so historical statuses can be reconstructed under their config.
 *  - `divergence_notes TEXT` — required free-text when the human
 *    decision differs from the system recommendation.
 *
 * Idempotent. No backfill — historical verdicts have no
 * system-recommended row, leave NULL.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0045_pdp_verdict_methodology_columns';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_pdp_verdicts";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $cols = [
            'system_recommended_status' => "VARCHAR(16) DEFAULT NULL",
            'methodology_version_id'    => "VARCHAR(64) DEFAULT NULL",
            'divergence_notes'          => "TEXT DEFAULT NULL",
        ];

        foreach ( $cols as $name => $defn ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table, $name
            ) );
            if ( $exists === null ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$defn}" );
            }
        }
    }
};
