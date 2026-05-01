<?php
namespace TT\Modules\PersonaDashboard\Registry;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TableRowSourceRegistry — pluggable row-source contract for DataTableWidget.
 *
 * The widget historically rendered table chrome + an empty-state row; data
 * wiring per preset was a separate ticket. This registry lets a preset
 * declare a `TableRowSource` whose `rowsFor()` returns the actual rows.
 * Presets without a registered source continue to render the empty-row
 * chrome — back-compat by default.
 *
 * Sources are registered at boot from `CoreTemplates::register()` (or any
 * module's boot hook). `DataTableWidget::render()` consults this registry
 * after loading the preset config.
 */
final class TableRowSourceRegistry {

    /** @var array<string, TableRowSource> */
    private static array $sources = [];

    public static function register( string $preset, TableRowSource $source ): void {
        self::$sources[ $preset ] = $source;
    }

    public static function resolve( string $preset ): ?TableRowSource {
        return self::$sources[ $preset ] ?? null;
    }

    public static function reset(): void {
        self::$sources = [];
    }
}
