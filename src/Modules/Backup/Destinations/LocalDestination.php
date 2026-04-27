<?php
namespace TT\Modules\Backup\Destinations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Backup\BackupSettings;

/**
 * LocalDestination — writes backup files to wp-content/uploads/talenttrack-backups/.
 *
 * On first use, creates the directory plus an `index.php` and `.htaccess`
 * to block direct browser access. Files are pure `.json.gz` — they
 * carry no attacker-useful surface beyond the data they contain, but
 * defense in depth is cheap.
 *
 * Retention: configurable in BackupSettings (default 30); after each
 * store(), older files beyond the limit are purged.
 */
class LocalDestination implements BackupDestinationInterface {

    private const DIR_NAME = 'talenttrack-backups';

    public function key(): string {
        return 'local';
    }

    public function label(): string {
        return __( 'Local disk', 'talenttrack' );
    }

    public function isEnabled(): bool {
        $settings = BackupSettings::get();
        return ! empty( $settings['destinations']['local']['enabled'] );
    }

    public function store( string $backup_path, array $metadata ): StoreResult {
        $dir = self::ensureDir();
        if ( $dir === '' ) {
            return StoreResult::failure( 'Could not create backup directory' );
        }
        if ( ! is_readable( $backup_path ) ) {
            return StoreResult::failure( 'Source backup file not readable' );
        }

        $filename = basename( $backup_path );
        // The serializer-generated filename already includes a UTC timestamp
        // and the preset slug; we keep it as-is so the listing is stable.
        $target = trailingslashit( $dir ) . $filename;

        if ( ! @copy( $backup_path, $target ) ) {
            return StoreResult::failure( 'Could not copy backup to destination' );
        }

        $this->purgeOld();

        return StoreResult::success( $filename, [
            'path'  => $target,
            'size'  => (int) @filesize( $target ),
        ] );
    }

    public function listBackups(): array {
        $dir = self::dir();
        if ( $dir === '' || ! is_dir( $dir ) ) return [];

        $out  = [];
        $iter = new \DirectoryIterator( $dir );
        foreach ( $iter as $f ) {
            if ( $f->isDot() || ! $f->isFile() ) continue;
            $name = (string) $f->getFilename();
            if ( substr( $name, -8 ) !== '.json.gz' ) continue;

            // Derive timestamp + preset from the filename when possible.
            // Fallback to mtime if the format is non-standard.
            $created_at = '';
            $preset     = '';
            if ( preg_match( '/^talenttrack-backup-(\d{8})-(\d{6})-([a-z0-9_-]+)\.json\.gz$/i', $name, $m ) ) {
                $iso = sprintf( '%s-%s-%sT%s:%s:%sZ', substr( $m[1], 0, 4 ), substr( $m[1], 4, 2 ), substr( $m[1], 6, 2 ), substr( $m[2], 0, 2 ), substr( $m[2], 2, 2 ), substr( $m[2], 4, 2 ) );
                $created_at = $iso;
                $preset     = (string) $m[3];
            } else {
                $created_at = gmdate( 'c', (int) $f->getMTime() );
                $preset     = '';
            }

            $out[] = [
                'id'         => $name,
                'filename'   => $name,
                'size'       => (int) $f->getSize(),
                'created_at' => $created_at,
                'preset'     => $preset,
            ];
        }
        // Newest first.
        usort( $out, function ( $a, $b ) { return strcmp( (string) $b['created_at'], (string) $a['created_at'] ); } );
        return $out;
    }

    public function fetchLocalPath( string $id ): string {
        $dir = self::dir();
        if ( $dir === '' ) return '';
        // Normalize: $id is the bare filename. Reject path traversal.
        $name = basename( $id );
        $path = trailingslashit( $dir ) . $name;
        return is_readable( $path ) ? $path : '';
    }

    public function purge( string $id ): bool {
        $path = $this->fetchLocalPath( $id );
        if ( $path === '' ) return true;
        return @unlink( $path );
    }

    // Helpers

    /**
     * Absolute filesystem path of the backups directory. Returns empty
     * string if uploads dir is unwritable.
     */
    public static function dir(): string {
        $uploads = wp_upload_dir( null, false );
        if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) return '';
        return trailingslashit( (string) $uploads['basedir'] ) . self::DIR_NAME;
    }

    /**
     * Create the directory (if missing) plus index.php + .htaccess
     * blocking direct access. Idempotent.
     */
    public static function ensureDir(): string {
        $dir = self::dir();
        if ( $dir === '' ) return '';
        if ( ! is_dir( $dir ) ) {
            if ( ! wp_mkdir_p( $dir ) ) return '';
        }
        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Order Deny,Allow\nDeny from all\n" );
        }
        return $dir;
    }

    private function purgeOld(): void {
        $settings  = BackupSettings::get();
        $retention = max( 1, (int) ( $settings['retention'] ?? 30 ) );
        $list      = $this->listBackups();
        if ( count( $list ) <= $retention ) return;
        $to_purge = array_slice( $list, $retention );
        foreach ( $to_purge as $row ) {
            $this->purge( (string) ( $row['id'] ?? '' ) );
        }
    }
}
