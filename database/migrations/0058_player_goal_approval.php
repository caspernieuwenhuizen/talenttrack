<?php
/**
 * Migration 0058 — player-created goal approval flow (#0077 M10).
 *
 * Adds the `Pending Approval` row to the goal_status lookup so player-
 * created goals can land in a holding state until a coach approves
 * (status flips to 'pending') or rejects (status flips to 'cancelled').
 *
 * Idempotent. INSERT IGNORE-style guard via lookup-existence check.
 * Translation comes through tt_lookups.meta.translations like every
 * other lookup row; the seed value is the English `name`.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0058_player_goal_approval';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = $p . 'tt_lookups';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE lookup_type = %s AND name = %s LIMIT 1",
            'goal_status',
            'Pending Approval'
        ) );
        if ( $existing ) return;

        $next_sort = (int) $wpdb->get_var(
            "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$table} WHERE lookup_type = 'goal_status'"
        );

        $wpdb->insert( $table, [
            'lookup_type' => 'goal_status',
            'name'        => 'Pending Approval',
            'sort_order'  => $next_sort,
        ] );
    }
};
