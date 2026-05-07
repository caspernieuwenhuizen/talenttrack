<?php
namespace TT\Modules\Export\Format;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FormatRendererRegistry (#0063) — central lookup for format renderers.
 *
 * Same registration pattern as `WidgetRegistry` and `KpiDataSourceRegistry`:
 * static, in-memory, idempotent. Modules register their renderers at
 * boot time (typically in `ExportModule::boot()` for the foundation
 * set; per-use-case modules may add format-specific ones).
 *
 * `FormatRendererInterface::format()` is the registry key. Last
 * registration wins so tests can swap a renderer without restarting.
 */
final class FormatRendererRegistry {

    /** @var array<string, FormatRendererInterface> */
    private static array $renderers = [];

    public static function register( FormatRendererInterface $renderer ): void {
        self::$renderers[ $renderer->format() ] = $renderer;
    }

    public static function get( string $format ): ?FormatRendererInterface {
        return self::$renderers[ $format ] ?? null;
    }

    /** @return string[] */
    public static function formats(): array {
        return array_keys( self::$renderers );
    }

    /** Reset between test scenarios. */
    public static function clear(): void {
        self::$renderers = [];
    }
}
