<?php
namespace TT\Infrastructure\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;

/**
 * ParentSearchService (#3806, #4673) — `GET /me/search`, a parent's search
 * over their own children's records, in the shape it has always answered.
 *
 * Since #4673 it decides nothing itself. The parent's activities,
 * evaluations, goals and messages are kinds of the shared engine
 * (`SearchService`), each scoped to the caller's own children (or own inbox)
 * before anything is fetched and confirmed per row. This class asks the
 * engine for those four kinds and composes the old response from what it
 * returns, so the route and the results page cannot disagree.
 *
 * **A miss looks the same whatever it missed.** A query that would match
 * another family's child returns exactly what a made-up name returns:
 * nothing, with no count and no suggestion.
 *
 * @phpstan-type Hit array{type: string, id: int, player_id: int, title: string,
 *                        subtitle: string, date: string, url: string}
 */
final class ParentSearchService {

    /** Per record type. A parent scanning a page does not want a hundred. */
    public const PER_TYPE = 10;

    public const TYPE_ACTIVITY   = 'activity';
    public const TYPE_EVALUATION = 'evaluation';
    public const TYPE_GOAL       = 'goal';
    public const TYPE_MESSAGE    = 'message';

    /** The kinds the parent search has always covered. */
    private const KINDS = [ self::TYPE_ACTIVITY, self::TYPE_EVALUATION, self::TYPE_GOAL, self::TYPE_MESSAGE ];

    /**
     * Search one parent's world.
     *
     * @return array{
     *   query: string,
     *   children: list<array{id:int, name:string}>,
     *   results: list<Hit>,
     *   total: int
     * }
     */
    public function search( int $parent_user_id, string $query ): array {
        $query = trim( $query );

        $named = [];
        foreach ( $parent_user_id > 0 ? ParentChildResolver::children( $parent_user_id ) : [] as $child ) {
            $row     = (array) $child;
            $named[] = [
                'id'   => (int) ( $row['id'] ?? 0 ),
                'name' => trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ),
            ];
        }

        $out = [
            'query'    => $query,
            'children' => $named,
            'results'  => [],
            'total'    => 0,
        ];
        // A user with no linked child has nothing to search here, whatever
        // else the engine would find for them.
        if ( $named === [] ) return $out;

        $engine  = new SearchService();
        $parsed  = new SearchQuery( $query );
        $results = [];
        foreach ( self::KINDS as $kind ) {
            $taken = 0;
            // Fetched with headroom: for a coach who is also a parent the
            // best activity hits can be their coached teams', which this
            // route leaves out.
            foreach ( $engine->hits( $parsed, $kind, $parent_user_id, self::PER_TYPE * 3 ) as $hit ) {
                // The route answers about the caller's own children: an
                // activity that belongs to none of them is not part of it.
                if ( $kind === self::TYPE_ACTIVITY && (int) ( $hit['player_id'] ?? 0 ) <= 0 ) continue;
                if ( ++$taken > self::PER_TYPE ) break;
                $results[] = [
                    'type'      => $kind,
                    'id'        => (int) $hit['id'],
                    'player_id' => (int) ( $hit['player_id'] ?? 0 ),
                    'title'     => (string) $hit['label'],
                    'subtitle'  => (string) $hit['sublabel'],
                    'date'      => (string) ( $hit['date'] ?? '' ),
                    'url'       => (string) $hit['url'],
                ];
            }
        }

        // Soonest first among what is still to come, then most recent of
        // what has been. A parent searching a session name almost always
        // wants the next one.
        $today = current_time( 'Y-m-d' );
        usort( $results, static function ( array $a, array $b ) use ( $today ): int {
            $a_next = $a['date'] >= $today;
            $b_next = $b['date'] >= $today;
            if ( $a_next !== $b_next ) return $a_next ? -1 : 1;
            return $a_next ? strcmp( $a['date'], $b['date'] ) : strcmp( $b['date'], $a['date'] );
        } );

        $out['results'] = $results;
        $out['total']   = count( $results );
        return $out;
    }

    /**
     * #4252 — is this caller someone the parent search is for?
     *
     * A linked child, or the parent persona without one yet (that parent is
     * told to ask the academy for the link).
     */
    public static function isParentCaller( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( ParentChildResolver::childCount( $user_id ) > 0 ) return true;
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\PersonaResolver' ) ) return false;

        return in_array( 'parent', \TT\Modules\Authorization\PersonaResolver::personasFor( $user_id ), true );
    }
}
