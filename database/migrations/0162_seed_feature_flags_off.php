<?php
/**
 * Migration 0162 — default `cohort_transitions` and `team_chemistry`
 * OFF (#1485).
 *
 * These two surfaces ship disabled. Fresh installs pick this up here;
 * existing installs get the same INSERT IGNORE so a club that never
 * touched the toggle lands on the off state too. Idempotent — re-running
 * leaves an operator's later choice untouched (a row they flipped on
 * already exists, so INSERT IGNORE is a no-op).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0162_seed_feature_flags_off';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_feature_state';

        $sql = "INSERT IGNORE INTO {$table} (feature_key, club_id, enabled) VALUES (%s, 1, 0)";
        foreach ( [ 'cohort_transitions', 'team_chemistry' ] as $key ) {
            $wpdb->query( $wpdb->prepare( $sql, $key ) );
        }
    }

    public function down(): void {
        // Forward-only.
    }
};
