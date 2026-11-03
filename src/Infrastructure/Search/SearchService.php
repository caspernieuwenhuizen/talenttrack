<?php
namespace TT\Infrastructure\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Security\ScopeMemo;

/**
 * SearchService (#4671) — runs a query over the providers and composes the
 * two shapes `GET /search` answers in. No matching or access rule lives
 * here: each provider narrows and confirms its own kind.
 *
 * - **The header box** (no `page`): `{ results: [ {type,id,label,sublabel,url} ] }`,
 *   at most 10, sections at most 3 while records also match. Sections,
 *   players, teams and activities, as it always was.
 * - **Paged**: every kind (or `types`), ranked together by score, one page
 *   of `items`, a count per kind, the patterns `understood` and the `total`.
 *
 * A hit the confirm step drops was fetched by a scope wider than the
 * record's own rule. That is a defect, so it is logged, never just hidden.
 */
final class SearchService {

    /** The header box: rows in all, and the most sections while records match. */
    public const LEGACY_LIMIT      = 10;
    public const LEGACY_VIEW_QUOTA = 3;
    /** @var list<string> */
    public const LEGACY_KINDS = [ 'view', 'player', 'team', 'activity' ];

    /** Records need this many characters in the header box. */
    private const LEGACY_MIN_CHARS = 2;

    /**
     * The header box's answer.
     *
     * @param list<string> $types
     * @return list<array{type:string, id:int, label:string, sublabel:string, url:string}>
     */
    public function headerResults( string $raw, array $types, int $user_id ): array {
        // One scope decision per request: the per-player checks every kind
        // asks read the viewer's own links once (ScopeMemo).
        return ScopeMemo::run( fn(): array => $this->headerResultsInScope( $raw, $types, $user_id ) );
    }

    /**
     * @param list<string> $types
     * @return list<array{type:string, id:int, label:string, sublabel:string, url:string}>
     */
    private function headerResultsInScope( string $raw, array $types, int $user_id ): array {
        $types = $types === [] ? self::LEGACY_KINDS : $types;
        $query = new SearchQuery( $raw );

        $views = [];
        if ( in_array( 'view', $types, true ) ) {
            $sections = SearchRegistry::get( 'view' );
            if ( $sections instanceof Providers\SectionSearchProvider ) {
                $views = $query->raw === '' ? $sections->all( $user_id ) : $sections->match( $query, $user_id, self::LEGACY_LIMIT );
            }
        }

        $records = [];
        if ( $query->length >= self::LEGACY_MIN_CHARS ) {
            foreach ( [ 'player', 'team', 'activity' ] as $kind ) {
                if ( ! in_array( $kind, $types, true ) ) continue;
                $records = array_merge( $records, $this->confirmed( $kind, $query, $user_id, self::LEGACY_LIMIT ) );
            }
        }

        return array_map( [ self::class, 'legacyRow' ], self::merge( $views, $records ) );
    }

    /**
     * The paged answer.
     *
     * @param list<string> $types
     * @return array{items:list<array<string,mixed>>, counts:array<string,int>, understood:list<array{pattern:string, value:string, label:string}>, total:int}
     */
    public function page( SearchQuery $query, array $types, int $page, int $per_page, int $user_id ): array {
        return ScopeMemo::run( fn(): array => $this->pageInScope( $query, $types, $page, $per_page, $user_id ) );
    }

