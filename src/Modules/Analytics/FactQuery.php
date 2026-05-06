<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\Dimension;
use TT\Modules\Analytics\Domain\Fact;
use TT\Modules\Analytics\Domain\Measure;

/**
 * FactQuery — the engine every KPI and explorer hits (#0083 Child 1).
 *
 * One method, `run()`, takes a fact key plus a list of dimension
 * keys to group by, a list of measure keys to compute, and a list of
 * filters. Returns a flat array of stdClass rows. Generates a single
 * SQL statement, scoped to the current `club_id`, cached for 60 seconds
 * via the WordPress object cache.
 *
 * Filter operator vocabulary (the `$filters` array shape):
 *
 *   [
 *     'team_id_eq'      => 12,
 *     'team_id_in'      => [ 12, 13, 14 ],
 *     'team_id_not_eq'  => 99,
 *     'date_after'      => '2026-01-01',     // applies to fact's timeColumn
 *     'date_before'     => '2026-04-01',
 *     'status_eq'       => 'present',
 *   ]
 *
 * Each operator key is `<dim_key>_<op>` where op ∈ {eq, in, not_eq}.
 * The `date_after` / `date_before` keys are special — they apply to
 * the fact's declared timeColumn, not a dimension.
 *
 * **Multi-tenancy:** the engine auto-injects `WHERE club_id = <current>`
 * via `CurrentClub::id()`. Cross-club aggregation is deliberately
 * impossible from this API — adding it later requires a separate
 * method with an explicit cap check, not a parameter override.
 *
 * **SQL injection prevention:** every value goes through
 * `$wpdb->prepare()`. Identifier names (table, column, alias) are
 * either constants in this file or come from registered Fact /
 * Dimension / Measure value objects which are constructed at boot
 * from compile-time literals. User input never reaches an identifier.
 */
final class FactQuery {

    private const CACHE_TTL_SECONDS = 60;
    private const CACHE_GROUP       = 'tt_analytics';

    /**
     * Operators recognised by `applyFilters()`. Suffix on the filter
     * array key drives the SQL fragment.
     */
    private const OPERATORS = [ 'eq', 'in', 'not_eq', 'not_in' ];

