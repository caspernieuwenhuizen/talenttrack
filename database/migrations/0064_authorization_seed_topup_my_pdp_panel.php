<?php
/**
 * Migration: 0064_authorization_seed_topup_my_pdp_panel
 *
 * v3.92.0 follow-up to #0079 — backfill the `my_pdp_panel`
 * tile-visibility entity that v3.92.0 added to
 * `config/authorization_seed.php`. Same pattern as
 * `0063_authorization_seed_topup_0079`: walk the seed and
 * `INSERT IGNORE` rows the matrix doesn't have yet.
 *
 * Why this is needed:
 *   - The Me-group "My PDP" tile previously used the data entity
 *     `pdp_file`, which coaches/HoD/scout legitimately read at
 *     team/global scope. With matrix-active installs, the matrix
 *     gate granted them the tile.
 *   - v3.92.0 disambiguates the tile via a new `my_pdp_panel`
 *     entity granted only to player (r[self]) and parent
 *     (r[player]). Without this migration the new entity exists in
 *     the seed file but not in `tt_authorization_matrix`, so the
 *     tile gate returns false for everyone — the player surface
 *     disappears for the people who legitimately use it.
 *
 * Idempotent. INSERT IGNORE on the unique key (persona, entity,
 * activity, scope_kind) leaves any operator-edited rows untouched
 * and only adds the new tuples.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0064_authorization_seed_topup_my_pdp_panel';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) return;

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        foreach ( $rows as $row ) {
            $wpdb->query( $wpdb->prepare(
                $sql,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }
    }
};
