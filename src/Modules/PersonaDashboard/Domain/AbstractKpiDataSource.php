<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AbstractKpiDataSource — base for shipped KPIs.
 *
 * Concrete KPIs declare $id, $label_key, $context as constants and
 * override compute(). Most academy-wide KPIs share the QueryHelpers /
 * audit-log scaffold; the base class doesn't try to be clever about
 * that — each concrete class queries what it needs.
 */
abstract class AbstractKpiDataSource implements KpiDataSource {

    public function id(): string {
        $cls = static::class;
        $parts = explode( '\\', $cls );
        return self::camelToSnake( end( $parts ) );
    }

    public function label(): string {
        return static::class;
    }

    public function context(): string {
        return PersonaContext::ACADEMY;
    }

    abstract public function compute( int $user_id, int $club_id ): KpiValue;

    private static function camelToSnake( string $s ): string {
        $s = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $s ) ?? $s;
        return strtolower( $s );
    }
}
