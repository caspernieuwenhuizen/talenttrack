<?php
namespace TT\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MigrationRunner (v2.6.4 rewrite)
 *
 * Key changes from v2.6.3:
 *   - loadMigrationFromFile() wraps the file inclusion in a closure so each
 *     call is a fresh execution scope. This sidesteps a class of issues where
 *     the file had already been required earlier in the request lifecycle,
 *     causing require to return int(1) instead of the object.
 *   - Error messages now include the actual type/value returned by require,
 *     so future "file not runnable" errors tell you WHY.
 *   - Boot-time auto-run behavior is DISABLED by default. Migrations are now
 *     applied exclusively via the Migrations admin page. This eliminates the
 *     whole category of "boot-time silently ran or skipped something" bugs.
 *     Kernel no longer calls run() on boot; it's purely an admin-triggered
 *     action now.
 */
class MigrationRunner {

    private const TRACKING_TABLE = 'tt_migrations';

    /** @var string */
    private $migrations_dir;

    public function __construct( ?string $migrations_dir = null ) {
        $this->migrations_dir = $migrations_dir ?? ( defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'database/migrations' : '' );
    }

    /**
     * Apply every pending migration. Admin-triggered, not boot-triggered.
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
            if ( in_array( $name, $applied, true ) ) continue;
            $results[] = $this->runFile( $file, $name );
        }
        return $results;
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
     * Load the migration file in an isolated closure scope, capturing both the
     * return value AND any errors. Using a closure guarantees we actually
     * evaluate the file's `return` statement in THIS call, regardless of
     * prior inclusions in the request.
     *
     * Accepts:
     *   (a) object extending TT\Infrastructure\Database\Migration (legacy pattern)
     *   (b) object with a public up() method (v2.6.2+ pattern)
     *
     * @return array{ok:bool, migration?:object, error?:string}
     */
    private function loadMigrationFromFile( string $file ): array {
        // Read contents and evaluate via eval-safe isolated scope.
        // We use a closure that include's the file; include (not require)
        // re-evaluates even if previously loaded in some cases, and the
        // closure scope means `use` statements in the included file still
        // resolve against the global namespace correctly.
        $loader = function () use ( $file ) {
            return include $file;
        };

        // Capture any output (stray whitespace, notices) so it doesn't
        // break the admin page.
        ob_start();
        $maybe = null;
        $thrown = null;
        try {
            $maybe = $loader();
        } catch ( \Throwable $e ) {
            $thrown = $e;
        }
        $stray_output = ob_get_clean();

        if ( $thrown !== null ) {
            return [
                'ok'    => false,
                'error' => sprintf(
                    'Exception while loading migration file: %s (in %s:%d)',
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
                    'Migration file returned an object of class %s, but it neither extends Migration nor has an up() method.',
                    get_class( $maybe )
                ),
            ];
        }

        // Diagnostic: describe what we actually got back.
        $type = gettype( $maybe );
        $repr = is_scalar( $maybe ) ? var_export( $maybe, true ) : $type;
        $hint = '';
        if ( $maybe === 1 || $maybe === true ) {
            $hint = ' (This often means the file was already included earlier in the request and PHP returned its default success value instead of re-running it. Try deactivating and reactivating the plugin once, then retry.)';
        } elseif ( $maybe === null ) {
            $hint = ' (The file executed but did not contain a top-level `return` statement.)';
        }
        $stray = $stray_output !== '' ? ' Unexpected output from migration file: "' . trim( mb_substr( $stray_output, 0, 200 ) ) . '".' : '';

        return [
            'ok'    => false,
            'error' => sprintf(
                'Migration file returned %s (value: %s) instead of an object.%s%s',
                $type,
                $repr,
                $hint,
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
