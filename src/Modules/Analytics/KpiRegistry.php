<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Domain\Kpi;

/**
 * KpiRegistry — central catalogue of fact-driven KPIs (#0083 Child 2).
 *
 * Mirrors `FactRegistry`'s shape (#0083 Child 1) — append-only,
 * keyed by KPI key, idempotent (last write wins). Modules register
 * KPIs in `boot()`; the framework reads them at resolve time.
 *
 * Coexists with the legacy `Modules\PersonaDashboard\Registry\KpiDataSourceRegistry`
 * during the migration window. Lookup precedence is the new registry
 * first, falling back to the legacy one — see `KpiResolver`.
 *
 * `clear()` is for tests only.
 */
final class KpiRegistry {

    /** @var array<string, Kpi> */
    private static array $kpis = [];

    public static function register( Kpi $kpi ): void {
        self::$kpis[ $kpi->key ] = $kpi;
    }

    public static function find( string $key ): ?Kpi {
        return self::$kpis[ $key ] ?? null;
    }

    /** @return array<string, Kpi> */
    public static function all(): array {
        return self::$kpis;
    }

    /**
     * KPIs whose `context` matches `$context`. Used by surfaces that
     * gate by persona (the central analytics view, the entity tab on
     * a player profile when viewed by a parent vs. a coach).
     *
     * @return array<string, Kpi>
     */
    public static function byContext( string $context ): array {
        $out = [];
        foreach ( self::$kpis as $key => $kpi ) {
            if ( $kpi->context === $context ) $out[ $key ] = $kpi;
        }
        return $out;
    }

    /**
     * KPIs scoped to a particular entity (player / team / activity).
     * Used by the entity Analytics tab (#0083 Child 4) — the player
     * profile pulls every player-scoped KPI; the team profile pulls
     * every team-scoped KPI.
     *
     * @return array<string, Kpi>
     */
    public static function forEntity( string $scope ): array {
        $out = [];
        foreach ( self::$kpis as $key => $kpi ) {
            if ( $kpi->entityScope === $scope ) $out[ $key ] = $kpi;
        }
        return $out;
    }

    public static function clear(): void {
        self::$kpis = [];
    }
}
