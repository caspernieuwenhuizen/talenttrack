<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * KpiResolver — single value-resolution path for both new fact-driven
 * KPIs and legacy `KpiDataSourceRegistry` entries (#0083 Child 2).
 *
 * Lookup precedence:
 *   1. `KpiRegistry::find($key)` — new fact-driven KPI.
 *      Compute via `FactQuery::run()` against the KPI's fact + measure
 *      with default filters applied. Returns the headline number.
 *   2. Legacy `KpiDataSourceRegistry::get($key)` — pre-#0083 KPIs
 *      that haven't been migrated. Compute via the existing
 *      `compute()` method.
 *
 * The resolver hides which registry holds the KPI — widgets and
 * surfaces just call `value($key)` and get a number. The legacy
 * 26 KPIs keep working unchanged through this resolver until
 * the bulk-migration follow-up rewrites them as fact-driven `Kpi`
 * declarations.
 *
 * Returns `null` when neither registry resolves the key — caller
 * decides whether to render "—" or hide the widget.
 */
final class KpiResolver {

    /**
     * Compute the headline number for `$key`. Optional `$extraFilters`
     * narrow the result on top of the KPI's `defaultFilters`.
     *
     * @param array<string,mixed> $extraFilters
     */
    public static function value( string $key, array $extraFilters = [] ): ?float {
        $kpi = KpiRegistry::find( $key );
        if ( $kpi !== null ) {
            $filters = array_merge( $kpi->defaultFilters, $extraFilters );
            $rows    = FactQuery::run( $kpi->factKey, [], [ $kpi->measureKey ], $filters );
            if ( empty( $rows ) ) return null;
            $row = $rows[0];
            $val = $row->{ $kpi->measureKey } ?? null;
            return $val === null ? null : (float) $val;
        }

        // Legacy fallback — only call into the persona-dashboard layer
        // when its class is loaded, so a future major release that
        // drops the legacy registry doesn't break this resolver.
        if ( class_exists( '\\TT\\Modules\\PersonaDashboard\\Registry\\KpiDataSourceRegistry' ) ) {
            $legacy = \TT\Modules\PersonaDashboard\Registry\KpiDataSourceRegistry::get( $key );
            if ( $legacy !== null ) {
                $club_id = class_exists( '\\TT\\Infrastructure\\Tenancy\\CurrentClub' )
                    ? (int) \TT\Infrastructure\Tenancy\CurrentClub::id()
                    : 1;
                $value = $legacy->compute( get_current_user_id(), $club_id );
                // Legacy KpiValue exposes its scalar via `value()`; we
                // coerce to float for a uniform return type.
                if ( is_object( $value ) && method_exists( $value, 'value' ) ) {
                    $scalar = $value->value();
                    return $scalar === null ? null : (float) $scalar;
                }
            }
        }

        return null;
    }

    /**
     * Whether `$key` resolves in either registry. Useful for surfaces
     * that want to hide a widget rather than render `—` for a missing
     * KPI.
     */
    public static function exists( string $key ): bool {
        if ( KpiRegistry::find( $key ) !== null ) return true;
        if ( class_exists( '\\TT\\Modules\\PersonaDashboard\\Registry\\KpiDataSourceRegistry' ) ) {
            return \TT\Modules\PersonaDashboard\Registry\KpiDataSourceRegistry::get( $key ) !== null;
        }
        return false;
    }
}
