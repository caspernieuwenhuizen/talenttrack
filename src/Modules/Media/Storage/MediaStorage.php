<?php
namespace TT\Modules\Media\Storage;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MediaStorage (#2590, epic #2589) — resolves the adapter for a row.
 *
 * Rows store the adapter they were written with, so an install that
 * later moves to object storage keeps serving its existing files through
 * the adapter that wrote them instead of needing every blob migrated
 * before the switch. `default()` is what new uploads use.
 *
 * The `tt_media_storage_adapters` filter is the extension point a future
 * object-storage adapter registers through — it does not need to touch
 * this class.
 */
final class MediaStorage {

    /** @var array<string, MediaStorageInterface>|null */
    private static $adapters = null;

    /** Adapter new uploads are written with. */
    public static function default(): MediaStorageInterface {
        $name = (string) apply_filters( 'tt_media_default_storage_adapter', LocalPrivateStorage::NAME );
        return self::for( $name );
    }

    /**
     * Adapter for a stored row. Falls back to the local adapter when a
     * row names something this install no longer has registered — the
     * file is very likely still on disk, and refusing to look would turn
     * a config drift into a broken gallery.
     */
    public static function for( string $name ): MediaStorageInterface {
        $all = self::all();
        return $all[ $name ] ?? $all[ LocalPrivateStorage::NAME ];
    }

    /** @return array<string, MediaStorageInterface> */
    public static function all(): array {
        if ( self::$adapters !== null ) return self::$adapters;

        $local     = new LocalPrivateStorage();
        $adapters  = [ $local->name() => $local ];
        $filtered  = apply_filters( 'tt_media_storage_adapters', $adapters );

        $valid = [];
        foreach ( (array) $filtered as $key => $adapter ) {
            if ( $adapter instanceof MediaStorageInterface ) $valid[ (string) $key ] = $adapter;
        }
        // The local adapter is never removable — it is the fallback every
        // other path assumes exists.
        $valid[ $local->name() ] = $local;

        self::$adapters = $valid;
        return self::$adapters;
    }

    /** Test seam. */
    public static function flush(): void {
        self::$adapters = null;
    }
}
