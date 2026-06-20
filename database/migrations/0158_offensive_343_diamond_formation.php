<?php
/**
 * Migration 0158 — offensive 3-4-3 (diamond) formation template (#1477).
 *
 * Seeds a new system formation: GK + 3 CB + a midfield diamond
 * (DM / LCM / RCM / AM) + a front three (LW / ST / RW). Distinct from
 * the existing standard 3-4-3 (flat midfield). Idempotent on name;
 * top-ups existing installs. The Dutch label is handled by
 * `LabelTranslator::formationName()` (gettext), matching the other
 * seeded formations.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0158_offensive_343_diamond_formation';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_formation_templates";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $name = 'Offensive 3-4-3 (diamond)';
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE name = %s",
            $name
        ) );
        if ( $existing > 0 ) return;

        // pos: x 0..1 left→right, y 0..1 top(attack)→bottom(own goal).
        $slots = [
            [ 'label' => 'GK',  'pos' => [ 'x' => 0.50, 'y' => 0.95 ], 'side' => 'center', 'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.20, 'mental' => 0.20 ] ],
            [ 'label' => 'LCB', 'pos' => [ 'x' => 0.28, 'y' => 0.84 ], 'side' => 'left',   'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.30, 'mental' => 0.10 ] ],
            [ 'label' => 'CB',  'pos' => [ 'x' => 0.50, 'y' => 0.86 ], 'side' => 'center', 'weights' => [ 'technical' => 0.20, 'tactical' => 0.45, 'physical' => 0.25, 'mental' => 0.10 ] ],
            [ 'label' => 'RCB', 'pos' => [ 'x' => 0.72, 'y' => 0.84 ], 'side' => 'right',  'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.30, 'mental' => 0.10 ] ],
            [ 'label' => 'DM',  'pos' => [ 'x' => 0.50, 'y' => 0.66 ], 'side' => 'center', 'weights' => [ 'technical' => 0.30, 'tactical' => 0.45, 'physical' => 0.15, 'mental' => 0.10 ] ],
            [ 'label' => 'LCM', 'pos' => [ 'x' => 0.30, 'y' => 0.52 ], 'side' => 'left',   'weights' => [ 'technical' => 0.35, 'tactical' => 0.35, 'physical' => 0.20, 'mental' => 0.10 ] ],
            [ 'label' => 'RCM', 'pos' => [ 'x' => 0.70, 'y' => 0.52 ], 'side' => 'right',  'weights' => [ 'technical' => 0.35, 'tactical' => 0.35, 'physical' => 0.20, 'mental' => 0.10 ] ],
            [ 'label' => 'AM',  'pos' => [ 'x' => 0.50, 'y' => 0.40 ], 'side' => 'center', 'weights' => [ 'technical' => 0.40, 'tactical' => 0.35, 'physical' => 0.15, 'mental' => 0.10 ] ],
            [ 'label' => 'LW',  'pos' => [ 'x' => 0.20, 'y' => 0.20 ], 'side' => 'left',   'weights' => [ 'technical' => 0.40, 'tactical' => 0.25, 'physical' => 0.25, 'mental' => 0.10 ] ],
            [ 'label' => 'ST',  'pos' => [ 'x' => 0.50, 'y' => 0.18 ], 'side' => 'center', 'weights' => [ 'technical' => 0.40, 'tactical' => 0.25, 'physical' => 0.25, 'mental' => 0.10 ] ],
            [ 'label' => 'RW',  'pos' => [ 'x' => 0.80, 'y' => 0.20 ], 'side' => 'right',  'weights' => [ 'technical' => 0.40, 'tactical' => 0.25, 'physical' => 0.25, 'mental' => 0.10 ] ],
        ];

        $wpdb->insert( $table, [
            'name'            => $name,
            'formation_shape' => '3-4-3',
            'slots_json'      => wp_json_encode( $slots ),
            'is_seeded'       => 1,
        ] );
    }

    public function down(): void {
        // Forward-only.
    }
};
