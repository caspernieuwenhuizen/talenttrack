<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Search\SearchProvider;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchText;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * RecordSearchProvider (#4671) — the shared query shape of a record kind.
 *
 * One statement per kind for the hits and one for the count, both over the
 * same `WHERE`:
 *
 *   - the club, live rows only (`ArchiveRepository::filterClause( 'active' )`)
 *     and the demo scope (`QueryHelpers::apply_demo_scope()`);
 *   - the kind's scope clause ({@see self::scope()}), built from the access
 *     rule the record's own screens use;
 *   - every folded token, in the title or the secondary text;
 *   - the patterns and filters the kind honours.
 *
 * The score is computed in the same statement (`SearchText::scoreSql()`),
 * plus the kind's bonus and +10 for a record in the viewer's own scope, so
 * the `LIMIT` is filled with the best rows the viewer may open.
 */
abstract class RecordSearchProvider implements SearchProvider {

    /** Bonus for a record in the viewer's own scope (own team, own child). */
    protected const OWN_SCOPE_BONUS = 10;

    public function minChars(): int { return 2; }

    public function isRecord(): bool { return true; }

    /** The table alias the statements use. */
    abstract protected function alias(): string;

    /** `FROM … [JOIN …]`, with the table prefix in place: what a hit is read from. */
    abstract protected function from(): string;

    /**
     * `FROM <table> <alias>`: the record's own table, which the scope, the
     * matching, the ranking and the count read. No joins.
     */
    protected function table(): string {
        global $wpdb;
        return "FROM {$wpdb->prefix}" . ( SearchText::KINDS[ $this->kind() ]['table'] ?? '' ) . ' ' . $this->alias();
    }

    /** The columns a hit is built from. */
    abstract protected function columns(): string;

    /**
     * The viewer's scope on this kind, or null when they read none of it:
     * `sql` narrows, `own_sql` marks a record in their own scope (or '').
     *
     * @return array{sql:string, params:list<mixed>, own_sql:string, own_params:list<mixed>}|null
     */
    abstract protected function scope( int $user_id ): ?array;

    /**
     * @param object $row
     * @return array<string,mixed>
     */
    abstract protected function toHit( object $row, int $user_id ): array;

    /** The score bonus of this kind (players +15, teams +8). */
    protected function kindBonus(): int { return 0; }

    /** What sorts hits of equal score. */
    protected function tieBreak(): string { return $this->alias() . '.id ASC'; }

    /**
     * Pattern and filter conditions. Null when the query asks for something
     * this kind cannot be (the caller then matches nothing).
     *
     * @return array{sql:string, params:list<mixed>}|null
     */
    protected function conditions( SearchQuery $query ): ?array {
        return [ 'sql' => '1=1', 'params' => [] ];
    }

    /**
     * Extra per-token conditions, beside the title and the text.
     *
     * @return array<string, array{sql:string, params:list<mixed>}>
     */
    protected function tokenExtras( SearchQuery $query ): array { return []; }

    public function match( SearchQuery $query, int $user_id, int $limit ): array {
        $built = $this->build( $query, $user_id );
        if ( $built === null || $limit <= 0 ) return [];

        global $wpdb;
        $a     = $this->alias();
        $score = SearchText::scoreSql( $this->kind(), $a, $query->tokens, $this->tokenExtras( $query ) );
        $own   = $built['own_sql'] !== '' ? "( CASE WHEN {$built['own_sql']} THEN " . self::OWN_SCOPE_BONUS . ' ELSE 0 END )' : '0';
        // The ranking reads the record's own table only; the columns a hit
        // shows, joins included, are read for the page of ids afterwards.
        $sql   = "SELECT {$a}.id, ( {$score['sql']} + {$own} + {$this->kindBonus()} ) AS tt_score
                    {$this->table()}
                   WHERE {$built['where']}
                ORDER BY tt_score DESC, {$this->tieBreak()}
                   LIMIT " . (int) $limit;
        $params = array_merge( $score['params'], $built['own_params'], $built['params'] );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ranked = $wpdb->get_results( $params === [] ? $sql : $wpdb->prepare( $sql, ...$params ) );
        $ranked = is_array( $ranked ) ? $ranked : [];
        if ( $ranked === [] ) return [];

        $scores = [];
        foreach ( $ranked as $row ) $scores[ (int) $row->id ] = (int) $row->tt_score;
        $rows = $this->rowsById( array_keys( $scores ) );

        $out = [];
        foreach ( $scores as $id => $value ) {
            if ( ! isset( $rows[ $id ] ) ) continue;
            $hit          = $this->toHit( $rows[ $id ], $user_id );
            $hit['score'] = $value;
            $out[]        = $hit;
        }
        return $out;
    }

    /**
     * The hit columns of these ids, joins included, in one statement.
     *
     * @param list<int> $ids
     * @return array<int, object>
     */
    private function rowsById( array $ids ): array {
        if ( $ids === [] ) return [];
        global $wpdb;
        $a    = $this->alias();
        $in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql  = "SELECT {$this->columns()} {$this->from()} WHERE {$a}.id IN ({$in})";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$ids ) );
        $out  = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) $out[ (int) $row->id ] = $row;
        return $out;
    }

    public function count( SearchQuery $query, int $user_id ): int {
        $built = $this->build( $query, $user_id );
        if ( $built === null ) return 0;
        global $wpdb;
        $sql = "SELECT COUNT(*) {$this->table()} WHERE {$built['where']}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $built['params'] === [] ? $sql : $wpdb->prepare( $sql, ...$built['params'] ) );
    }

    public function hydrate( int $user_id, array $ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) return [];
        $scope = $this->scope( $user_id );
        if ( $scope === null ) return [];

        global $wpdb;
        $a      = $this->alias();
        $in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $base   = $this->baseWhere();
        $where  = "{$base['sql']} AND {$scope['sql']} AND {$a}.id IN ({$in})";
        $params = array_merge( $base['params'], $scope['params'], $ids );
        $sql    = "SELECT {$this->columns()}, 0 AS tt_score {$this->from()} WHERE {$where}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        $hits = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $hit          = $this->toHit( $row, $user_id );
            $hit['score'] = 0;
            $hits[]       = $hit;
        }
        $out = [];
        foreach ( $this->confirm( $user_id, $hits ) as $hit ) {
            $out[ (int) $hit['id'] ] = $hit;
        }
        return $out;
    }

    /**
     * The club, live rows and the demo scope.
     *
     * @return array{sql:string, params:list<mixed>}
     */
    protected function baseWhere(): array {
        $a    = $this->alias();
        $demo = QueryHelpers::apply_demo_scope( $a, SearchText::KINDS[ $this->kind() ]['demo'] ?? $this->kind() );
        return [
            'sql'    => "{$a}.club_id = %d AND " . ArchiveRepository::filterClause( 'active', $a ) . ' ' . $demo,
            'params' => [ (int) CurrentClub::id() ],
        ];
    }

    /**
     * @return array{where:string, params:list<mixed>, own_sql:string, own_params:list<mixed>}|null
     */
    private function build( SearchQuery $query, int $user_id ): ?array {
        if ( $query->length < $this->minChars() || $query->isEmpty() ) return null;
        foreach ( $query->patterns() as $pattern ) {
            if ( ! in_array( $pattern, $this->patterns(), true ) ) return null;
        }
        $scope = $this->scope( $user_id );
        if ( $scope === null ) return null;
        $conditions = $this->conditions( $query );
        if ( $conditions === null ) return null;

        $a      = $this->alias();
        $tokens = SearchText::whereSql( $this->kind(), $a, $query->tokens, $this->tokenExtras( $query ) );
        $base   = $this->baseWhere();

        return [
            'where'      => "{$base['sql']} AND {$scope['sql']} AND {$tokens['sql']} AND {$conditions['sql']}",
            'params'     => array_merge( $base['params'], $scope['params'], $tokens['params'], $conditions['params'] ),
            'own_sql'    => $scope['own_sql'],
            'own_params' => $scope['own_params'],
        ];
    }

    private ?string $dashboard = null;

    /**
     * The dashboard URL, resolved once: hits are built per row, and the
     * page lookup behind it must not be.
     */
    protected function dashboard(): string {
        return $this->dashboard ??= \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
    }

    /** `RecordLink::detailUrlFor()` on the memoised dashboard URL. */
    protected function detailUrl( string $list_slug, int $id ): string {
        if ( $id <= 0 ) return '';
        return (string) add_query_arg( [ 'tt_view' => $list_slug, 'id' => $id ], $this->dashboard() );
    }

    /**
     * `IN (…)` placeholders for a list, or a clause that matches nothing.
     *
     * @param list<int> $ids
     * @return array{sql:string, params:list<int>}
     */
    protected static function inList( string $column, array $ids ): array {
        if ( $ids === [] ) return [ 'sql' => '1=0', 'params' => [] ];
        return [ 'sql' => "{$column} IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', 'params' => $ids ];
    }

    /**
     * The age-group condition on a team's folded title and text: `O14`
     * matches every group of that age (O14, U14, JO14, MO14), `MO14` only
     * the girls' group.
     *
     * @param array{prefix:string, age:int} $group
     * @return array{sql:string, params:list<mixed>}
     */
    protected static function ageGroupSql( string $team_alias, array $group ): array {
        $prefixes = $group['prefix'] === 'mo' ? [ 'mo' ] : [ 'o', 'u', 'jo', 'mo' ];
        return SearchText::anySql( 'team', $team_alias, array_map( static fn( string $prefix ): string => '% ' . $prefix . $group['age'] . '%', $prefixes ) );
    }
}
