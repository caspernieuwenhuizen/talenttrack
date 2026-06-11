<?php
namespace TT\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MigrationRunner (v2.10.1)
 *
 * v2.10.1 replaces the eval()-based loader with `include` inside an
 * isolated closure scope. Rationale: eval()'d code runs in the global
 * namespace regardless of the file's `namespace` declaration, and `use`
 * statements at the top of an eval'd string are silently ignored. This
 * meant migration files whose anonymous class extended `Migration` (i.e.
 * the short name, resolved via `use TT\Infrastructure\Database\Migration`)
 * got `Migration` resolved to `\Migration`, which either failed outright
 * or — on some hosts — produced an object that didn't satisfy
 * `instanceof Migration`, sending the runner down a fallback code path.
 *
 * The runner already filters out applied migrations before calling
 * runFile, so the original eval() rationale (PHP's per-request
 * include-once tracking) is moot: runFile is never called twice for
 * the same file in a single request. `include` with a closure scope is
 * both simpler and correct.
 *
 * Previous (v2.6.5) design notes retained for history:
 *   v2.6.5 used eval() to sidestep PHP's include-once behavior. eval
 *   is also a legitimate tool in general, but the downsides above make
 *   it the wrong tool for this particular job.
 */
class MigrationRunner {

    private const TRACKING_TABLE = 'tt_migrations';

    /**
     * Option holding the failures of the most recent run as
     * [ ['name' => …, 'error' => …], … ]. Cleared by any run that
     * ends with zero failures. SchemaStatus reads it to keep the
     * schema flagged pending and show the errors (#1346).
     */
    public const FAILURES_OPTION = 'tt_migration_failures';

    /** @var string */
    private $migrations_dir;

    public function __construct( ?string $migrations_dir = null ) {
        $this->migrations_dir = $migrations_dir ?? ( defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'database/migrations' : '' );
    }

