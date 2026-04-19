<?php
namespace TT\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MigrationRunner (v2.6.3 rewrite)
 *
 * Same responsibilities as before:
 *   - Ensure tt_migrations tracking table exists
 *   - Scan /database/migrations/*.php
 *   - Apply new migrations, mark applied_at
 *   - Handle legacy (pre-migration-system) installs
 *
 * What changed in v2.6.3:
 *   - pending() returns the list of pending migration names (for UI display)
 *   - all() returns the full state: applied + pending + files
 *   - runAll() and runOne() now RETURN structured results (name, ok, error, duration_ms)
 *     instead of just silently recording/logging. Admin UI uses these.
 *   - loadMigrationFromFile() accepts BOTH the old pattern (class extends Migration)
 *     AND the simpler pattern (anonymous class with an up(\wpdb $wpdb) method).
 *     This fixes v2.6.2's migration 0004 which used the simpler pattern and was
 *     silently skipped by the old runner's strict instanceof check.
 */
class MigrationRunner {

    private const TRACKING_TABLE = 'tt_migrations';

    /** @var string */
    private $migrations_dir;

    public function __construct( ?string $migrations_dir = null ) {
        $this->migrations_dir = $migrations_dir ?? ( defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'database/migrations' : '' );
    }

    /**
     * Auto-apply any pending migrations (original boot/activation behavior).
     * Returns per-migration results.
     *
     * @return array<int, array{name:string, ok:bool, error:?string, duration_ms:int, skipped:bool}>
     */
    public function run(): array {
        if ( ! $this->migrations_dir || ! is_dir( $this->migrations_dir ) ) {
            return [];
        }

        $this->ensureTrackingTable();
        $this->handleLegacyInstall();

        $applied = $this->getAppliedMigrations();
        $files   = $this->scanMigrationFiles();

        $results = [];
        foreach ( $files as $file ) {
            $name = basename( $file, '.php' );
            if ( in_array( $name, $applied, true ) ) {
                continue; // Already applied, don't include in results
            }
            $results[] = $this->runFile( $file, $name );
        }
        return $results;
    }

    /**
     * Run a single named migration. Used by the Migrations admin page.
     *
     * @return array{name:string, ok:bool, error:?string, duration_ms:int, skipped:bool}
     */
    public function runOne( string $name ): array {
        if ( ! $this->migrations_dir || ! is_dir( $this->migrations_dir ) ) {
            return $this->errorResult( $name, 'Migrations directory not found.' );
        }

        $this->ensureTrackingTable();

        // Already applied?
        if ( in_array( $name, $this->getAppliedMigrations(), true ) ) {
            return [ 'name' => $name, 'ok' => true, 'error' => null, 'duration_ms' => 0, 'skipped' => true ];
        }

        $file = $this->migrations_dir . '/' . $name . '.php';
        if ( ! file_exists( $file ) ) {
            return $this->errorResult( $name, "Migration file not found: $file" );
        }

        return $this->runFile( $file, $name );
    }

    /**
     * @return array{
     *   applied: array<int, array{name:string, applied_at:string}>,
     *   pending: string[],
     *   missing_files: string[],
     *   tracking_table_exists: bool,
     *   migrations_dir: string
     * }
     */
    public function inspect(): array {
        $tracking_exists = $this->trackingTableExists();
        $applied_raw = $tracking_exists ? $this->getAppliedMigrationsDetailed() : [];
        $files_by_name = [];
        foreach ( $this->scanMigrationFiles() as $file ) {
            $files_by_name[ basename( $file, '.php' ) ] = $file;
        }

        $applied_names = array_column( $applied_raw, 'name' );
        $pending = array_values( array_diff( array_keys( $files_by_name ), $applied_names ) );
        sort( $pending );

        // Migrations the DB thinks are applied but whose source file is gone.
        $missing_files = array_values( array_diff( $applied_names, array_keys( $files_by_name ) ) );
        sort( $missing_files );

        return [
            'applied'               => $applied_raw,
            'pending'               => $pending,
            'missing_files'         => $missing_files,
            'tracking_table_exists' => $tracking_exists,
            'migrations_dir'        => $this->migrations_dir,
        ];
    }

