<?php
namespace TT\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MigrationRunner (v2.6.5)
 *
 * Fix for v2.6.4 diagnostic finding: PHP's include/require tracks file
 * inclusion globally per-request. Once a migration file has been included
 * (typically by the boot-time runner in Kernel::boot), subsequent include
 * calls return int(1) instead of re-executing the file's `return` statement.
 *
 * v2.6.5 sidesteps this by reading the file contents and evaluating via
 * eval(). This is the rare legitimate use of eval: we explicitly want a
 * fresh evaluation scope every time, and PHP's include-tracking fights us.
 *
 * Safety notes:
 *   - Migration files are bundled with the plugin, not user input; no
 *     injection surface.
 *   - We strip the leading <?php tag before eval since eval expects
 *     statements, not file content.
 *   - Errors from eval (parse errors, fatal errors) are caught via
 *     ParseError and Throwable so the admin page can display them.
 */
class MigrationRunner {

    private const TRACKING_TABLE = 'tt_migrations';

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
     * Load and evaluate a migration file. Uses eval() because PHP's include
     * tracking means a previously-included file won't re-execute its return
     * statement. See class docblock for context.
     *
     * @return array{ok:bool, migration?:object, error?:string}
     */
    private function loadMigrationFromFile( string $file ): array {
        $contents = @file_get_contents( $file );
        if ( $contents === false ) {
            return [ 'ok' => false, 'error' => "Could not read migration file: $file" ];
        }

        // Strip leading PHP open tag (and any whitespace/BOM) so eval gets clean code.
        $contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents ); // strip BOM
        $contents = preg_replace( '/^\s*<\?php\s*/', '', $contents, 1 );
        // Strip optional trailing PHP close tag if present.
        $contents = preg_replace( '/\s*\?' . '>\s*$/', '', $contents );

        ob_start();
        $maybe = null;
        $thrown = null;
        try {
            // eval returns the value from `return` statements at top level.
            // Phpstan ignore — intentional use of eval for fresh execution.
            /** @noinspection PhpUnusedLocalVariableInspection */
            $maybe = eval( $contents );
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
                    'Error evaluating migration: %s (%s:%d)',
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
                'Migration evaluation returned %s (value: %s) instead of an object.%s',
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
