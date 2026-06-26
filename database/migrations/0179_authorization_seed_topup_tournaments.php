<?php
/**
 * Migration: 0179_authorization_seed_topup_tournaments
 *
 * #1943 — backfill the new admin-only `tournaments` matrix entity into
 * `tt_authorization_matrix`. The seed now grants academy_admin
 * `rcd[global]` on `tournaments`; without this migration the entity
 * exists in the seed file but not in the live matrix, so the cap bridge
 * (`tt_view_tournaments` / `tt_edit_tournaments` → `tournaments`) would
 * resolve to false for everyone once the matrix is active — silently
 * revoking the admin-only access the raw caps grant today.
 *
 * Scoped to the new entity only (same as
 * `0165_authorization_seed_topup_my_evaluations_panel`) so it never
 * re-adds rows an operator deliberately removed for other entities.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves any operator-edited
 * rows untouched and only adds the missing tuples.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0179_authorization_seed_topup_tournaments';
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
            if ( ( $row['entity'] ?? '' ) !== 'tournaments' ) {
                continue;
            }
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
