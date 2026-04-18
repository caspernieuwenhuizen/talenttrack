<?php
namespace TT\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MigrationRunner
 *
 * - Ensures tt_migrations tracking table exists.
 * - On legacy installs (v2.0.1 and earlier had no migrations system but
 *   already had all schema tables), marks the initial schema migration
 *   as already-applied so it doesn't re-run seed logic.
 * - Scans /database/migrations/ for *.php files, sorted alphabetically.
 *   File naming convention: NNNN_description.php (e.g. 0001_initial_schema.php).
 * - Skips any migration whose name already exists in tt_migrations.
 * - Runs new migrations, records success with applied_at timestamp.
 *
 * Safe to call on both activation and every boot — idempotent by design.
 */
class MigrationRunner {

    private const TRACKING_TABLE = 'tt_migrations';

    /** @var string */
    private $migrations_dir;

    public function __construct( ?string $migrations_dir = null ) {
        $this->migrations_dir = $migrations_dir ?? ( defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'database/migrations' : '' );
    }

    public function run(): void {
        if ( ! $this->migrations_dir || ! is_dir( $this->migrations_dir ) ) {
            return;
        }

        $this->ensureTrackingTable();
        $this->handleLegacyInstall();

        $applied = $this->getAppliedMigrations();
        $files   = $this->scanMigrationFiles();

        foreach ( $files as $file ) {
            $name = basename( $file, '.php' );
            if ( in_array( $name, $applied, true ) ) {
                continue;
            }
            $this->runOne( $file, $name );
        }
    }

    /**
     * Create tt_migrations table if it doesn't exist.
     */
    private function ensureTrackingTable(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TRACKING_TABLE;
        $c     = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration VARCHAR(191) NOT NULL,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_migration (migration)
        ) $c;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Backward-compat: if tt_lookups exists (from pre-migration v2.0.1 install)
     * but tt_migrations is empty, register 0001_initial_schema as already-applied
     * so we don't re-seed lookups/config on an existing install.
     */
    private function handleLegacyInstall(): void {
        global $wpdb;
        $tracking = $wpdb->prefix . self::TRACKING_TABLE;
        $lookups  = $wpdb->prefix . 'tt_lookups';

        $tracking_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tracking}" );
        if ( $tracking_count > 0 ) {
            return; // Already tracking migrations — nothing to do.
        }

        $lookups_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) === $lookups;
        if ( ! $lookups_exists ) {
            return; // Fresh install — first migration will create everything.
        }

        // Legacy install detected: record the initial schema as applied.
        $wpdb->insert( $tracking, [
            'migration'  => '0001_initial_schema',
            'applied_at' => current_time( 'mysql' ),
        ]);
    }

    /** @return string[] */
    private function getAppliedMigrations(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TRACKING_TABLE;
        /** @var string[] $rows */
        $rows = $wpdb->get_col( "SELECT migration FROM {$table}" );
        return $rows ?: [];
    }

    /** @return string[] */
    private function scanMigrationFiles(): array {
        $files = glob( $this->migrations_dir . '/*.php' );
        if ( ! $files ) return [];
        sort( $files );
        return $files;
    }

    private function runOne( string $file, string $name ): void {
        /** @var mixed $maybe_migration */
        $maybe_migration = require $file;

        if ( ! $maybe_migration instanceof Migration ) {
            return; // File didn't return a Migration instance — skip safely.
        }

        try {
            $maybe_migration->up();
            $this->markApplied( $name );
        } catch ( \Throwable $e ) {
            // Fail quiet-but-visible: record to error log. Do not halt plugin boot.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[TalentTrack] Migration "%s" failed: %s', $name, $e->getMessage() ) );
            }
        }
    }

    private function markApplied( string $name ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::TRACKING_TABLE, [
            'migration'  => $name,
            'applied_at' => current_time( 'mysql' ),
        ]);
    }
}
