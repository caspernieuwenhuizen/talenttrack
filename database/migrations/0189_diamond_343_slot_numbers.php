<?php
/**
 * Migration 0189 — slot numbers for the 3-4-3 diamond template (#2099).
 *
 * The match-prep pitch positions players by `slot_number` (1..11). Until
 * now it read those positions from a hardcoded shape-keyed table, so every
 * template sharing the `3-4-3` shape string collapsed onto the same FLAT
 * midfield — the "Offensive 3-4-3 (diamond)" template (migration 0158)
 * rendered as a line instead of a diamond, because its real geometry lives
 * in `slots_json`, which the pitch never read.
 *
 * To make `slots_json` authoritative the renderer needs a `slot_number`
 * per slot. The diamond's seeded `slots_json` carries position labels +
 * coordinates but no numbers; this migration adds a `num` to each slot by
 * label, keeping the back three (5/3/2) and front three (11/9/7) aligned
 * with the flat 3-4-3 and assigning the diamond midfield AM=10, LCM=8,
 * RCM=6, DM=4. Coordinates are read from the live row (not hardcoded) so
 * the numbering composes with the back-line fix in migration 0164.
 *
 * Idempotent: re-running re-applies the same numbers. Forward-only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0189_diamond_343_slot_numbers';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_formation_templates';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Position label → match-prep slot number. Back/front lines match the
        // flat 3-4-3; the diamond midfield gets 10/8/6/4 (AM/LCM/RCM/DM).
        $num_by_label = [
            'GK'  => 1,
            'LCB' => 5,
            'CB'  => 3,
            'RCB' => 2,
            'DM'  => 4,
            'LCM' => 8,
            'RCM' => 6,
            'AM'  => 10,
            'LW'  => 11,
            'ST'  => 9,
            'RW'  => 7,
        ];

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, slots_json FROM {$table} WHERE name = %s LIMIT 1",
            'Offensive 3-4-3 (diamond)'
        ) );
        if ( ! $row ) return;

        $slots = json_decode( (string) $row->slots_json, true );
        if ( ! is_array( $slots ) ) return;

        foreach ( $slots as &$slot ) {
            if ( ! is_array( $slot ) ) continue;
            $label = (string) ( $slot['label'] ?? '' );
            if ( isset( $num_by_label[ $label ] ) ) {
                $slot['num'] = $num_by_label[ $label ];
            }
        }
        unset( $slot );

        $wpdb->update(
            $table,
            [ 'slots_json' => wp_json_encode( $slots ) ],
            [ 'id' => (int) $row->id ]
        );
    }

    public function down(): void {
        // Forward-only.
    }
};
