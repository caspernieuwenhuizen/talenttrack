<?php
/**
 * Migration 0026 — Authorization matrix table (#0033 Sprint 1).
 *
 * Creates `tt_authorization_matrix`, the persona × activity × entity ×
 * scope_kind table that drives MatrixGate read decisions. Seeded from
 * the shipped defaults at /config/authorization_seed.php.
 *
 * Idempotent — re-running this migration will not duplicate seed rows
 * (guarded on row count). To force a re-seed after editing the seed
 * file, call MatrixRepository::reseed() — never re-run the migration.
 *
 * Sprint 1 ships this table + the read API only. Nothing reads from
 * MatrixGate yet; existing dispatchers continue to call
 * `current_user_can()` as before. Sprint 2 wires the legacy
 * `user_has_cap` filter through MatrixGate; Sprint 8 ships the apply
 * toggle that lets a club switch to matrix-driven enforcement.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0026_authorization_matrix';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_authorization_matrix" ) ) !== "{$p}tt_authorization_matrix" ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_authorization_matrix (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                persona VARCHAR(40) NOT NULL,
                entity VARCHAR(64) NOT NULL,
                activity VARCHAR(20) NOT NULL,
                scope_kind VARCHAR(20) NOT NULL,
                module_class VARCHAR(191) NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_lookup (persona, entity, activity, scope_kind),
                KEY idx_persona (persona),
                KEY idx_module (module_class)
            ) {$charset}" );
        }

        $this->seedIfEmpty();
    }

    /**
     * Seed the table from the shipped defaults if the table is empty.
     *
     * Why "if empty" instead of "INSERT IGNORE": admins can edit rows
     * after the initial seed (Sprint 3 admin UI), and we don't want
     * a re-run of this migration to revert their changes. To force a
     * reset after editing the seed file, callers use
     * MatrixRepository::reseed() which truncates first.
     */
    private function seedIfEmpty(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_authorization_matrix" );
        if ( $count > 0 ) {
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) {
            return;
        }

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $wpdb->insert( "{$p}tt_authorization_matrix", [
                'persona'      => (string) $row['persona'],
                'entity'       => (string) $row['entity'],
                'activity'     => (string) $row['activity'],
                'scope_kind'   => (string) $row['scope_kind'],
                'module_class' => (string) $row['module_class'],
                'is_default'   => 1,
            ] );
        }
    }
};
