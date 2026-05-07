<?php
namespace TT\Modules\CustomWidgets\Cache;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CustomWidgetCache (#0078 Phase 5) — per-widget transient cache for
 * the rendered-data path.
 *
 * Stored as `tt_cw_data_<uuid>_<user_id>_v<version>` transients with
 * the widget's `cache_ttl_minutes` from the saved definition. The
 * `_v<version>` suffix is the trick that makes cache invalidation
 * O(1) without needing transient-prefix scanning (which WP doesn't
 * support reliably across all object cache backends): bumping the
 * version counter for a uuid effectively orphans every prior cache
 * entry and they expire naturally.
 *
 * **Per-user keying**: a custom widget can return different rows for
 * different viewers (e.g. a future "my players" filter), so the
 * cache key is `(uuid, user_id)`. Phase 5 keeps every saved widget
 * tenant-rooted; if a future source bypasses tenant scope, that's
 * its problem to handle.
 *
 * **TTL of 0** disables caching entirely (the operator's escape
 * hatch from a slow-moving widget). Callers see a fresh fetch every
 * render.
 */
final class CustomWidgetCache {

    private const VERSION_OPTION_PREFIX = 'tt_cw_v_';
    private const DATA_TRANSIENT_PREFIX = 'tt_cw_data_';

    /**
     * Try to read cached rows for `(uuid, user_id)`. Returns null on
     * miss, expiry, or `ttl=0` disabled.
     *
     * @return array<int, array<string,mixed>>|null
     */
    public static function get( string $uuid, int $user_id, int $ttl_minutes ): ?array {
        if ( $ttl_minutes <= 0 ) return null;
        $key   = self::dataKey( $uuid, $user_id );
        $value = get_transient( $key );
        if ( $value === false ) return null;
        return is_array( $value ) ? $value : null;
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     */
    public static function put( string $uuid, int $user_id, array $rows, int $ttl_minutes ): void {
        if ( $ttl_minutes <= 0 ) return;
        $key = self::dataKey( $uuid, $user_id );
        $ttl = $ttl_minutes * MINUTE_IN_SECONDS;
        // WP transients hard-cap at WEEK_IN_SECONDS for some backends.
        if ( $ttl > WEEK_IN_SECONDS ) $ttl = WEEK_IN_SECONDS;
        set_transient( $key, $rows, $ttl );
    }

    /**
     * Bump the per-uuid version counter. Every prior cache entry for
     * that uuid is now keyed under the old version and unreachable;
     * they expire naturally on their TTL.
     */
    public static function flush( string $uuid ): void {
        $opt = self::VERSION_OPTION_PREFIX . $uuid;
        $cur = (int) get_option( $opt, 0 );
        update_option( $opt, $cur + 1, false );
    }

    private static function dataKey( string $uuid, int $user_id ): string {
        $version = (int) get_option( self::VERSION_OPTION_PREFIX . $uuid, 0 );
        // Transient keys are bound to 172 chars on some backends; the
        // composed key here stays under 80 even with a 36-char uuid.
        return self::DATA_TRANSIENT_PREFIX . $uuid . '_' . $user_id . '_v' . $version;
    }
}
