<?php
/**
 * Migration 0187 — backfill the `recycle_bin` matrix entity (#2020, epic #2018).
 *
 * #2020 adds the admin-only `recycle_bin` matrix entity to the seed file
 * (`config/authorization_seed.php`), granting academy_admin `rcd[global]`.
 * Without this migration the entity exists in the seed but not in the live
 * `tt_authorization_matrix` on already-installed sites, so once the matrix
 * is active the cap bridge (`tt_manage_recycle_bin → recycle_bin`) resolves
 * to false for everyone — silently revoking the admin-only access the raw
 * cap grants today.
 *
 * Scoped to the new entity only (same as 0179_authorization_seed_topup_
 * tournaments) so it never re-adds rows an operator deliberately removed for
 * other entities.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves any operator-edited rows
 * untouched and only adds the missing tuples.
 *
 * Pairs with 0186_recycle_bin_foundation (schema + config). Both ship in the
 * #2020 foundation and are run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0187_authorization_seed_topup_recycle_bin';
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
            if ( ( $row['entity'] ?? '' ) !== 'recycle_bin' ) {
                continue;
            }
            $this->exec( $wpdb->prepare(
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
