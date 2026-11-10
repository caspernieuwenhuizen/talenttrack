<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\SearchProvider;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchText;

/**
 * MemorySearchProvider (#4671) — a kind matched in memory from a registry
 * that already answers who may see each entry: the sections of the
 * navigation, the help topics, the settings. The entries come back filtered
 * for the viewer, so there is nothing to confirm afterwards and nothing to
 * leak; the text is in the viewer's language because the registry is.
 */
abstract class MemorySearchProvider implements SearchProvider {

    public function minChars(): int { return 1; }

    public function isRecord(): bool { return false; }

    public function patterns(): array { return []; }

    /**
     * The viewer's entries: a stable key, the title and secondary text to
     * match, and the hit fields. `extra` carries a kind's further hit fields
     * (a setting's `path`, `disabled` and `actions`, #4936).
     *
     * @return list<array{key:string, title:string, text:string, label:string, sublabel:string, url:string, extra?:array<string,mixed>}>
     */
    abstract protected function entries( int $user_id ): array;

    public function match( SearchQuery $query, int $user_id, int $limit ): array {
        $hits = $this->scored( $query, $user_id );
        return array_slice( $hits, 0, max( 0, $limit ) );
    }

    public function count( SearchQuery $query, int $user_id ): int {
        return count( $this->scored( $query, $user_id ) );
    }

    public function confirm( int $user_id, array $hits ): array {
        return $hits;
    }

    public function hydrate( int $user_id, array $ids ): array {
        return [];
    }

    /** @var array<string, list<array<string,mixed>>> user and query => scored hits, for one request */
    private array $memo = [];

    /** @var array<int, list<array{key:string, title:string, text:string, label:string, sublabel:string, url:string, extra?:array<string,mixed>}>> */
    private array $entries = [];

    /** @return list<array<string,mixed>> */
    private function scored( SearchQuery $query, int $user_id ): array {
        if ( $query->length < $this->minChars() || $query->tokens === [] || $query->understood !== [] ) return [];
        $key = $user_id . '|' . implode( ' ', $query->tokens );
        if ( isset( $this->memo[ $key ] ) ) return $this->memo[ $key ];
        if ( ! isset( $this->entries[ $user_id ] ) ) $this->entries[ $user_id ] = $this->entries( $user_id );

        $out = [];
        foreach ( $this->entries[ $user_id ] as $i => $entry ) {
            $score = SearchText::scoreText( $entry['title'], $entry['text'], $query->tokens );
            if ( $score === null ) continue;
            $out[] = array_merge( [ 'actions' => [] ], $entry['extra'] ?? [], [
                'type'     => $this->kind(),
                'id'       => 0,
                'key'      => $entry['key'],
                'label'    => $entry['label'],
                'sublabel' => $entry['sublabel'],
                'url'      => $entry['url'],
                'score'    => $score,
                'tt_order' => $i,
            ] );
        }
        usort( $out, static fn( array $a, array $b ): int => [ $b['score'], $a['tt_order'] ] <=> [ $a['score'], $b['tt_order'] ] );
        foreach ( $out as $i => $hit ) unset( $out[ $i ]['tt_order'] );
        return $this->memo[ $key ] = $out;
    }
}
