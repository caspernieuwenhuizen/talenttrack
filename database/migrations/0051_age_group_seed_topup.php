<?php
/**
 * Migration 0051 — top up `age_group` lookup if empty (#0063).
 *
 * The new-team wizard's BasicsStep reads `tt_lookups` for
 * `lookup_type = 'age_group'`. Some installs (early imports, demo
 * data resets, or hand-rolled databases) end up with this lookup
 * type empty, leaving the wizard's age-group dropdown blank.
 *
 * This migration seeds the standard youth-football age groups
 * **only when no rows exist for the type**. Existing values are
 * left untouched — clubs that already curated their age-group
 * vocabulary keep theirs.
 *
 * Idempotent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0051_age_group_seed_topup';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_lookups';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $existing = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE lookup_type = 'age_group'"
        );
        if ( $existing > 0 ) return; // someone already seeded; leave it

        $rows = [
            'U7', 'U8', 'U9', 'U10', 'U11', 'U12',
            'U13', 'U14', 'U15', 'U16', 'U17',
            'U18', 'U19', 'U21', 'U23', 'Senior',
        ];
        foreach ( $rows as $i => $name ) {
            $wpdb->insert( $table, [
                'club_id'     => 1,
                'lookup_type' => 'age_group',
                'name'        => $name,
                'sort_order'  => ( $i + 1 ) * 10,
            ] );
        }
    }

    public function down(): void {
        // No-op. Schema migrations are forward-only.
    }
};
