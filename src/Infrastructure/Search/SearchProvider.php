<?php
namespace TT\Infrastructure\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SearchProvider (#4671) — one kind of thing search can find.
 *
 * The contract, the same for every kind:
 *
 *   - `match()` narrows **in SQL** (or, for an in-memory registry, before
 *     anything is returned) by club, demo scope and the viewer's own scope,
 *     so a row the viewer may not open is never fetched and then hidden.
 *   - `confirm()` asks each hit the record's own access check, batched. It
 *     is the second check, not the filter: a hit it drops is a defect,
 *     logged by the caller.
 *   - `count()` counts over the same scoped `WHERE`, so a count reveals
 *     nothing outside the scope either.
 *   - `hydrate()` loads hits by id, scoped and confirmed the same way, for
 *     the recent items.
 *
 * A hit is an array:
 * `{type, id, label, sublabel, url, score, date?, player_id?, team_id?, photo_url?, shirt_number?, position?, excerpt?, path?, disabled?, actions}`.
 * An `excerpt` is only ever text the viewer may read (#4936).
 *
 * @phpstan-type Hit array{type:string, id:int, label:string, sublabel:string, url:string, score:int, date?:string, player_id?:int, team_id?:int, photo_url?:string, shirt_number?:int, position?:string, excerpt?:string, path?:string, disabled?:bool, actions:list<array{key:string, label:string, url:string}>, key?:string}
 */
interface SearchProvider {

    /** The kind key, e.g. `player`. Also the `type` of its hits. */
    public function kind(): string;

    /** The shortest query this kind answers, in characters. */
    public function minChars(): int;

    /** Does it hold records with ids (and so recent items)? */
    public function isRecord(): bool;

    /**
     * The patterns this kind honours (`SearchQuery::PATTERN_*`). A query
     * carrying a pattern outside this list matches nothing of this kind.
     *
     * @return list<string>
     */
    public function patterns(): array;

    /**
     * The best hits for the query, scoped, at most `$limit`, ranked by score.
     *
     * @return list<array<string,mixed>>
     */
    public function match( SearchQuery $query, int $user_id, int $limit ): array;

    /** How many hits the scoped query has in all. */
    public function count( SearchQuery $query, int $user_id ): int;

    /**
     * The hits the record's own access check confirms.
     *
     * @param list<array<string,mixed>> $hits
     * @return list<array<string,mixed>>
     */
    public function confirm( int $user_id, array $hits ): array;

    /**
     * Hits by id, scoped and confirmed: what `GET /me/recent` shows. An id
     * that no longer resolves, or that the viewer may no longer open, is
     * left out.
     *
     * @param list<int> $ids
     * @return array<int, array<string,mixed>> id => hit
     */
    public function hydrate( int $user_id, array $ids ): array;
}
