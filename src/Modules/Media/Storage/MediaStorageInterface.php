<?php
namespace TT\Modules\Media\Storage;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MediaStorageInterface (#2590, epic #2589) — where media bytes live.
 *
 * The whole point of this interface is that `tt_media.storage_key` is
 * opaque. Callers receive a key from `store()` and hand it back to
 * `readStream()` / `delete()`; nothing outside an implementation may
 * parse it, join it onto a path, or turn it into a URL. That constraint
 * is what makes an object-storage adapter a one-class addition instead
 * of a rewrite (CLAUDE.md §4).
 *
 * Implementations are registered by their `storage_adapter` name, which
 * is stored per row — so an install that migrates to object storage can
 * keep serving the rows written by the previous adapter instead of
 * needing a big-bang move.
 */
interface MediaStorageInterface {

    /**
     * Adapter name, stored verbatim in `tt_media.storage_adapter`.
     * Must be stable — changing it orphans every existing row.
     */
    public function name(): string;

    /**
     * Take ownership of a file and return its opaque storage key.
     *
     * The source file is moved, not copied, when it is an upload temp
     * file. Implementations must not assume the caller keeps it around.
     *
     * @param string $source_path Absolute path to the file to store.
     * @param string $extension   Normalised, dot-less, lowercase.
     * @return string Opaque key. Empty string on failure.
     */
    public function store( string $source_path, string $extension ): string;

    /**
     * Open a read stream for a stored key.
     *
     * A stream rather than a string because a 200MB training clip must
     * not be loaded into PHP memory to be served.
     *
     * @return resource|null
     */
    public function readStream( string $key );

    /**
     * Total byte length of the stored object, or 0 when unknown.
     * Needed for Content-Length and Range arithmetic at delivery time.
     */
    public function size( string $key ): int;

    public function exists( string $key ): bool;

    public function delete( string $key ): bool;
}
