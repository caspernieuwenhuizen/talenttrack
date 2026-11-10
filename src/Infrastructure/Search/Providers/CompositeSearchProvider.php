<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\SearchProvider;
use TT\Infrastructure\Search\SearchQuery;

/**
 * CompositeSearchProvider (#4936) — one kind served by several providers
 * whose scopes do not overlap: the family's own records and the records a
 * staff member reads as staff are the same resource, so they are the same
 * kind, answered by two scopes.
 *
 * The contract stays the kind's:
 *
 *   - `match()` asks every provider for its best `$limit` and merges them
 *     by score, ties in provider order;
 *   - `count()` adds the providers' counts. That is exact only because the
 *     scopes are disjoint: a row is in at most one provider's scope. Each
 *     provider that joins a composite has to keep it so (the staff
 *     providers leave out every player the viewer is family of);
 *   - `confirm()` hands each hit back to the provider that found it, with
 *     the record's own check, and keeps the merged order;
 *   - `hydrate()` asks each provider in turn for the ids still missing.
 *
 * A provider's hits carry `tt_source` (its index) until the confirm step.
 */
final class CompositeSearchProvider implements SearchProvider {

    /** @var list<SearchProvider> */
    private array $providers;

    private string $kind;

    /** @param list<SearchProvider> $providers non-empty, all of one kind */
    public function __construct( string $kind, array $providers ) {
        $this->kind      = $kind;
        $this->providers = $providers;
    }

    /** @return list<SearchProvider> */
    public function providers(): array {
        return $this->providers;
    }

    public function kind(): string { return $this->kind; }

    public function minChars(): int {
        $min = PHP_INT_MAX;
        foreach ( $this->providers as $provider ) $min = min( $min, $provider->minChars() );
        return $min === PHP_INT_MAX ? 1 : $min;
    }

    public function isRecord(): bool {
        foreach ( $this->providers as $provider ) {
            if ( $provider->isRecord() ) return true;
        }
        return false;
    }

    /**
     * Every pattern one of the providers honours. A provider still matches
     * nothing for a pattern it does not honour itself.
     */
    public function patterns(): array {
        $out = [];
        foreach ( $this->providers as $provider ) {
            foreach ( $provider->patterns() as $pattern ) {
                if ( ! in_array( $pattern, $out, true ) ) $out[] = $pattern;
            }
        }
        return $out;
    }

    public function match( SearchQuery $query, int $user_id, int $limit ): array {
        if ( $limit <= 0 ) return [];
        $hits = [];
        foreach ( $this->providers as $index => $provider ) {
            if ( $query->length < $provider->minChars() ) continue;
            foreach ( $provider->match( $query, $user_id, $limit ) as $hit ) {
                $hit['tt_source'] = $index;
                $hits[]           = $hit;
            }
        }
        // Best first; among equals, provider order and then each provider's own order.
        $order = array_keys( $hits );
        array_multisort( array_map( static fn( array $h ): int => (int) ( $h['score'] ?? 0 ), $hits ), SORT_DESC, $order, SORT_ASC, $hits );
        return array_slice( $hits, 0, $limit );
    }

    public function count( SearchQuery $query, int $user_id ): int {
        $n = 0;
        foreach ( $this->providers as $provider ) {
            if ( $query->length < $provider->minChars() ) continue;
            $n += $provider->count( $query, $user_id );
        }
        return $n;
    }

    public function confirm( int $user_id, array $hits ): array {
        if ( $hits === [] ) return [];
        $by_source = [];
        foreach ( $hits as $position => $hit ) {
            $source = (int) ( $hit['tt_source'] ?? -1 );
            if ( ! isset( $this->providers[ $source ] ) ) continue;
            unset( $hit['tt_source'] );
            $hit['tt_position']       = $position;
            $by_source[ $source ][]   = $hit;
        }

        $kept = [];
        foreach ( $by_source as $source => $group ) {
            foreach ( $this->providers[ $source ]->confirm( $user_id, $group ) as $hit ) $kept[] = $hit;
        }
        usort( $kept, static fn( array $a, array $b ): int => (int) ( $a['tt_position'] ?? 0 ) <=> (int) ( $b['tt_position'] ?? 0 ) );
        foreach ( $kept as $i => $hit ) unset( $kept[ $i ]['tt_position'] );
        return $kept;
    }

    public function hydrate( int $user_id, array $ids ): array {
        $out = [];
        foreach ( $this->providers as $provider ) {
            $missing = array_values( array_diff( $ids, array_keys( $out ) ) );
            if ( $missing === [] ) break;
            $out += $provider->hydrate( $user_id, $missing );
        }
        return $out;
    }
}