    /**
     * @param string             $factKey
     * @param string[]           $dimensionKeys
     * @param string[]           $measureKeys
     * @param array<string,mixed> $filters
     * @return array<int, \stdClass>
     */
    public static function run( string $factKey, array $dimensionKeys, array $measureKeys, array $filters = [] ): array {
        $fact = FactRegistry::find( $factKey );
        if ( $fact === null ) return [];

        $cache_key = self::cacheKey( $factKey, $dimensionKeys, $measureKeys, $filters );
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( is_array( $cached ) ) return $cached;

        $rows = self::execute( $fact, $dimensionKeys, $measureKeys, $filters );
        wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL_SECONDS );
        return $rows;
    }

    /**
     * Build + run the SQL. Pulled out of `run()` so the cache wrapper
     * stays small.
     *
     * @param string[]            $dimensionKeys
     * @param string[]            $measureKeys
     * @param array<string,mixed> $filters
     * @return array<int, \stdClass>
     */
    private static function execute( Fact $fact, array $dimensionKeys, array $measureKeys, array $filters ): array {
        global $wpdb;

        $select_parts = [];
        $group_by     = [];
        $params       = [];

        foreach ( $dimensionKeys as $dim_key ) {
            $dim = $fact->dimension( $dim_key );
            if ( $dim === null ) continue;
            $expr = self::dimensionExpression( $fact, $dim );
            $select_parts[] = $expr . ' AS ' . self::ident( $dim->key );
            $group_by[]     = $expr;
        }

        foreach ( $measureKeys as $measure_key ) {
            $measure = $fact->measure( $measure_key );
            if ( $measure === null ) continue;
            $select_parts[] = self::measureExpression( $measure ) . ' AS ' . self::ident( $measure->key );
        }

        if ( empty( $select_parts ) ) {
            // Caller passed no dimensions and no measures. Return [] rather
            // than emitting a SELECT with no projections.
            return [];
        }

        // Build the FROM + optional join for the time column.
        $from = $wpdb->prefix . self::ident( $fact->tableName ) . ' AS ' . self::ident( $fact->tableAlias );
        $tc   = $fact->timeColumn;
        if ( $tc->joinedTable !== null && $tc->joinKey !== null ) {
            // Convention: joinedTable string is `'tt_table_name a'` (table + alias).
            // Trust the caller (registered facts are author-controlled, not user input).
            $from .= ' LEFT JOIN ' . $wpdb->prefix . $tc->joinedTable
                  . ' ON ' . $fact->tableAlias . '.' . self::ident( $tc->joinKey )
                  . ' = ' . self::tableAliasFromJoin( $tc->joinedTable ) . '.id';
        }

        // WHERE: tenancy + filters.
        $where = [];
        $where[] = $fact->tableAlias . '.club_id = %d';
        $params[] = CurrentClub::id();

        $filter_sql = self::applyFilters( $fact, $filters, $params );
        if ( $filter_sql !== '' ) {
            $where[] = $filter_sql;
        }

        $sql = 'SELECT ' . implode( ', ', $select_parts )
             . ' FROM ' . $from
             . ' WHERE ' . implode( ' AND ', $where );
        if ( ! empty( $group_by ) ) {
            $sql .= ' GROUP BY ' . implode( ', ', $group_by );
        }
        $sql .= ' LIMIT 5000';

        if ( ! empty( $params ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare( $sql, ...$params );
        }

        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Render the SQL expression for a dimension. Falls back to
     * `<table_alias>.<key>` if the dimension didn't declare one.
     */
    private static function dimensionExpression( Fact $fact, Dimension $dim ): string {
        if ( $dim->sqlExpression !== null && $dim->sqlExpression !== '' ) {
            return $dim->sqlExpression;
        }
        return $fact->tableAlias . '.' . self::ident( $dim->key );
    }

    /**
     * Render the SQL expression for a measure.
     */
    private static function measureExpression( Measure $measure ): string {
        $agg = strtoupper( $measure->aggregation );
        $col = $measure->column ?? '*';
        // Whitelist the aggregation function — never trust the registry
        // to put arbitrary SQL in `$aggregation`.
        $allowed = [ 'COUNT', 'AVG', 'SUM', 'MIN', 'MAX' ];
        if ( ! in_array( $agg, $allowed, true ) ) {
            $agg = 'COUNT';
            $col = '*';
        }
        return $agg . '(' . $col . ')';
    }

    /**
     * Build the WHERE-fragment for the filter array. Appends to
     * `$params` in place. Returns the fragment (may be empty).
     *
     * @param array<string,mixed> $filters
     * @param array<int,mixed>    $params  (mutable, by reference)
     */
    private static function applyFilters( Fact $fact, array $filters, array &$params ): string {
        $clauses = [];
        foreach ( $filters as $key => $value ) {
            // Special-case the time-column filters first.
            if ( $key === 'date_after' ) {
                $clauses[] = $fact->timeColumn->expression . ' >= %s';
                $params[]  = (string) $value;
                continue;
            }
            if ( $key === 'date_before' ) {
                $clauses[] = $fact->timeColumn->expression . ' <= %s';
                $params[]  = (string) $value;
                continue;
            }

            // Generic <dim>_<op> handling.
            [ $dim_key, $op ] = self::splitFilterKey( $key );
            if ( $dim_key === null || $op === null ) continue;

            $dim = $fact->dimension( $dim_key );
            if ( $dim === null ) continue;

            $expr = self::dimensionExpression( $fact, $dim );
            switch ( $op ) {
                case 'eq':
                    $clauses[] = $expr . ' = ' . self::placeholder( $value );
                    $params[]  = $value;
                    break;
                case 'not_eq':
                    $clauses[] = $expr . ' <> ' . self::placeholder( $value );
                    $params[]  = $value;
                    break;
                case 'in':
                    if ( ! is_array( $value ) || empty( $value ) ) continue 2;
                    $placeholders = implode( ',', array_fill( 0, count( $value ), self::placeholderFor( reset( $value ) ) ) );
                    $clauses[]    = $expr . ' IN (' . $placeholders . ')';
                    foreach ( $value as $v ) $params[] = $v;
                    break;
                case 'not_in':
                    if ( ! is_array( $value ) || empty( $value ) ) continue 2;
                    $placeholders = implode( ',', array_fill( 0, count( $value ), self::placeholderFor( reset( $value ) ) ) );
                    $clauses[]    = $expr . ' NOT IN (' . $placeholders . ')';
                    foreach ( $value as $v ) $params[] = $v;
                    break;
            }
        }
        return implode( ' AND ', $clauses );
    }

    /**
     * Split `<dim_key>_<op>` into ['dim_key', 'op']. Returns
     * [null, null] on shapes that don't match.
     *
     * @return array{0:?string,1:?string}
     */
    private static function splitFilterKey( string $key ): array {
        foreach ( self::OPERATORS as $op ) {
            $suffix = '_' . $op;
            $len    = strlen( $suffix );
            if ( strlen( $key ) > $len && substr( $key, -$len ) === $suffix ) {
                return [ substr( $key, 0, -$len ), $op ];
            }
        }
        return [ null, null ];
    }

    private static function placeholder( $value ): string {
        return self::placeholderFor( $value );
    }

    private static function placeholderFor( $value ): string {
        if ( is_int( $value ) ) return '%d';
        if ( is_float( $value ) ) return '%f';
        return '%s';
    }

    /**
     * Sanitise an identifier (column / alias name) — only [a-z0-9_]
     * is allowed. Defensive even though identifiers are author-
     * controlled at registration time.
     */
    private static function ident( string $name ): string {
        return preg_replace( '/[^a-zA-Z0-9_]/', '', $name );
    }

    /**
     * Parse `'tt_activities a'` → `'a'`. Falls back to the table name
     * when no alias is declared (rare; the fact convention is to
     * always alias).
     */
    private static function tableAliasFromJoin( string $joinedTable ): string {
        $parts = preg_split( '/\s+/', trim( $joinedTable ) );
        return self::ident( end( $parts ) );
    }

    /**
     * Cache key — short hash of the query parameters.
     *
     * @param string[]            $dims
     * @param string[]            $measures
     * @param array<string,mixed> $filters
     */
    private static function cacheKey( string $factKey, array $dims, array $measures, array $filters ): string {
        $parts = [
            'club'     => CurrentClub::id(),
            'fact'     => $factKey,
            'dims'     => $dims,
            'measures' => $measures,
            'filters'  => $filters,
        ];
        return 'q_' . md5( (string) wp_json_encode( $parts ) );
    }
}
