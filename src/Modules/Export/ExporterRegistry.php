<?php
namespace TT\Modules\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ExporterRegistry (#0063) — central lookup for use-case exporters.
 *
 * Same pattern as `WidgetRegistry` / `KpiDataSourceRegistry` /
 * `FormatRendererRegistry`: static, in-memory, idempotent. Modules
 * register their exporters at boot (e.g. `PlayersModule::boot()`
 * registers `players_csv`).
 *
 * Keys come from `ExporterInterface::key()`. The REST URL uses the
 * key as a path segment: `POST /talenttrack/v1/exports/{key}`.
 */
final class ExporterRegistry {

    /** @var array<string, ExporterInterface> */
    private static array $exporters = [];

    public static function register( ExporterInterface $exporter ): void {
        self::$exporters[ $exporter->key() ] = $exporter;
    }

    public static function get( string $key ): ?ExporterInterface {
        return self::$exporters[ $key ] ?? null;
    }

    /** @return array<string, ExporterInterface> */
    public static function all(): array {
        return self::$exporters;
    }

    public static function clear(): void {
        self::$exporters = [];
    }
}
