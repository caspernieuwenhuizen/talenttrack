<?php
namespace TT\Modules\CustomWidgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\CustomWidgets\Domain\CustomDataSource;

/**
 * CustomDataSourceRegistry — central catalogue of data sources for
 * the custom widget builder (#0078 Phase 1).
 *
 * Same registration shape as `WidgetRegistry`, `KpiDataSourceRegistry`,
 * `FactRegistry`, etc.: append-only, keyed by source id, idempotent
 * (last write wins). Modules register their sources in `boot()`;
 * the builder UI + rendering engine (Phases 3-4) consume the
 * registry.
 *
 * v1 ships 5 reference sources from `Modules\CustomWidgets\DataSources\`:
 *   - players_active
 *   - evaluations_recent
 *   - goals_open
 *   - activities_recent
 *   - pdp_files
 *
 * Plugin authors can register additional sources from their own
 * `boot()` once the public API stabilises.
 */
final class CustomDataSourceRegistry {

    /** @var array<string, CustomDataSource> */
    private static array $sources = [];

    public static function register( CustomDataSource $source ): void {
        self::$sources[ $source->id() ] = $source;
    }

    public static function find( string $id ): ?CustomDataSource {
        return self::$sources[ $id ] ?? null;
    }

    /** @return array<string, CustomDataSource> */
    public static function all(): array {
        return self::$sources;
    }

    /**
     * Catalogue shape for the builder UI's data-source picker.
     *
     * @return array<int, array{id:string,label:string}>
     */
    public static function catalogue(): array {
        $out = [];
        foreach ( self::$sources as $id => $source ) {
            $out[] = [
                'id'    => $id,
                'label' => $source->label(),
            ];
        }
        return $out;
    }

    public static function clear(): void {
        self::$sources = [];
    }
}