    /**
     * @return array<int, array{name:string, ok:bool, error:?string, duration_ms:int, skipped:bool}>
     */
    public function run(): array {
        if ( ! $this->migrations_dir || ! is_dir( $this->migrations_dir ) ) {
            return [];
        }

        global $wpdb;

        // Two requests racing right after a plugin update must not run
        // the same migration concurrently — multi-statement DDL is not
        // transactional, and seed-inserts without unique keys would
        // double up. Non-blocking: the loser skips, the winner's
        // tracking rows make the next pass a no-op.
        $lock_name = $wpdb->prefix . 'tt_migrations_run';
        $acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
        if ( (string) $acquired !== '1' ) {
            return [];
        }

        try {
            $this->ensureTrackingTable();
            $this->handleLegacyInstall();

            $applied = $this->getAppliedMigrations();
            $files   = $this->scanMigrationFiles();

            $results = [];
            foreach ( $files as $file ) {
                $name = basename( $file, '.php' );
                if ( in_array( $name, $applied, true ) ) continue;
                $results[] = $this->runFile( $file, $name );
            }

            $this->recordFailures( $results );
            return $results;
        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    /**
     * Persist failed results to FAILURES_OPTION so the admin notice can
     * surface them; clear the option when the run ended clean. A failed
     * migration is never marked applied, so it re-enters $results on
     * the next run — an empty failure set here means nothing is broken.
     *
     * @param array<int, array{name:string, ok:bool, error:?string}> $results
     */
    private function recordFailures( array $results ): void {
        $failures = [];
        foreach ( $results as $r ) {
            if ( empty( $r['ok'] ) ) {
                $failures[] = [
                    'name'  => (string) ( $r['name'] ?? '' ),
                    'error' => (string) ( $r['error'] ?? '' ),
                ];
            }
        }
        if ( $failures !== [] ) {
            update_option( self::FAILURES_OPTION, $failures, false );
        } else {
            delete_option( self::FAILURES_OPTION );
        }
    }

    /**
     * @return array{name:string, ok:bool, error:?string, duration_ms:int, skipped:bool}
     */
    public function runOne( string $name ): array {
        if ( ! $this->migrations_dir || ! is_dir( $this->migrations_dir ) ) {
            return $this->errorResult( $name, 'Migrations directory not found.' );
        }
        $this->ensureTrackingTable();

        if ( in_array( $name, $this->getAppliedMigrations(), true ) ) {
            return [ 'name' => $name, 'ok' => true, 'error' => null, 'duration_ms' => 0, 'skipped' => true ];
        }

        $file = $this->migrations_dir . '/' . $name . '.php';
        if ( ! file_exists( $file ) ) {
            return $this->errorResult( $name, "Migration file not found: $file" );
        }
        $result = $this->runFile( $file, $name );
        $this->syncFailureRecord( $result );
        return $result;
    }

    /**
     * Single-run counterpart of recordFailures(): update or clear just
     * this migration's entry in FAILURES_OPTION.
     *
     * @param array{name:string, ok:bool, error:?string} $result
     */
    private function syncFailureRecord( array $result ): void {
        $failures = get_option( self::FAILURES_OPTION, [] );
        if ( ! is_array( $failures ) ) $failures = [];
        $name = (string) ( $result['name'] ?? '' );

        $failures = array_values( array_filter( $failures, function ( $f ) use ( $name ) {
            return is_array( $f ) && ( $f['name'] ?? '' ) !== $name;
        } ) );
        if ( empty( $result['ok'] ) ) {
            $failures[] = [ 'name' => $name, 'error' => (string) ( $result['error'] ?? '' ) ];
        }

        if ( $failures !== [] ) {
            update_option( self::FAILURES_OPTION, $failures, false );
        } else {
            delete_option( self::FAILURES_OPTION );
        }
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

    // internals

    private function runFile( string $file, string $name ): array {
        $start = microtime( true );
        try {
            $load = $this->loadMigrationFromFile( $file );
            if ( ! $load['ok'] ) {
                return $this->errorResult( $name, $load['error'], $start );
            }
            $migration = $load['migration'];

            global $wpdb;
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
            return $this->errorResult( $name, $e->getMessage(), $start );
        }
    }

    /**
     * Load a migration file via `include` inside an isolated closure
     * scope. Returns the object produced by the file's top-level
     * `return` statement.
     *
     * Rationale for `include` (not `eval`): PHP's `use` statements are
     * processed at file-compile time and are ignored inside eval'd
     * strings, which breaks the common `use TT\Infrastructure\Database\Migration;
     * return new class extends Migration { ... }` pattern used by every
     * migration file in this plugin.
     *
     * @return array{ok:bool, migration?:object, error?:string}
     */
    private function loadMigrationFromFile( string $file ): array {
        if ( ! is_readable( $file ) ) {
            return [ 'ok' => false, 'error' => "Could not read migration file: $file" ];
        }

        // Closure gives us an isolated variable scope so the migration
        // file's top-level `return` doesn't pollute the runner. The
        // closure is bound to the current object so $this inside the
        // include resolves cleanly, but in practice migration files
        // don't reference $this — they return an anonymous class.
        $loader = function ( string $migration_file ) {
            return include $migration_file;
        };

        $thrown = null;
        ob_start();
        $maybe = null;
        try {
            $maybe = $loader( $file );
        } catch ( \ParseError $e ) {
            $thrown = $e;
        } catch ( \Throwable $e ) {
            $thrown = $e;
        }
        $stray_output = ob_get_clean();

        if ( $thrown !== null ) {
            return [
                'ok'    => false,
                'error' => sprintf(
                    'Error loading migration: %s (%s:%d)',
                    $thrown->getMessage(),
                    basename( $thrown->getFile() ),
                    $thrown->getLine()
                ),
            ];
        }

        if ( is_object( $maybe ) ) {
            if ( $maybe instanceof Migration ) {
                return [ 'ok' => true, 'migration' => $maybe ];
            }
            if ( method_exists( $maybe, 'up' ) ) {
                return [ 'ok' => true, 'migration' => $maybe ];
            }
            return [
                'ok'    => false,
                'error' => sprintf(
                    'Migration returned an object of class %s, but it neither extends Migration nor has an up() method.',
                    get_class( $maybe )
                ),
            ];
        }

        $type = gettype( $maybe );
        $repr = is_scalar( $maybe ) ? var_export( $maybe, true ) : $type;
        $stray = $stray_output !== '' ? ' Unexpected output: "' . trim( mb_substr( $stray_output, 0, 200 ) ) . '".' : '';

        return [
            'ok'    => false,
            'error' => sprintf(
                'Migration file returned %s (value: %s) instead of an object.%s',
                $type,
                $repr,
                $stray
            ),
        ];
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
        // Ignore non-migration files like WordPress's index.php placeholder.
        // Real migrations follow the NNNN_name.php convention.
        $files = array_values( array_filter( $files, function ( string $f ): bool {
            $name = basename( $f, '.php' );
            if ( $name === 'index' ) return false;
            // Enforce NNNN_ prefix (four digits, underscore).
            return (bool) preg_match( '/^\d{4}_/', $name );
        } ) );
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
