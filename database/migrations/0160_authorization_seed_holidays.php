<?php
/**
 * Migration 0160 — backfill the `holidays` authorization-matrix rows (#1480).
 *
 * The original seeder (0026) only seeds an empty table, so the new
 * `holidays` entity grants added to `config/authorization_seed.php`
 * never reach existing installs. This additively `INSERT IGNORE`s only
 * the `holidays` rows — a brand-new entity, so it can't collide with or
 * overwrite any existing (admin-edited) matrix row. Idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0160_authorization_seed_holidays';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_authorization_matrix";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return; // 0026 hasn't run yet.
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) return;

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        foreach ( $rows as $row ) {
            if ( ( $row['entity'] ?? '' ) !== 'holidays' ) continue;
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

    public function down(): void {
        // Forward-only.
    }
};
