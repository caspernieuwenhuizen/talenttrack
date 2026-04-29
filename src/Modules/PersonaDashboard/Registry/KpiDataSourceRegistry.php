<?php
namespace TT\Modules\PersonaDashboard\Registry;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\KpiDataSource;

/**
 * KpiDataSourceRegistry — closed enum of the 25 shipped KPIs (#0060).
 *
 * KPIs register via CoreKpis at module boot. The editor's KPI picker
 * filters by persona context; rendering looks up by id.
 */
final class KpiDataSourceRegistry {

    /** @var array<string, KpiDataSource> */
    private static array $sources = [];

    public static function register( KpiDataSource $source ): void {
        self::$sources[ $source->id() ] = $source;
    }

    public static function get( string $id ): ?KpiDataSource {
        return self::$sources[ $id ] ?? null;
    }

    /** @return array<string, KpiDataSource> */
    public static function all(): array {
        return self::$sources;
    }

    /**
     * @param string $context one of PersonaContext::ACADEMY|COACH|PLAYER_PARENT
     * @return array<string, KpiDataSource>
     */
    public static function byContext( string $context ): array {
        $out = [];
        foreach ( self::$sources as $id => $source ) {
            if ( $source->context() === $context ) $out[ $id ] = $source;
        }
        return $out;
    }

    public static function clear(): void {
        self::$sources = [];
    }
}
