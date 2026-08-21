<?php
namespace TT\Modules\Media\Storage;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LocalPrivateStorage (#2590, epic #2589) — media bytes on the local disk,
 * outside the reach of a direct HTTP request.
 *
 * Files land under `uploads/tt-media/` in a two-level hex shard, and the
 * directory is created with a deny-all `.htaccess` plus an `index.php`.
 * That mirrors `Modules\Backup\Destinations\LocalDestination::ensureDir()`,
 * which has protected backup archives the same way since the backup module
 * shipped.
 *
 * **The `.htaccess` is defence in depth, not the access boundary.** It
 * does nothing on nginx. The boundary is the REST delivery endpoint, which
 * checks capabilities before it opens a stream. This class deliberately
 * exposes no way to build a public URL — there is nothing to leak, because
 * there is no URL.
 *
 * Sharding by the first four hex characters of the key keeps any single
 * directory from collecting tens of thousands of entries, which is where
 * ext4 and NTFS both start to slow down on a busy academy's third season.
 */
final class LocalPrivateStorage implements MediaStorageInterface {

    public const NAME = 'local_private';

    private const DIR_NAME = 'tt-media';

    /**
     * Extensions this store will write or read, independent of what
     * ingest decided. Two layers rather than one: ingest whitelists by
     * sniffed content type, and the store refuses to name a file anything
     * outside this list even if a caller asks it to.
     *
     * The list matters because these files sit inside `uploads/`. A
     * `.phtml` or `.php` under a document root that the deny-all guard
     * does not cover — an nginx install — is remote code execution, so
     * the safe move is for such a name to be unwritable and unreadable
     * here rather than merely unlikely.
     */
    private const SAFE_EXTENSIONS = [ 'jpg', 'png', 'webp', 'mp4', 'mov' ];

    public function name(): string {
        return self::NAME;
    }

    public function store( string $source_path, string $extension ): string {
        if ( $source_path === '' || ! is_file( $source_path ) ) return '';

        $root = self::ensureRoot();
        if ( $root === '' ) return '';

        $extension = strtolower( preg_replace( '/[^a-z0-9]/i', '', $extension ) ?? '' );
        if ( ! in_array( $extension, self::SAFE_EXTENSIONS, true ) ) return '';

        $stem = bin2hex( random_bytes( 16 ) );
        $key  = substr( $stem, 0, 2 ) . '/' . substr( $stem, 2, 2 ) . '/' . $stem . '.' . $extension;

        $target = $root . '/' . $key;
        if ( ! wp_mkdir_p( dirname( $target ) ) ) return '';

        // move_uploaded_file() would be wrong here: ingest has already
        // validated and rewritten the file (EXIF strip), so by this point
        // it is an ordinary temp file we own, not the raw upload.
        if ( ! @rename( $source_path, $target ) ) {
            if ( ! @copy( $source_path, $target ) ) return '';
            @unlink( $source_path );
        }

        @chmod( $target, 0644 );
        return $key;
    }

    public function readStream( string $key ) {
        $path = $this->pathFor( $key );
        if ( $path === '' || ! is_file( $path ) ) return null;
        $handle = @fopen( $path, 'rb' );
        return $handle === false ? null : $handle;
    }

    public function size( string $key ): int {
        $path = $this->pathFor( $key );
        if ( $path === '' || ! is_file( $path ) ) return 0;
        return (int) @filesize( $path );
    }

    public function exists( string $key ): bool {
        $path = $this->pathFor( $key );
        return $path !== '' && is_file( $path );
    }

    public function delete( string $key ): bool {
        $path = $this->pathFor( $key );
        if ( $path === '' ) return false;
        if ( ! is_file( $path ) ) return true;
        return (bool) @unlink( $path );
    }

    // Helpers

    /**
     * Absolute path for a key, or empty string when the key is not a
     * well-formed one. Every filesystem entry point routes through here,
     * so a malformed or hostile key never reaches a file operation.
     */
    private function pathFor( string $key ): string {
        if ( ! preg_match( self::keyPattern(), $key ) ) return '';
        $root = self::root();
        return $root === '' ? '' : $root . '/' . $key;
    }

    /**
     * Keys are exactly `ab/cd/<32 hex>.<safe ext>` and nothing else.
     * Matching the whole string with `\z` rather than `$` matters: `$`
     * also matches before a trailing newline, which would let
     * `<valid key>\n../../evil` through.
     */
    private static function keyPattern(): string {
        return '#\A[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{32}\.(?:'
            . implode( '|', self::SAFE_EXTENSIONS )
            . ')\z#';
    }

    /** Absolute path of the media root, or '' when uploads is unavailable. */
    public static function root(): string {
        $uploads = wp_upload_dir( null, false );
        if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) return '';
        return untrailingslashit( (string) $uploads['basedir'] ) . '/' . self::DIR_NAME;
    }

    /**
     * Create the media root with its access guards. Idempotent — safe to
     * call on every store.
     */
    public static function ensureRoot(): string {
        $dir = self::root();
        if ( $dir === '' ) return '';
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return '';

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
}
