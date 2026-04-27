<?php
/**
 * Migration 0033 — Activity type lookup (#0050).
 *
 * Seeds three rows in `tt_lookups[lookup_type='activity_type']` so the
 * activity Type dropdown becomes admin-extensible. Existing rows in
 * `tt_activities.activity_type_key` already match these names so no
 * data migration is needed.
 *
 * Each seed row carries:
 *   - meta.is_locked = 1            — admin UI hides Delete on locked rows
 *   - meta.workflow_template_slug   — when set, that template's
 *                                     expandTrigger() runs on save; otherwise
 *                                     no auto-task. Game points at
 *                                     post_game_evaluation; training and
 *                                     other are blank.
 *   - translations[nl_NL].name      — Dutch label
 *
 * Idempotent — re-running leaves existing rows alone (matches on
 * lookup_type + name).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0033_activity_type_lookup';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = [
            [
                'name'        => 'training',
                'description' => 'Default activity type — practices.',
                'meta'        => [ 'is_locked' => 1 ],
                'nl_label'    => 'Training',
                'sort_order'  => 10,
            ],
            [
                'name'        => 'game',
                'description' => 'Match against another team. Spawns post-game evaluation tasks.',
                'meta'        => [
                    'is_locked'              => 1,
                    'workflow_template_slug' => 'post_game_evaluation',
                ],
                'nl_label'    => 'Wedstrijd',
                'sort_order'  => 20,
            ],
            [
                'name'        => 'other',
                'description' => 'Anything else — team-building day, club meeting, off-roster training.',
                'meta'        => [ 'is_locked' => 1 ],
                'nl_label'    => 'Overig',
                'sort_order'  => 30,
            ],
        ];

        foreach ( $rows as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_lookups WHERE lookup_type = %s AND name = %s",
                'activity_type',
                $row['name']
            ) );
            if ( $existing > 0 ) {
                continue;
            }

            $wpdb->insert( "{$p}tt_lookups", [
                'lookup_type'  => 'activity_type',
                'name'         => $row['name'],
                'description'  => $row['description'],
                'meta'         => (string) wp_json_encode( $row['meta'] ),
                'translations' => (string) wp_json_encode( [
                    'nl_NL' => [ 'name' => $row['nl_label'], 'description' => '' ],
                ] ),
                'sort_order'   => $row['sort_order'],
            ] );
        }
    }
};
