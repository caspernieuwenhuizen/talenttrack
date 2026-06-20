<?php
/**
 * Migration 0164 — nudge the "Offensive 3-4-3 (diamond)" back three up so
 * they clear the goalkeeper (#1525).
 *
 * Migration 0158 seeded the back three at y=0.84/0.86/0.84, only ~0.09 from
 * the GK at y=0.95 — they visually overlapped the keeper on the editor
 * pitch. 0158 is idempotent-on-name and won't re-run on installs that
 * already have the row, so this migration rewrites the seeded row's
 * `slots_json`, moving LCB/CB/RCB to y=0.80 (~0.15 clearance, matching the
 * standard flat 3-4-3). Only the back-three y values change; every other
 * slot is left untouched. Idempotent (re-running sets the same 0.80) and
 * guarded to the seeded system row so a club's hand-edited copy is never
 * clobbered.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0164_fix_offensive_343_back_line';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_formation_templates';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, slots_json FROM {$table} WHERE name = %s AND is_seeded = 1",
            'Offensive 3-4-3 (diamond)'
        ) );
        if ( ! $row || empty( $row->slots_json ) ) return;

        $slots = json_decode( (string) $row->slots_json, true );
        if ( ! is_array( $slots ) ) return;

        $back_three = [ 'LCB', 'CB', 'RCB' ];
        $changed    = false;
        foreach ( $slots as &$slot ) {
            if ( in_array( (string) ( $slot['label'] ?? '' ), $back_three, true ) ) {
                if ( ! isset( $slot['pos']['y'] ) || (float) $slot['pos']['y'] !== 0.80 ) {
                    $slot['pos']['y'] = 0.80;
                    $changed          = true;
                }
            }
        }
        unset( $slot );

        if ( ! $changed ) return;

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
