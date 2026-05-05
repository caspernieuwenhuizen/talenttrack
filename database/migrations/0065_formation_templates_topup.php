<?php
/**
 * Migration: 0065_formation_templates_topup
 *
 * #0068 — team chemistry rebuild. Adds three additional formation
 * shapes alongside the existing four 4-3-3 play-style variants seeded
 * in migration 0032:
 *
 *   - 4-4-2 (Neutral)
 *   - 3-5-2 (Neutral)
 *   - 4-2-3-1 (Neutral)
 *
 * Each shape ships as a single Neutral-style template; play-style
 * variants of these shapes are a follow-up if Casper asks. The new
 * templates make the chemistry view actually responsive to formation
 * choice — v1's four templates were all 4-3-3 with identical slot
 * positions, only differing in per-slot weights, which made the
 * "different formations look the same" complaint literally true.
 *
 * `pos` is normalised {x, y} on the pitch (0,0 = top-left,
 * 1,1 = bottom-right) — `PitchSvg` reads it. Weights within each slot
 * sum to 1.0; cross-slot weights are independent. Per-slot weight
 * profiles follow the Neutral 4-3-3 archetype the v1 seed established
 * (defender-heavy on physical/tactical, midfield balanced, forwards
 * technical-leaning) so the existing CompatibilityEngine maths
 * stays meaningful.
 *
 * `INSERT IGNORE` on (formation_shape, name) so re-running the
 * migration doesn't duplicate. Existing seeded templates are left
 * untouched. Idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0065_formation_templates_topup';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_formation_templates";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            // tt_formation_templates doesn't exist yet (migration 0032
            // hasn't run). The initial seed in 0032 will pick up these
            // shapes when it runs — but only if 0032 itself is updated
            // to include them. For now this migration only top-ups
            // existing installs.
            return;
        }

        foreach ( self::shippedTemplates() as $tpl ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE name = %s",
                $tpl['name']
            ) );
            if ( $existing > 0 ) continue;

            $wpdb->insert( $table, [
                'name'            => $tpl['name'],
                'formation_shape' => $tpl['formation_shape'],
                'slots_json'      => wp_json_encode( $tpl['slots'] ),
                'is_seeded'       => 1,
            ] );
        }
    }

    /**
     * @return list<array{name:string, formation_shape:string, slots:list<array<string,mixed>>}>
     */
    private static function shippedTemplates(): array {
        return [
            [
                'name'            => 'Neutral 4-4-2',
                'formation_shape' => '4-4-2',
                'slots'           => [
                    [ 'label' => 'GK',  'pos' => [ 'x' => 0.50, 'y' => 0.95 ], 'side' => 'center', 'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.20, 'mental' => 0.20 ] ],
                    [ 'label' => 'LB',  'pos' => [ 'x' => 0.15, 'y' => 0.78 ], 'side' => 'left',   'weights' => [ 'technical' => 0.25, 'tactical' => 0.30, 'physical' => 0.30, 'mental' => 0.15 ] ],
                    [ 'label' => 'LCB', 'pos' => [ 'x' => 0.38, 'y' => 0.82 ], 'side' => 'left',   'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.30, 'mental' => 0.10 ] ],
                    [ 'label' => 'RCB', 'pos' => [ 'x' => 0.62, 'y' => 0.82 ], 'side' => 'right',  'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.30, 'mental' => 0.10 ] ],
                    [ 'label' => 'RB',  'pos' => [ 'x' => 0.85, 'y' => 0.78 ], 'side' => 'right',  'weights' => [ 'technical' => 0.25, 'tactical' => 0.30, 'physical' => 0.30, 'mental' => 0.15 ] ],
                    [ 'label' => 'LM',  'pos' => [ 'x' => 0.15, 'y' => 0.50 ], 'side' => 'left',   'weights' => [ 'technical' => 0.35, 'tactical' => 0.25, 'physical' => 0.30, 'mental' => 0.10 ] ],
                    [ 'label' => 'LCM', 'pos' => [ 'x' => 0.38, 'y' => 0.55 ], 'side' => 'left',   'weights' => [ 'technical' => 0.30, 'tactical' => 0.35, 'physical' => 0.25, 'mental' => 0.10 ] ],
                    [ 'label' => 'RCM', 'pos' => [ 'x' => 0.62, 'y' => 0.55 ], 'side' => 'right',  'weights' => [ 'technical' => 0.30, 'tactical' => 0.35, 'physical' => 0.25, 'mental' => 0.10 ] ],
                    [ 'label' => 'RM',  'pos' => [ 'x' => 0.85, 'y' => 0.50 ], 'side' => 'right',  'weights' => [ 'technical' => 0.35, 'tactical' => 0.25, 'physical' => 0.30, 'mental' => 0.10 ] ],
                    [ 'label' => 'LST', 'pos' => [ 'x' => 0.40, 'y' => 0.20 ], 'side' => 'left',   'weights' => [ 'technical' => 0.40, 'tactical' => 0.25, 'physical' => 0.25, 'mental' => 0.10 ] ],
                    [ 'label' => 'RST', 'pos' => [ 'x' => 0.60, 'y' => 0.20 ], 'side' => 'right',  'weights' => [ 'technical' => 0.40, 'tactical' => 0.25, 'physical' => 0.25, 'mental' => 0.10 ] ],
                ],
            ],
            [
                'name'            => 'Neutral 3-5-2',
                'formation_shape' => '3-5-2',
                'slots'           => [
                    [ 'label' => 'GK',  'pos' => [ 'x' => 0.50, 'y' => 0.95 ], 'side' => 'center', 'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.20, 'mental' => 0.20 ] ],
                    [ 'label' => 'LCB', 'pos' => [ 'x' => 0.28, 'y' => 0.82 ], 'side' => 'left',   'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.30, 'mental' => 0.10 ] ],
                    [ 'label' => 'CB',  'pos' => [ 'x' => 0.50, 'y' => 0.85 ], 'side' => 'center', 'weights' => [ 'technical' => 0.20, 'tactical' => 0.45, 'physical' => 0.25, 'mental' => 0.10 ] ],
                    [ 'label' => 'RCB', 'pos' => [ 'x' => 0.72, 'y' => 0.82 ], 'side' => 'right',  'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.30, 'mental' => 0.10 ] ],
                    [ 'label' => 'LWB', 'pos' => [ 'x' => 0.10, 'y' => 0.55 ], 'side' => 'left',   'weights' => [ 'technical' => 0.30, 'tactical' => 0.25, 'physical' => 0.35, 'mental' => 0.10 ] ],
                    [ 'label' => 'LCM', 'pos' => [ 'x' => 0.32, 'y' => 0.50 ], 'side' => 'left',   'weights' => [ 'technical' => 0.35, 'tactical' => 0.35, 'physical' => 0.20, 'mental' => 0.10 ] ],
                    [ 'label' => 'CM',  'pos' => [ 'x' => 0.50, 'y' => 0.55 ], 'side' => 'center', 'weights' => [ 'technical' => 0.35, 'tactical' => 0.40, 'physical' => 0.15, 'mental' => 0.10 ] ],
                    [ 'label' => 'RCM', 'pos' => [ 'x' => 0.68, 'y' => 0.50 ], 'side' => 'right',  'weights' => [ 'technical' => 0.35, 'tactical' => 0.35, 'physical' => 0.20, 'mental' => 0.10 ] ],
                    [ 'label' => 'RWB', 'pos' => [ 'x' => 0.90, 'y' => 0.55 ], 'side' => 'right',  'weights' => [ 'technical' => 0.30, 'tactical' => 0.25, 'physical' => 0.35, 'mental' => 0.10 ] ],
                    [ 'label' => 'LST', 'pos' => [ 'x' => 0.40, 'y' => 0.20 ], 'side' => 'left',   'weights' => [ 'technical' => 0.40, 'tactical' => 0.25, 'physical' => 0.25, 'mental' => 0.10 ] ],
                    [ 'label' => 'RST', 'pos' => [ 'x' => 0.60, 'y' => 0.20 ], 'side' => 'right',  'weights' => [ 'technical' => 0.40, 'tactical' => 0.25, 'physical' => 0.25, 'mental' => 0.10 ] ],
                ],
            ],
            [
                'name'            => 'Neutral 4-2-3-1',
                'formation_shape' => '4-2-3-1',
                'slots'           => [
                    [ 'label' => 'GK',  'pos' => [ 'x' => 0.50, 'y' => 0.95 ], 'side' => 'center', 'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.20, 'mental' => 0.20 ] ],
                    [ 'label' => 'LB',  'pos' => [ 'x' => 0.15, 'y' => 0.78 ], 'side' => 'left',   'weights' => [ 'technical' => 0.25, 'tactical' => 0.30, 'physical' => 0.30, 'mental' => 0.15 ] ],
                    [ 'label' => 'LCB', 'pos' => [ 'x' => 0.38, 'y' => 0.82 ], 'side' => 'left',   'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.30, 'mental' => 0.10 ] ],
                    [ 'label' => 'RCB', 'pos' => [ 'x' => 0.62, 'y' => 0.82 ], 'side' => 'right',  'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.30, 'mental' => 0.10 ] ],
                    [ 'label' => 'RB',  'pos' => [ 'x' => 0.85, 'y' => 0.78 ], 'side' => 'right',  'weights' => [ 'technical' => 0.25, 'tactical' => 0.30, 'physical' => 0.30, 'mental' => 0.15 ] ],
                    [ 'label' => 'LDM', 'pos' => [ 'x' => 0.38, 'y' => 0.62 ], 'side' => 'left',   'weights' => [ 'technical' => 0.30, 'tactical' => 0.40, 'physical' => 0.25, 'mental' => 0.05 ] ],
                    [ 'label' => 'RDM', 'pos' => [ 'x' => 0.62, 'y' => 0.62 ], 'side' => 'right',  'weights' => [ 'technical' => 0.30, 'tactical' => 0.40, 'physical' => 0.25, 'mental' => 0.05 ] ],
                    [ 'label' => 'LAM', 'pos' => [ 'x' => 0.22, 'y' => 0.40 ], 'side' => 'left',   'weights' => [ 'technical' => 0.40, 'tactical' => 0.25, 'physical' => 0.25, 'mental' => 0.10 ] ],
                    [ 'label' => 'CAM', 'pos' => [ 'x' => 0.50, 'y' => 0.36 ], 'side' => 'center', 'weights' => [ 'technical' => 0.45, 'tactical' => 0.30, 'physical' => 0.15, 'mental' => 0.10 ] ],
                    [ 'label' => 'RAM', 'pos' => [ 'x' => 0.78, 'y' => 0.40 ], 'side' => 'right',  'weights' => [ 'technical' => 0.40, 'tactical' => 0.25, 'physical' => 0.25, 'mental' => 0.10 ] ],
                    [ 'label' => 'ST',  'pos' => [ 'x' => 0.50, 'y' => 0.15 ], 'side' => 'center', 'weights' => [ 'technical' => 0.40, 'tactical' => 0.25, 'physical' => 0.25, 'mental' => 0.10 ] ],
                ],
            ],
        ];
    }
};
