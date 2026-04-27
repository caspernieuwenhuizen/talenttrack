<?php
/**
 * Migration 0029 — Authorization Sprints 3 + 4 + 5 (#0033).
 *
 * Three tables added in one migration so Sprints 3 / 4 / 5 land in a
 * single PR per the v3.22.0+ compression pattern:
 *
 * 1. `tt_authorization_changelog` (Sprint 3) — append-only audit row
 *    for every matrix edit. Lives separately from `tt_audit_log`
 *    until #0021 ships and absorbs it. Carries the (persona, entity,
 *    activity, scope_kind, before, after, actor, ts) tuple.
 *
 * 2. `tt_module_state` (Sprint 5) — per-module enabled flag. Seeded
 *    from `config/modules.php` on first run (every existing module →
 *    enabled = 1 to match today's static `=> true`). The Modules
 *    admin tab toggles rows here; ModuleRegistry::isEnabled() reads
 *    from this table at boot time.
 *
 * 3. (Sprint 4 carries no schema change — TileRegistry is in-memory
 *    only and seeded from each module's `boot()`.)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0029_authorization_sprints_3_4_5';
    }

    public function up(): void {
        $this->createChangelogTable();
        $this->createModuleStateTable();
        $this->seedModuleStateFromConfig();
    }

    private function createChangelogTable(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_authorization_changelog" ) ) === "{$p}tt_authorization_changelog" ) {
            return;
        }

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_authorization_changelog (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            persona VARCHAR(40) NOT NULL,
            entity VARCHAR(64) NOT NULL,
            activity VARCHAR(20) NOT NULL,
            scope_kind VARCHAR(20) NOT NULL,
            change_type VARCHAR(20) NOT NULL,
            before_value TINYINT(1) DEFAULT NULL,
            after_value TINYINT(1) DEFAULT NULL,
            actor_user_id BIGINT UNSIGNED NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_actor_time (actor_user_id, created_at),
            KEY idx_persona (persona),
            KEY idx_entity (entity)
        ) {$charset}" );
    }

    private function createModuleStateTable(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_module_state" ) ) === "{$p}tt_module_state" ) {
            return;
        }

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_module_state (
            module_class VARCHAR(191) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (module_class)
        ) {$charset}" );
    }

    /**
     * Seed `tt_module_state` from `config/modules.php` — every module
     * defaults to enabled=1 to match today's static behavior. Only
     * inserts rows that don't already exist (idempotent).
     */
    private function seedModuleStateFromConfig(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_module_state";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $modules_path = defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'config/modules.php' : __DIR__ . '/../../config/modules.php';
        if ( ! is_readable( $modules_path ) ) return;

        $modules = require $modules_path;
        if ( ! is_array( $modules ) ) return;

        foreach ( $modules as $class => $enabled ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE module_class = %s",
                $class
            ) );
            if ( $existing > 0 ) continue;
            $wpdb->insert( $table, [
                'module_class' => $class,
                'enabled'      => $enabled ? 1 : 0,
                'updated_at'   => current_time( 'mysql' ),
            ] );
        }
    }
};