    /**
     * @param list<string> $types
     * @return array{items:list<array<string,mixed>>, counts:array<string,int>, understood:list<array{pattern:string, value:string, label:string}>, total:int}
     */
    private function pageInScope( SearchQuery $query, array $types, int $page, int $per_page, int $user_id ): array {
        $types = $types === [] ? SearchRegistry::kinds() : $types;
        $fetch = $page * $per_page;

        $hits   = [];
        $counts = [];
        $order  = array_flip( SearchRegistry::kinds() );
        foreach ( $types as $kind ) {
            $provider = SearchRegistry::get( $kind );
            if ( $provider === null ) continue;
            if ( $query->length < $provider->minChars() || $query->isEmpty() ) {
                $counts[ $kind ] = 0;
                continue;
            }
            $raw  = $provider->match( $query, $user_id, $fetch );
            $kept = $this->confirmRaw( $provider, $raw, $user_id );
            // A page short of full holds every match; only a full one needs
            // the count over the same scope. Either way the count follows the
            // confirm: a hit it dropped is not counted.
            $dropped         = count( $raw ) - count( $kept );
            $counts[ $kind ] = count( $raw ) < $fetch
                ? count( $kept )
                : max( 0, $provider->count( $query, $user_id ) - $dropped );
            foreach ( $kept as $hit ) {
                $hit['tt_kind_order'] = $order[ $kind ] ?? 99;
                $hits[] = $hit;
            }
        }

        usort( $hits, static fn( array $a, array $b ): int => [ $b['score'], $a['tt_kind_order'] ] <=> [ $a['score'], $b['tt_kind_order'] ] );
        $slice = array_slice( $hits, ( $page - 1 ) * $per_page, $per_page );

        return [
            'items'      => array_map( [ self::class, 'item' ], $slice ),
            'counts'     => $counts,
            'understood' => $query->understood,
            'total'      => array_sum( $counts ),
        ];
    }

    /**
     * A provider's matches, confirmed.
     *
     * @return list<array<string,mixed>>
     */
    private function confirmed( string $kind, SearchQuery $query, int $user_id, int $limit ): array {
        $provider = SearchRegistry::get( $kind );
        if ( $provider === null ) return [];
        return $this->confirmRaw( $provider, $provider->match( $query, $user_id, $limit ), $user_id );
    }

    /**
     * @param list<array<string,mixed>> $raw
     * @return list<array<string,mixed>>
     */
    private function confirmRaw( SearchProvider $provider, array $raw, int $user_id ): array {
        if ( $raw === [] ) return [];
        $kept = $provider->confirm( $user_id, $raw );
        if ( count( $kept ) < count( $raw ) ) {
            Logger::warning( 'search.confirm_dropped', [
                'kind'    => $provider->kind(),
                'dropped' => count( $raw ) - count( $kept ),
                'user_id' => $user_id,
            ] );
        }
        return $kept;
    }

    /**
     * Sections and records in one capped list: sections first, at most the
     * quota while records also match, the spare slots handed to whichever
     * side has more.
     *
     * @param list<array<string,mixed>> $views
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private static function merge( array $views, array $records ): array {
        if ( $records === [] ) return array_slice( $views, 0, self::LEGACY_LIMIT );
        if ( $views === [] ) return array_slice( $records, 0, self::LEGACY_LIMIT );

        $view_take   = min( count( $views ), self::LEGACY_VIEW_QUOTA );
        $record_take = min( count( $records ), self::LEGACY_LIMIT - $view_take );
        $view_take   = min( count( $views ), self::LEGACY_LIMIT - $record_take );

        return array_merge( array_slice( $views, 0, $view_take ), array_slice( $records, 0, $record_take ) );
    }

    /**
     * @param array<string,mixed> $hit
     * @return array{type:string, id:int, label:string, sublabel:string, url:string}
     */
    private static function legacyRow( array $hit ): array {
        return [
            'type'     => (string) $hit['type'],
            'id'       => (int) $hit['id'],
            'label'    => (string) $hit['label'],
            'sublabel' => (string) $hit['sublabel'],
            'url'      => (string) $hit['url'],
        ];
    }

    /**
     * The paged item: the header box's fields plus what a richer result
     * shows. No score, no internal keys.
     *
     * @param array<string,mixed> $hit
     * @return array<string,mixed>
     */
    public static function item( array $hit ): array {
        $item = self::legacyRow( $hit );
        foreach ( [ 'player_id', 'team_id' ] as $key ) {
            if ( isset( $hit[ $key ] ) && (int) $hit[ $key ] > 0 ) $item[ $key ] = (int) $hit[ $key ];
        }
        if ( isset( $hit['photo_url'] ) && (string) $hit['photo_url'] !== '' ) $item['photo_url'] = (string) $hit['photo_url'];
        $item['actions'] = array_values( (array) ( $hit['actions'] ?? [] ) );
        return $item;
    }
}
