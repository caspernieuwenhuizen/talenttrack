<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\SearchProvider;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchViewer;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * LibraryRecordSearchProvider (#4942) — a library record kept in a table of
 * its own (exercises, training plans), found by the staff who may open it.
 *
 * One statement for the hits and one for the count, over the same `WHERE`:
 *
 *   - the club and the record's own lifecycle ({@see self::live()});
 *   - the viewer's scope ({@see self::scope()}), the rule the record's own
 *     list and detail page apply;
 *   - every folded token, in the title or the secondary text.
 *
 * These tables carry no folded search columns, so the raw columns are
 * matched lower-cased, as `SearchText` does for a row not folded yet. The
 * score follows `SearchText::scoreSql()`: 100 for an exact title, then per
 * token 60 at the start of a word in the title, 30 inside it, 12 in the
 * secondary text.
 *
 * Nothing here is answered for a parent or a player: the library is not on
 * the family's list (#4673). A kind whose module is switched off returns
 * nothing ({@see self::moduleOn()}).
 */
abstract class LibraryRecordSearchProvider implements SearchProvider {

    /** How many words of free text a hit shows. */
    protected const EXCERPT_WORDS = 14;

    public function minChars(): int { return 2; }

    public function isRecord(): bool { return false; }

    /** No pattern describes a library record: a query carrying one matches none. */
    public function patterns(): array { return []; }

    /** The record's table alias. */
    abstract protected function alias(): string;

    /** `FROM <table> <alias> [JOIN …]`, prefix in place. */
    abstract protected function from(): string;

    /** The columns a hit is built from: the record's `id` first. */
    abstract protected function columns(): string;

    /** The record's lifecycle condition: live rows only. */
    abstract protected function live(): string;

    /** @return list<string> the title's columns, qualified */
    abstract protected function titleColumns(): array;

    /** @return list<string> the secondary text's columns, qualified */
    abstract protected function textColumns(): array;

    /** What sorts hits of equal score. */
    abstract protected function tieBreak(): string;

    /** Is the module that owns this kind switched on? */
    abstract protected function moduleOn(): bool;

    /**
     * The viewer's scope on this kind, or null when they may open none of it.
     *
     * @return array{sql:string, params:list<mixed>}|null
     */
    abstract protected function scope( int $user_id ): ?array;

    /**
     * Extra conditions from the query's filters. Null when the query asks
     * for something this kind cannot be.
     *
     * @return array{sql:string, params:list<mixed>}|null
     */
    protected function conditions( SearchQuery $query ): ?array {
        return [ 'sql' => '1=1', 'params' => [] ];
    }

    /** @return array<string,mixed> */
    abstract protected function toHit( object $row, int $user_id ): array;

    /**
     * The hits the record's own access check keeps, batched.
     *
     * @param list<array<string,mixed>> $hits
     * @return list<array<string,mixed>>
     */
    abstract protected function confirmHits( int $user_id, array $hits ): array;

    public function match( SearchQuery $query, int $user_id, int $limit ): array {
        $built = $this->build( $query, $user_id );
        if ( $built === null || $limit <= 0 ) return [];

        global $wpdb;
        $score = $this->scoreSql( $query->tokens );
        $sql   = "SELECT {$this->columns()}, ( {$score['sql']} ) AS tt_score
                    {$this->from()}
                   WHERE {$built['where']}
                ORDER BY tt_score DESC, {$this->tieBreak()}
                   LIMIT " . (int) $limit;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( $score['params'], $built['params'] ) ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $hit          = $this->toHit( $row, $user_id );
            $hit['score'] = (int) ( $row->tt_score ?? 0 );
            $out[]        = $hit;
        }
        return $out;
    }

    public function count( SearchQuery $query, int $user_id ): int {
        $built = $this->build( $query, $user_id );
        if ( $built === null ) return 0;
        global $wpdb;
        $sql = "SELECT COUNT(*) {$this->from()} WHERE {$built['where']}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$built['params'] ) );
    }

    public function confirm( int $user_id, array $hits ): array {
        if ( $hits === [] || ! $this->serves( $user_id ) ) return [];
        $kept = $this->confirmHits( $user_id, $hits );
        foreach ( $kept as $i => $hit ) unset( $kept[ $i ]['tt_row'] );
        return array_values( $kept );
    }

    public function hydrate( int $user_id, array $ids ): array {
        return [];
    }

    /** Staff and scouts with the module on: anyone not answered as a family. */
    protected function serves( int $user_id ): bool {
        return $user_id > 0 && $this->moduleOn() && ! SearchViewer::isFamily( $user_id );
    }

    /**
     * @return array{where:string, params:list<mixed>}|null
     */
    private function build( SearchQuery $query, int $user_id ): ?array {
        if ( $query->length < $this->minChars() || $query->isEmpty() || $query->tokens === [] ) return null;
        if ( $query->patterns() !== [] ) return null;
        if ( ! $this->serves( $user_id ) ) return null;
        $scope = $this->scope( $user_id );
        if ( $scope === null ) return null;
        $conditions = $this->conditions( $query );
        if ( $conditions === null ) return null;

        $a      = $this->alias();
        $tokens = $this->tokensSql( $query->tokens );
        return [
            'where'  => "{$a}.club_id = %d AND {$this->live()} AND {$scope['sql']} AND {$tokens['sql']} AND {$conditions['sql']}",
            'params' => array_merge( [ (int) CurrentClub::id() ], $scope['params'], $tokens['params'], $conditions['params'] ),
        ];
    }

    /**
     * Every token in the title or the secondary text.
     *
     * @param list<string> $tokens
     * @return array{sql:string, params:list<mixed>}
     */
    private function tokensSql( array $tokens ): array {
        $title  = $this->joined( $this->titleColumns() );
        $text   = $this->joined( $this->textColumns() );
        $parts  = [];
        $params = [];
        foreach ( $tokens as $token ) {
            $parts[] = "( {$title} LIKE %s OR {$text} LIKE %s )";
            array_push( $params, '%' . $token . '%', '%' . $token . '%' );
        }
        return [ 'sql' => implode( ' AND ', $parts ), 'params' => $params ];
    }

    /**
     * The score in SQL, as `SearchText::scoreSql()` computes it.
     *
     * @param list<string> $tokens
     * @return array{sql:string, params:list<mixed>}
     */
    private function scoreSql( array $tokens ): array {
        $title  = $this->joined( $this->titleColumns() );
        $text   = $this->joined( $this->textColumns() );
        $parts  = [ "( CASE WHEN {$title} = %s THEN 100 ELSE 0 END )" ];
        $params = [ ' ' . implode( ' ', $tokens ) ];
        foreach ( $tokens as $token ) {
            $parts[] = "( CASE WHEN {$title} LIKE %s THEN 60 WHEN {$title} LIKE %s THEN 30 WHEN {$text} LIKE %s THEN 12 ELSE 0 END )";
            array_push( $params, '% ' . $token . '%', '%' . $token . '%', '%' . $token . '%' );
        }
        return [ 'sql' => '( ' . implode( ' + ', $parts ) . ' )', 'params' => $params ];
    }

    /**
     * Columns lower-cased and joined, with the leading space a folded
     * column has, so the start of a word is `LIKE '% tok%'`.
     *
     * @param list<string> $columns
     */
    private function joined( array $columns ): string {
        if ( $columns === [] ) return "''";
        return "CONCAT( ' ', LOWER( CONCAT_WS( ' ', " . implode( ', ', $columns ) . ' ) ) )';
    }

    /** A short excerpt of free text, tags removed; '' for none. */
    protected static function excerpt( string $text ): string {
        $text = trim( wp_strip_all_tags( $text ) );
        return $text === '' ? '' : wp_trim_words( $text, self::EXCERPT_WORDS );
    }

    private ?string $dashboard = null;

    /** A detail URL on the memoised dashboard URL. */
    protected function detailUrl( string $slug, int $id ): string {
        $this->dashboard ??= \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
        return (string) add_query_arg( [ 'tt_view' => $slug, 'id' => $id ], $this->dashboard );
    }
}
