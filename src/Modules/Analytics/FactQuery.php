<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\Dimension;
use TT\Modules\Analytics\Domain\Fact;
use TT\Modules\Analytics\Domain\FactResult;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Domain\Measure;

/**
 * FactQuery — the engine every KPI and explorer hits (#0083 Child 1).
 *
 * One method, `run()`, takes a fact key plus a list of dimension
 * keys to group by, a list of measure keys to compute, a list of
 * filters and the reader's scope. Returns a flat array of stdClass rows.
 * Generates a single SQL statement, scoped to the current `club_id`,
 * cached for 60 seconds via the WordPress object cache.
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
 * **Row scope (#4568):** every call takes a `FactScope`, and the engine
 * applies it as SQL: the fact's read entity must be readable, a team-scoped
 * reader gets only their teams' rows (through the fact's team or player
 * column), and a fact that cannot be narrowed answers nothing below
 * club-wide scope. Filters narrow inside that; they never widen it.
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

    /** The most rows one query returns; `query()` says when it was reached. */
    public const ROW_LIMIT = 5000;

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
    public static function run( string $factKey, array $dimensionKeys, array $measureKeys, array $filters, FactScope $scope ): array {
        return self::query( $factKey, $dimensionKeys, $measureKeys, $filters, $scope )->rows;
    }

    /**
     * `run()` with the truncation flag: `truncated` is true when the result
     * reached `ROW_LIMIT` and more rows matched.
     *
     * @param string[]            $dimensionKeys
     * @param string[]            $measureKeys
     * @param array<string,mixed> $filters
     */
    public static function query( string $factKey, array $dimensionKeys, array $measureKeys, array $filters, FactScope $scope ): FactResult {
        $fact = FactRegistry::find( $factKey );
        if ( $fact === null ) return new FactResult( [], false );

        $cache_key = self::cacheKey( $factKey, $dimensionKeys, $measureKeys, $filters, $scope );
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( $cached instanceof FactResult ) return $cached;

        $result = self::execute( $fact, $dimensionKeys, $measureKeys, $filters, $scope );
        wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL_SECONDS );
        return $result;
    }

    /**
     * May this reader query this fact at all? False when the fact is
     * unknown, its entity is not readable, or it cannot be narrowed and the
     * reader is not club-wide. A surface offers only the facts this allows.
     */
    public static function canRead( string $factKey, FactScope $scope ): bool {
        $fact = FactRegistry::find( $factKey );
        if ( $fact === null ) return false;
        if ( ! $scope->mayRead( $fact->readEntity ) ) return false;
        return $scope->isClubWide() || $fact->isScopable();
    }

    /**
     * #874 — drilldown rows. Selects raw fact rows (not aggregated)
     * with every dimension expression aliased by its key. Same WHERE /
     * tenancy / scope / filter semantics as `run()`; respects the outer
     * 5000-row outer cap. Each row carries `_id` (the fact's primary
     * key) so detail links can be built downstream.
     *
     * @param array<string,mixed> $filters
     * @return array<int, \stdClass>
     */
    public static function rows( string $factKey, array $filters, FactScope $scope, int $limit = 50, int $offset = 0 ): array {
        $fact = FactRegistry::find( $factKey );
        if ( $fact === null ) return [];
        $limit  = max( 1, min( self::ROW_LIMIT, $limit ) );
        $offset = max( 0, $offset );
        return self::executeRows( $fact, $filters, $scope, $limit, $offset );
    }

    /**
     * #874 — count of drilldown rows matching the filter set. Used by
     * the pager. Capped at 5000.
     *
     * @param array<string,mixed> $filters
     */
    public static function countRows( string $factKey, array $filters, FactScope $scope ): int {
        $fact = FactRegistry::find( $factKey );
        if ( $fact === null ) return 0;
        return self::executeCount( $fact, $filters, $scope );
    }

    /**
     * Build + run the SQL. Pulled out of `run()` so the cache wrapper
     * stays small.
     *
     * @param string[]            $dimensionKeys
     * @param string[]            $measureKeys
     * @param array<string,mixed> $filters
     */
    private static function execute( Fact $fact, array $dimensionKeys, array $measureKeys, array $filters, FactScope $scope ): FactResult {
        global $wpdb;

        $select_parts = [];
        $group_by     = [];

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
            return new FactResult( [], false );
        }

        $params = [];
        $base   = self::fromWhere( $fact, $filters, $scope, $params );
        if ( $base === null ) return new FactResult( [], false );

        $sql = 'SELECT ' . implode( ', ', $select_parts ) . $base;
        if ( ! empty( $group_by ) ) {
            $sql .= ' GROUP BY ' . implode( ', ', $group_by );
        }
        // One row past the cap, so a result that reached it can say so.
        $sql .= ' LIMIT ' . ( self::ROW_LIMIT + 1 );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        $rows = is_array( $rows ) ? $rows : [];

        $truncated = count( $rows ) > self::ROW_LIMIT;
        if ( $truncated ) $rows = array_slice( $rows, 0, self::ROW_LIMIT );
        return new FactResult( $rows, $truncated );
    }

    /**
     * #874 — execute the raw-row SQL for drilldown. Builds a SELECT
     * that aliases each dimension's expression by its key + emits
     * `f.id AS _id` so links can be built downstream. Reuses
     * `fromWhere()` for parity with `execute()`.
     *
     * @param array<string,mixed> $filters
     * @return array<int, \stdClass>
     */
    private static function executeRows( Fact $fact, array $filters, FactScope $scope, int $limit, int $offset ): array {
        global $wpdb;

        $select_parts = [ $fact->tableAlias . '.id AS _id' ];
        foreach ( $fact->dimensions as $dim ) {
            $expr = self::dimensionExpression( $fact, $dim );
            $select_parts[] = $expr . ' AS ' . self::ident( $dim->key );
        }

        $params = [];
        $base   = self::fromWhere( $fact, $filters, $scope, $params );
        if ( $base === null ) return [];

        $tc    = $fact->timeColumn;
        $order = $tc->expression !== '' ? $tc->expression . ' DESC, ' : '';
        $order .= $fact->tableAlias . '.id DESC';

        $sql = 'SELECT ' . implode( ', ', $select_parts )
             . $base
             . ' ORDER BY ' . $order
             . ' LIMIT %d OFFSET %d';

        $params[] = $limit;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #874 — count helper for the drilldown pager. Mirrors the WHERE
     * clause `executeRows()` builds; respects the same outer cap.
     *
     * @param array<string,mixed> $filters
     */
    private static function executeCount( Fact $fact, array $filters, FactScope $scope ): int {
        global $wpdb;

        $params = [];
        $base   = self::fromWhere( $fact, $filters, $scope, $params );
        if ( $base === null ) return 0;

        $sql = 'SELECT COUNT(*)' . $base;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
        return min( $total, self::ROW_LIMIT );
    }

    /**
     * ` FROM … WHERE …` shared by the three statements: the fact table, the
     * optional time-column join, tenancy, the reader's scope and the filters.
     * Null when the scope leaves nothing to read, so no statement is run.
     *
     * @param array<string,mixed> $filters
     * @param array<int,mixed>    $params  (mutable, by reference)
     */
    private static function fromWhere( Fact $fact, array $filters, FactScope $scope, array &$params ): ?string {
        global $wpdb;

        $params_scope = [];
        $scope_sql    = self::scopeClause( $fact, $scope, $params_scope );
        if ( $scope_sql === null ) return null;

        $from = $wpdb->prefix . self::ident( $fact->tableName ) . ' AS ' . self::ident( $fact->tableAlias );
        $tc   = $fact->timeColumn;
        if ( $tc->joinedTable !== null && $tc->joinKey !== null ) {
            // Convention: joinedTable string is `'tt_table_name a'` (table + alias).
            // Trust the caller (registered facts are author-controlled, not user input).
            $from .= ' LEFT JOIN ' . $wpdb->prefix . $tc->joinedTable
                  . ' ON ' . $fact->tableAlias . '.' . self::ident( $tc->joinKey )
                  . ' = ' . self::tableAliasFromJoin( $tc->joinedTable ) . '.id';
        }

        $where    = [ $fact->tableAlias . '.club_id = %d' ];
        $params[] = CurrentClub::id();

        if ( $scope_sql !== '' ) {
            $where[] = $scope_sql;
            foreach ( $params_scope as $p ) $params[] = $p;
        }

        $filter_sql = self::applyFilters( $fact, $filters, $params );
        if ( $filter_sql !== '' ) {
            $where[] = $filter_sql;
        }

        return ' FROM ' . $from . ' WHERE ' . implode( ' AND ', $where );
    }

    /**
     * The reader's row scope as SQL: '' when nothing narrows, null when
     * nothing may be read.
     *
     * @param array<int,mixed> $params (out)
     */
    private static function scopeClause( Fact $fact, FactScope $scope, array &$params ): ?string {
        global $wpdb;
        $params = [];

        if ( ! $scope->mayRead( $fact->readEntity ) ) return null;

        $clauses = [];
        $teams   = $scope->teamIds();
        if ( $teams !== null ) {
            if ( $teams === [] || ! $fact->isScopable() ) return null;
            $in = implode( ',', array_fill( 0, count( $teams ), '%d' ) );
            if ( $fact->teamColumn !== null ) {
                $clauses[] = $fact->teamColumn . " IN ({$in})";
            } else {
                $clauses[] = "EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_players sp WHERE sp.id = "
                    . $fact->playerColumn . " AND sp.team_id IN ({$in}) )";
            }
            foreach ( $teams as $t ) $params[] = $t;
        }

        $levels = $scope->visibilities();
        if ( $fact->visibilityColumn !== null && $levels !== null ) {
            if ( $levels === [] ) return null;
            $clauses[] = $fact->visibilityColumn . ' IN (' . implode( ',', array_fill( 0, count( $levels ), '%s' ) ) . ')';
            foreach ( $levels as $l ) $params[] = $l;
        }

        return implode( ' AND ', $clauses );
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
     * Resolve a `date_after` / `date_before` bound to an absolute `Y-m-d`.
     *
     * The explorer form, the preset URLs in ExplorerUrl and the KPI
     * `defaultFilters` in AnalyticsModule all advertise a relative form
     * (`-30 days`, `-12 months`) alongside absolute dates. This is the one
     * chokepoint they share, so the expansion happens here.
     *
     * Returns null when the bound is empty or unparseable — the caller drops
     * the clause. Binding the raw string instead would let MySQL coerce it to
     * `0000-00-00`, which matches every row and reads as "no filter" while
     * looking like one is applied.
     */
    public static function normaliseDateBound( string $value ): ?string {
        $value = trim( $value );
        if ( $value === '' ) return null;
        // An absolute date must be a real calendar date. `0000-00-00` has the
        // right shape but is exactly the value this fix exists to keep out of
        // the query, so shape alone is not enough.
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $d ) === 1 ) {
            return checkdate( (int) $d[2], (int) $d[3], (int) $d[1] ) ? $value : null;
        }

        // Match the relative form explicitly rather than handing the string
        // to strtotime(), which is lenient enough to turn "30 dayz ago" into
        // a plausible-but-wrong date. A typo must read as "no filter", not as
        // a silently different window.
        if ( preg_match( '/^([+-]?\d{1,4})\s*(day|week|month|year)s?$/i', $value, $m ) !== 1 ) {
            return null;
        }

        // Anchor to midnight today so "-30 days" is a whole number of days
        // back rather than an arbitrary time-of-day cut.
        $base = strtotime( gmdate( 'Y-m-d' ) );
        if ( $base === false ) return null;

        $offset = (int) $m[1];
        $unit   = strtolower( $m[2] );
        $ts     = strtotime( sprintf( '%+d %s', $offset, $unit ), $base );

        return $ts === false ? null : gmdate( 'Y-m-d', $ts );
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
            // Special-case the time-column filters first. Both accept an
            // absolute `Y-m-d` or a relative form (`-30 days`); an
            // unparseable bound drops the clause rather than binding a
            // value MySQL would coerce to 0000-00-00 (#2440).
            if ( $key === 'date_after' ) {
                $bound = self::normaliseDateBound( (string) $value );
                if ( $bound === null ) continue;
                $clauses[] = $fact->timeColumn->expression . ' >= %s';
                $params[]  = $bound;
                continue;
            }
            if ( $key === 'date_before' ) {
                $bound = self::normaliseDateBound( (string) $value );
                if ( $bound === null ) continue;
                $clauses[] = $fact->timeColumn->expression . ' <= %s';
                $params[]  = $bound;
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
    private static function cacheKey( string $factKey, array $dims, array $measures, array $filters, FactScope $scope ): string {
        $parts = [
            'club'     => CurrentClub::id(),
            'fact'     => $factKey,
            'dims'     => $dims,
            'measures' => $measures,
            'filters'  => $filters,
            'scope'    => $scope->cacheKey(),
        ];
        return 'q_' . md5( (string) wp_json_encode( $parts ) );
    }
}
