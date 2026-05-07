<?php
namespace TT\Modules\Comms\Channel;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ChannelAdapterRegistry (#0066) — central lookup keyed on channel key.
 *
 * Same registration pattern as `WidgetRegistry` /
 * `KpiDataSourceRegistry` / `FormatRendererRegistry`: static,
 * in-memory, idempotent. Modules register their adapters at boot.
 *
 * `CommsService` looks the adapter up after resolving the channel
 * preference / opt-out / quiet-hours decision tree.
 */
final class ChannelAdapterRegistry {

    /** @var array<string, ChannelAdapterInterface> */
    private static array $adapters = [];

    public static function register( ChannelAdapterInterface $adapter ): void {
        self::$adapters[ $adapter->key() ] = $adapter;
    }

    public static function get( string $key ): ?ChannelAdapterInterface {
        return self::$adapters[ $key ] ?? null;
    }

    /** @return string[] registered channel keys (in insertion order). */
    public static function keys(): array {
        return array_keys( self::$adapters );
    }

    public static function clear(): void {
        self::$adapters = [];
    }
}