    /* ═══ internals ═══ */

    /**
     * @return array{name:string, ok:bool, error:?string, duration_ms:int, skipped:bool}
     */
    private function runFile( string $file, string $name ): array {
        $start = microtime( true );
        try {
            $migration = $this->loadMigrationFromFile( $file );
            if ( $migration === null ) {
                return $this->errorResult(
                    $name,
                    "Migration file does not return a runnable migration. Expected either a Migration instance or an object with an up(\\wpdb) method."
                );
            }

            global $wpdb;
            // Suppress wpdb's default error output during migration — we capture it below.
            $prev_show_errors = $wpdb->hide_errors();

            if ( $migration instanceof Migration ) {
                $migration->up();
            } else {
                $migration->up( $wpdb );
            }

            $last_error = (string) $wpdb->last_error;
            if ( $prev_show_errors ) $wpdb->show_errors();

            if ( $last_error !== '' ) {
                return $this->errorResult( $name, $last_error, $start );
            }

            $this->markApplied( $name );
            return [
                'name'        => $name,
                'ok'          => true,
                'error'       => null,
                'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
                'skipped'     => false,
            ];
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[TalentTrack] Migration "%s" failed: %s', $name, $e->getMessage() ) );
            }
            return $this->errorResult( $name, $e->getMessage(), $start );
        }
    }

    /**
     * Load a migration file and return something we can run.
     * Accepts:
     *   (a) object extending TT\Infrastructure\Database\Migration (legacy pattern)
     *   (b) object with a public up(\wpdb) method (v2.6.2+ pattern)
     * Returns null if neither.
     */
    private function loadMigrationFromFile( string $file ) {
        $maybe = require $file;
        if ( ! is_object( $maybe ) ) {
            return null;
        }
        if ( $maybe instanceof Migration ) {
            return $maybe;
        }
        if ( method_exists( $maybe, 'up' ) ) {
            return $maybe;
        }
        return null;
    }

    private function errorResult( string $name, string $error, ?float $start = null ): array {
        return [
            'name'        => $name,
            'ok'          => false,
            'error'       => $error,
            'duration_ms' => $start ? (int) round( ( microtime( true ) - $start ) * 1000 ) : 0,
            'skipped'     => false,
        ];
    }

    private function trackingTableExists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TRACKING_TABLE;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

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

    private function handleLegacyInstall(): void {
        global $wpdb;
        $tracking = $wpdb->prefix . self::TRACKING_TABLE;
        $lookups  = $wpdb->prefix . 'tt_lookups';

        $tracking_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tracking}" );
        if ( $tracking_count > 0 ) return;

        $lookups_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) === $lookups;
        if ( ! $lookups_exists ) return;

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

    /** @return array<int, array{name:string, applied_at:string}> */
    private function getAppliedMigrationsDetailed(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TRACKING_TABLE;
        $rows = $wpdb->get_results( "SELECT migration, applied_at FROM {$table} ORDER BY migration ASC", ARRAY_A );
        if ( ! $rows ) return [];
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'name'       => (string) ( $r['migration'] ?? '' ),
                'applied_at' => (string) ( $r['applied_at'] ?? '' ),
            ];
        }
        return $out;
    }

    /** @return string[] */
    private function scanMigrationFiles(): array {
        $files = glob( $this->migrations_dir . '/*.php' );
        if ( ! $files ) return [];
        sort( $files );
        return $files;
    }

    private function markApplied( string $name ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::TRACKING_TABLE, [
            'migration'  => $name,
            'applied_at' => current_time( 'mysql' ),
        ]);
    }
}
