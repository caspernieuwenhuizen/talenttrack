<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\SearchProvider;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchText;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FamilyRecordSearchProvider (#4673) — a kind found within the viewer's own
 * family: their own record, their children's, their own inbox.
 *
 * The scope is resolved first, as a list of ids the viewer reads, and the
 * statement is bounded by it, so another family's rows are never fetched.
 * The query's dates narrow in SQL; the words are matched in PHP over the
 * newest {@see self::CANDIDATES} rows in scope, with the same fold and score
 * as every other kind, because the text a row is found by (an evaluation
 * type in the viewer's language) is not a column to match in SQL.
 *
 * A miss looks the same whatever it missed: a match outside the scope and
 * no match at all both come back empty and uncounted.
 */
abstract class FamilyRecordSearchProvider implements SearchProvider {

    /** The most rows in scope a query is matched over. */
    protected const CANDIDATES = 500;

    public function minChars(): int { return 2; }

    public function isRecord(): bool { return false; }

    public function patterns(): array {
        return [ SearchQuery::PATTERN_DATE, SearchQuery::PATTERN_WEEKDAY ];
    }

    /**
     * The ids that bound the statement (players, or the viewer's own user
     * id for an inbox). Empty when the viewer reads none of this kind.
     *
     * @return list<int>
     */
    abstract protected function scopeIds( int $user_id ): array;

    /**
     * The rows in scope, newest first, narrowed by the query's dates and
     * the team filter where the kind has a team, at most `$limit`.
     *
     * @param list<int> $ids
     * @return list<object>
     */
    abstract protected function rows( array $ids, SearchQuery $query, int $limit ): array;

    /**
     * A hit, plus `tt_title` and `tt_text`: what the words are matched on.
     *
     * @return array<string,mixed>
     */
    abstract protected function toHit( object $row, int $user_id ): array;

    /**
     * The id in a hit that must still be in scope for the confirm step.
     *
     * @param array<string,mixed> $hit
     */
    abstract protected function scopeKey( array $hit ): int;

    public function match( SearchQuery $query, int $user_id, int $limit ): array {
        return array_slice( $this->scored( $query, $user_id ), 0, max( 0, $limit ) );
    }

    public function count( SearchQuery $query, int $user_id ): int {
        return count( $this->scored( $query, $user_id ) );
    }

    public function confirm( int $user_id, array $hits ): array {
        if ( $hits === [] ) return [];
        $ids = $this->scopeIds( $user_id );
        return array_values( array_filter( $hits, fn( array $h ): bool => in_array( $this->scopeKey( $h ), $ids, true ) ) );
    }

    public function hydrate( int $user_id, array $ids ): array {
        return [];
    }

    /** @var array<string, list<array<string,mixed>>> */
    private array $memo = [];

    /** @return list<array<string,mixed>> */
    private function scored( SearchQuery $query, int $user_id ): array {
        if ( $query->length < $this->minChars() || $query->isEmpty() ) return [];
        foreach ( $query->patterns() as $pattern ) {
            if ( ! in_array( $pattern, $this->patterns(), true ) ) return [];
        }
        $ids = $this->scopeIds( $user_id );
        if ( $ids === [] ) return [];

        $key = implode( '|', [ $user_id, $query->raw, (string) $query->team_id, (string) $query->from, (string) $query->to ] );
        if ( isset( $this->memo[ $key ] ) ) return $this->memo[ $key ];

        $out = [];
        foreach ( $this->rows( $ids, $query, self::CANDIDATES ) as $row ) {
            $hit   = $this->toHit( $row, $user_id );
            $score = $query->tokens === [] ? 0 : SearchText::scoreText( (string) $hit['tt_title'], (string) $hit['tt_text'], $query->tokens );
            if ( $score === null ) continue;
            unset( $hit['tt_title'], $hit['tt_text'] );
            $hit['score'] = $score;
            $out[]        = $hit;
        }
        // Best first; among equals, the order the rows came in (newest).
        $order = array_keys( $out );
        array_multisort( array_column( $out, 'score' ), SORT_DESC, $order, SORT_ASC, $out );
        return $this->memo[ $key ] = $out;
    }

    /**
     * The date conditions on one column: the query's days, and the from/to
     * window.
     *
     * @return array{sql:string, params:list<mixed>}
     */
    protected static function dateSql( string $column, SearchQuery $query ): array {
        $parts  = [];
        $params = [];
        if ( $query->dates !== [] ) {
            $parts[] = "{$column} IN (" . implode( ',', array_fill( 0, count( $query->dates ), '%s' ) ) . ')';
            $params  = array_merge( $params, $query->dates );
        }
        if ( $query->from !== null ) {
            $parts[]  = "{$column} >= %s";
            $params[] = $query->from;
        }
        if ( $query->to !== null ) {
            $parts[]  = "{$column} <= %s";
            $params[] = $query->to;
        }
        return [ 'sql' => $parts === [] ? '1=1' : implode( ' AND ', $parts ), 'params' => $params ];
    }

    /**
     * `IN (…)` placeholders for a non-empty list.
     *
     * @param list<int> $ids
     */
    protected static function placeholders( array $ids ): string {
        return implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    }

    private ?string $dashboard = null;

    /**
     * A Me-view URL on the dashboard. `player_id` is carried because every
     * family surface resolves its subject from it, and a parent with two
     * children landing on the wrong one is the bug that would make the
     * result useless.
     *
     * @param array<string, int|string> $args
     */
    protected function meUrl( string $slug, array $args ): string {
        $this->dashboard ??= RecordLink::dashboardUrl();
        return (string) add_query_arg( array_merge( [ 'tt_view' => $slug ], array_filter( $args ) ), $this->dashboard );
    }
}
