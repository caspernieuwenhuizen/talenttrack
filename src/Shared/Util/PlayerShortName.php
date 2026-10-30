<?php
namespace TT\Shared\Util;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerShortName (#1023) — resolve player display names to compact form
 * for surfaces where horizontal space is tight (match prep roster column,
 * pitch slot labels, Doen per speler column, Rollen pane, availability
 * drawer, the live match pitch, the activity Line-up card).
 *
 * Algorithm:
 *   - Default: first name only (`Daan`, `Senna`, `Javi`).
 *   - Disambiguation: when two players in the input set share a first
 *     name, both render as `<firstName> <initial>` (`Daan P`, `Daan A`),
 *     the initial of the main surname: prefix words are skipped, so
 *     "Dex Van der Laan" / "Dex Van Wijk" read `Dex L` / `Dex W` (#4372).
 *   - #4372 — when that still collides, the players that collide take more
 *     letters of the main surname until they differ (`Dex Vr`, `Dex Va`).
 *     When the surnames are identical too, the shirt number replaces the
 *     surname (`Dex #23`, `Dex #30`), short enough for a phone's pitch slot.
 *   - The disambiguation scope is the input set, not the whole club, so
 *     nobody gets a longer name without a reason on the surface showing it.
 *   - Falls back to a usable string for players with missing first or
 *     last names (full last name, or '—').
 *
 * v1 assumes Western "first last" order. Per-locale name ordering
 * (East-Asian "last first" conventions) is out of scope.
 *
 * Players can be passed as objects (rows from `tt_players`) or as
 * arrays with `id` / `first_name` / `last_name` keys — both shapes
 * occur in the codebase (PHP repository rows vs JS-bound dictionaries).
 * The shirt number is read from `jersey_number` (or `jersey`).
 */
class PlayerShortName {

    /**
     * #4372 — surname prefix words (Dutch tussenvoegsels and their common
     * neighbours) skipped when a surname has to tell two players apart.
     */
    private const SURNAME_PREFIXES = [
        'van', 'von', 'de', 'der', 'den', 'des', 'het', "'t", "'s", 'ten', 'ter', 'te',
        'in', 'op', 'aan', 'bij', 'uit', 'onder', 'over', 'voor', 'le', 'la', 'les',
        'du', 'da', 'di', 'del', 'della', 'dos', 'das', 'do', 'el', 'al', 'zu', 'vd',
    ];

    /**
     * Resolve a list of players into a `[ player_id => short_name ]` map.
     *
     * @param iterable<int|string, object|array<string,mixed>> $players
     * @return array<int, string>  player_id => short display name
     */
    public static function resolve( iterable $players ): array {
        $groups     = [];
        $normalised = [];

        foreach ( $players as $p ) {
            $row = self::normalise( $p );
            if ( $row['id'] <= 0 ) continue;
            $normalised[ $row['id'] ] = $row;
            $key = self::lower( $row['first'] );
            if ( $key === '' ) continue;
            $groups[ $key ][] = $row['id'];
        }

        $out = [];
        foreach ( $normalised as $pid => $row ) {
            $first = $row['first'];
            $last  = $row['last'];
            if ( $first === '' && $last === '' ) {
                $out[ $pid ] = '—';
                continue;
            }
            if ( $first === '' ) {
                $out[ $pid ] = $last;
                continue;
            }
            if ( count( $groups[ self::lower( $first ) ] ?? [] ) <= 1 ) {
                $out[ $pid ] = $first;
            }
        }

        foreach ( $groups as $ids ) {
            if ( count( $ids ) <= 1 ) continue;
            foreach ( self::disambiguate( $ids, $normalised ) as $pid => $label ) {
                $out[ $pid ] = $label;
            }
        }

        return $out;
    }

    /**
     * Players sharing a first name: the main surname's initial first, then
     * more of its letters for the ones still colliding, then the shirt
     * number for the ones whose surnames are identical.
     *
     * @param list<int> $ids
     * @param array<int, array{id:int,first:string,last:string,main:string,jersey:string}> $rows
     * @return array<int, string>
     */
    private static function disambiguate( array $ids, array $rows ): array {
        $letters = array_fill_keys( $ids, 1 );

        while ( true ) {
            $labels   = self::labelsAt( $ids, $rows, $letters );
            $clashing = self::clashing( $labels );
            $advanced = false;
            foreach ( $clashing as $pid ) {
                if ( $letters[ $pid ] < self::length( $rows[ $pid ]['main'] ) ) {
                    $letters[ $pid ]++;
                    $advanced = true;
                }
            }
            if ( ! $advanced ) break;
        }

        foreach ( $clashing as $pid ) {
            $jersey = $rows[ $pid ]['jersey'];
            if ( $jersey === '' ) continue;
            $labels[ $pid ] = $rows[ $pid ]['first'] . ' #' . $jersey;
        }

        return $labels;
    }

    /**
     * @param list<int> $ids
     * @param array<int, array{id:int,first:string,last:string,main:string,jersey:string}> $rows
     * @param array<int, int> $letters
     * @return array<int, string>
     */
    private static function labelsAt( array $ids, array $rows, array $letters ): array {
        $labels = [];
        foreach ( $ids as $pid ) {
            $labels[ $pid ] = self::label( $rows[ $pid ]['first'], $rows[ $pid ]['main'], $letters[ $pid ] );
        }
        return $labels;
    }

    /**
     * The players whose label another player in the set also has.
     *
     * @param array<int, string> $labels
     * @return list<int>
     */
    private static function clashing( array $labels ): array {
        $counts = [];
        foreach ( $labels as $label ) {
            $key = self::lower( $label );
            $counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
        }
        $out = [];
        foreach ( $labels as $pid => $label ) {
            if ( $counts[ self::lower( $label ) ] > 1 ) $out[] = (int) $pid;
        }
        return $out;
    }

    private static function label( string $first, string $last, int $letters ): string {
        $part = self::surnamePart( $last, $letters );
        return $part === '' ? $first : $first . ' ' . $part;
    }

    /** The first `$letters` letters of the surname, the first one upper-case. */
    private static function surnamePart( string $last, int $letters ): string {
        if ( $last === '' ) return '';
        if ( function_exists( 'mb_substr' ) ) {
            $head = mb_strtoupper( mb_substr( $last, 0, 1, 'UTF-8' ), 'UTF-8' );
            return $head . mb_substr( $last, 1, max( 0, $letters - 1 ), 'UTF-8' );
        }
        return strtoupper( substr( $last, 0, 1 ) ) . substr( $last, 1, max( 0, $letters - 1 ) );
    }

    /**
     * @param object|array<string,mixed> $p
     * @return array{id:int,first:string,last:string,main:string,jersey:string}
     */
    private static function normalise( $p ): array {
        $data   = is_array( $p ) ? $p : get_object_vars( $p );
        $id     = isset( $data['id'] ) ? (int) $data['id'] : 0;
        $first  = isset( $data['first_name'] ) ? trim( (string) $data['first_name'] ) : '';
        $last   = isset( $data['last_name'] )  ? trim( (string) $data['last_name'] )  : '';
        $jersey = trim( (string) ( $data['jersey_number'] ?? ( $data['jersey'] ?? '' ) ) );
        return [ 'id' => $id, 'first' => $first, 'last' => $last, 'main' => self::mainSurname( $last ), 'jersey' => $jersey ];
    }

    /**
     * The surname without its leading prefix words ("Van der Laan" → "Laan",
     * "de Jong" → "Jong"), which is the part a coach tells two players apart
     * by. A surname made of prefix words only keeps its last word.
     */
    private static function mainSurname( string $last ): string {
        $words = preg_split( '/\s+/u', $last, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $words ) || $words === [] ) return '';
        while ( count( $words ) > 1 && in_array( self::lower( (string) $words[0] ), self::SURNAME_PREFIXES, true ) ) {
            array_shift( $words );
        }
        return implode( ' ', $words );
    }

    private static function lower( string $s ): string {
        if ( $s === '' ) return '';
        if ( function_exists( 'mb_strtolower' ) ) {
            return mb_strtolower( $s, 'UTF-8' );
        }
        return strtolower( $s );
    }

    private static function length( string $s ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
    }
}
