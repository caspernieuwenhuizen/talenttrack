<?php
/**
 * Migration 0150 — top up the age_group lookup to the full canonical set (#1439).
 *
 * Installs seeded before the canonical list grew (migration 0001 + the
 * Activator seeded only U8, U10, U12, U14, U16, U19, Senior) are missing the
 * odd-numbered groups U7, U9, U11, U13, U15, U17, U18, U20, U21, U23. This
 * inserts the missing canonical names and normalises sort_order to age order
 * for every existing club. Idempotent: re-running inserts nothing and just
 * re-asserts sort_order.
 *
 * Age-group names are locale-invariant U-codes (U7 == U7 in every language);
 * only "Senior" carries a translation, seeded by the lookup-translation
 * backfill, so this migration writes no tt_translations rows.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0150_seed_missing_age_groups';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $lookups = "{$p}tt_lookups";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) {
            return;
        }

        // Canonical age groups in age order; value is the target sort_order.
        $canonical = [
            'U7' => 1, 'U8' => 2, 'U9' => 3, 'U10' => 4, 'U11' => 5, 'U12' => 6,
            'U13' => 7, 'U14' => 8, 'U15' => 9, 'U16' => 10, 'U17' => 11, 'U18' => 12,
            'U19' => 13, 'U20' => 14, 'U21' => 15, 'U23' => 16, 'Senior' => 17,
        ];

        // Per existing club so multi-tenant installs each get the full set.
        $club_ids = $wpdb->get_col(
            "SELECT DISTINCT club_id FROM {$lookups} WHERE lookup_type = 'age_group'"
        );
        if ( empty( $club_ids ) ) {
            $club_ids = [ 1 ];
        }

        foreach ( $club_ids as $club_id ) {
            $club_id  = (int) $club_id;
            $existing = array_map(
                'strval',
                (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT name FROM {$lookups} WHERE lookup_type = 'age_group' AND club_id = %d",
                    $club_id
                ) )
            );

            foreach ( $canonical as $name => $order ) {
                if ( in_array( $name, $existing, true ) ) {
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$lookups} SET sort_order = %d
                          WHERE lookup_type = 'age_group' AND name = %s AND club_id = %d",
                        $order, $name, $club_id
                    ) );
                } else {
                    $wpdb->insert( $lookups, [
                        'lookup_type' => 'age_group',
                        'name'        => $name,
                        'sort_order'  => $order,
                        'club_id'     => $club_id,
                    ] );
                }
            }
        }
    }
};
