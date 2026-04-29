<?php
/**
 * Migration 0047 — Add `draft` to the activity_status lookup (#0061).
 *
 * The wizard's Cancel button can save a partially-filled activity as
 * `draft` instead of discarding the work. The status is normally
 * hidden from the user-facing dropdowns (the form treats it as an
 * internal state); admins still see it under Configuration → Lookups
 * if they want to clean up old drafts.
 *
 * Idempotent. Skip if the row already exists.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0047_activity_status_draft';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_lookups";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE lookup_type = %s AND name = %s",
            'activity_status',
            'draft'
        ) );
        if ( $existing > 0 ) return;

        $wpdb->insert( $table, [
            'lookup_type'  => 'activity_status',
            'name'         => 'draft',
            'description'  => 'Internal — the wizard saves cancelled in-progress activities here.',
            'meta'         => (string) wp_json_encode( [ 'color' => '#5b6e75', 'is_locked' => 1, 'hidden_from_form' => 1 ] ),
            'translations' => (string) wp_json_encode( [ 'nl_NL' => [ 'name' => 'Concept', 'description' => '' ] ] ),
            'sort_order'   => 5,
        ] );
    }
};
