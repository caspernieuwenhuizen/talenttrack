<?php
/**
 * Migration 0078 — `tt_team_blueprints.share_token_seed` (#0068 Phase 4).
 *
 * Adds a `VARCHAR(32)` column carrying the per-blueprint secret used
 * to sign the public share-link's HMAC. Default empty string; on
 * first share-link build the repository lazily seeds with the row's
 * `uuid` (cheap, idempotent — avoids touching every existing blueprint
 * at migration time). "Rotate share link" sets a new
 * `wp_generate_password(16, false, false)` value, invalidating every
 * prior URL for that blueprint.
 *
 * The HMAC payload is `(blueprint_id, uuid, share_token_seed)` keyed
 * on `wp_salt('auth')`. Mirrors the #0081 `ParentConfirmationController`
 * sign pattern.
 *
 * Idempotent. `SHOW COLUMNS` guard skips the ADD on already-migrated
 * installs.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0078_team_blueprint_share_token_seed';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_team_blueprints";

        // Defensive: blueprints table only exists since #0068 Phase 1
        // (migration 0070). On installs that skipped activations
        // entirely the table may be missing; bail rather than error.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $exists ) return;

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            'share_token_seed'
        ) );
        if ( $col === 'share_token_seed' ) return;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN share_token_seed VARCHAR(32) NOT NULL DEFAULT '' AFTER notes" );
    }
};
