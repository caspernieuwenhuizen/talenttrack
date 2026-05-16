<?php
/**
 * Migration 0098 — #0093 tournament planner. Seeds the two new
 * lookup vocabularies the module consumes:
 *
 *   tournament_formation       — supported on-pitch shapes. Each row's
 *                                meta carries `lines` (the player-count
 *                                breakdown including GK) and
 *                                `slot_labels` (per-line slot codes).
 *                                Stored as JSON so the planner grid can
 *                                lay rows out by formation line.
 *
 *   tournament_opponent_level  — 4-tier difficulty pill. meta.color
 *                                drives the visible pill colour on the
 *                                match card + planner header.
 *
 * Both are operator-extensible from the Lookups admin — clubs running
 * an 8v8 shape we didn't seed can add their own without code changes.
 *
 * Idempotent. Skips rows whose (lookup_type, name) already exists.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0098_tournament_lookups_seed';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $formations = [
            // 11v11.
            [
                'name'       => '1-4-3-3',
                'nl_label'   => '1-4-3-3',
                'sort_order' => 10,
                'meta'       => [
                    'lines'       => [ 1, 4, 3, 3 ],
                    'slot_labels' => [
                        [ 'GK' ],
                        [ 'RB', 'RCB', 'LCB', 'LB' ],
                        [ 'RCM', 'CM', 'LCM' ],
                        [ 'RW', 'ST', 'LW' ],
                    ],
                ],
            ],
            [
                'name'       => '1-4-4-2',
                'nl_label'   => '1-4-4-2',
                'sort_order' => 20,
                'meta'       => [
                    'lines'       => [ 1, 4, 4, 2 ],
                    'slot_labels' => [
                        [ 'GK' ],
                        [ 'RB', 'RCB', 'LCB', 'LB' ],
                        [ 'RM', 'RCM', 'LCM', 'LM' ],
                        [ 'RST', 'LST' ],
                    ],
                ],
            ],
            [
                'name'       => '1-3-4-3',
                'nl_label'   => '1-3-4-3',
                'sort_order' => 30,
                'meta'       => [
                    'lines'       => [ 1, 3, 4, 3 ],
                    'slot_labels' => [
                        [ 'GK' ],
                        [ 'RCB', 'CB', 'LCB' ],
                        [ 'RM', 'RCM', 'LCM', 'LM' ],
                        [ 'RW', 'ST', 'LW' ],
                    ],
                ],
            ],
            [
                'name'       => '1-3-5-2',
                'nl_label'   => '1-3-5-2',
                'sort_order' => 40,
                'meta'       => [
                    'lines'       => [ 1, 3, 5, 2 ],
                    'slot_labels' => [
                        [ 'GK' ],
                        [ 'RCB', 'CB', 'LCB' ],
                        [ 'RWB', 'RCM', 'CM', 'LCM', 'LWB' ],
                        [ 'RST', 'LST' ],
                    ],
                ],
            ],
            [
                'name'       => '1-4-2-3-1',
                'nl_label'   => '1-4-2-3-1',
                'sort_order' => 50,
                'meta'       => [
                    'lines'       => [ 1, 4, 2, 3, 1 ],
                    'slot_labels' => [
                        [ 'GK' ],
                        [ 'RB', 'RCB', 'LCB', 'LB' ],
                        [ 'RDM', 'LDM' ],
                        [ 'RAM', 'CAM', 'LAM' ],
                        [ 'ST' ],
                    ],
                ],
            ],
            // 8v8 — typical U10-U11 small-sided shape.
            [
                'name'       => '1-2-3-2',
                'nl_label'   => '1-2-3-2',
                'sort_order' => 60,
                'meta'       => [
                    'lines'       => [ 1, 2, 3, 2 ],
                    'slot_labels' => [
                        [ 'GK' ],
                        [ 'RCB', 'LCB' ],
                        [ 'RM', 'CM', 'LM' ],
                        [ 'RST', 'LST' ],
                    ],
                ],
            ],
            // 7v7 — U8/U9.
            [
                'name'       => '1-2-3-1',
                'nl_label'   => '1-2-3-1',
                'sort_order' => 70,
                'meta'       => [
                    'lines'       => [ 1, 2, 3, 1 ],
                    'slot_labels' => [
                        [ 'GK' ],
                        [ 'RCB', 'LCB' ],
                        [ 'RM', 'CM', 'LM' ],
                        [ 'ST' ],
                    ],
                ],
            ],
            // 6v6 — U6/U7.
            [
                'name'       => '1-1-3-1',
                'nl_label'   => '1-1-3-1',
                'sort_order' => 80,
                'meta'       => [
                    'lines'       => [ 1, 1, 3, 1 ],
                    'slot_labels' => [
                        [ 'GK' ],
                        [ 'CB' ],
                        [ 'RM', 'CM', 'LM' ],
                        [ 'ST' ],
                    ],
                ],
            ],
        ];

        foreach ( $formations as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_lookups WHERE lookup_type = %s AND name = %s",
                'tournament_formation',
                $row['name']
            ) );
            if ( $existing > 0 ) continue;

            $wpdb->insert( "{$p}tt_lookups", [
                'lookup_type'  => 'tournament_formation',
                'name'         => $row['name'],
                'description'  => '',
                'meta'         => (string) wp_json_encode( $row['meta'] ),
                'translations' => (string) wp_json_encode( [
                    'nl_NL' => [ 'name' => $row['nl_label'], 'description' => '' ],
                ] ),
                'sort_order'   => $row['sort_order'],
            ] );
        }

        $levels = [
            [
                'name'       => 'weaker',
                'nl_label'   => 'Zwakker',
                'sort_order' => 10,
                'meta'       => [ 'color' => '#16a34a' ],   // green
            ],
            [
                'name'       => 'equal',
                'nl_label'   => 'Gelijkwaardig',
                'sort_order' => 20,
                'meta'       => [ 'color' => '#5b6e75' ],   // neutral grey
            ],
            [
                'name'       => 'stronger',
                'nl_label'   => 'Sterker',
                'sort_order' => 30,
                'meta'       => [ 'color' => '#f59e0b' ],   // amber
            ],
            [
                'name'       => 'much_stronger',
                'nl_label'   => 'Veel sterker',
                'sort_order' => 40,
                'meta'       => [ 'color' => '#dc2626' ],   // red
            ],
        ];

        foreach ( $levels as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_lookups WHERE lookup_type = %s AND name = %s",
                'tournament_opponent_level',
                $row['name']
            ) );
            if ( $existing > 0 ) continue;

            $wpdb->insert( "{$p}tt_lookups", [
                'lookup_type'  => 'tournament_opponent_level',
                'name'         => $row['name'],
                'description'  => '',
                'meta'         => (string) wp_json_encode( $row['meta'] ),
                'translations' => (string) wp_json_encode( [
                    'nl_NL' => [ 'name' => $row['nl_label'], 'description' => '' ],
                ] ),
                'sort_order'   => $row['sort_order'],
            ] );
        }
    }
};
