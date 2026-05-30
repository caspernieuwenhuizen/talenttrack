<?php
namespace TT\Shared\Util;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerShortName (#1023) — resolve player display names to compact form
 * for surfaces where horizontal space is tight (match prep roster column,
 * pitch slot labels, Doen per speler column, Rollen pane, availability
 * drawer).
 *
 * Algorithm:
 *   - Default: first name only (`Daan`, `Senna`, `Javi`).
 *   - Disambiguation: when two players in the input set share a first
 *     name, both render as `<firstName> <lastInitial>` (`Daan P`, `Daan A`).
 *     The disambiguation scope is the input set, not the whole club.
 *   - Falls back to a usable string for players with missing first or
 *     last names (full last name, or '—').
 *
 * v1 assumes Western "first last" order. Per-locale name ordering
 * (East-Asian "last first" conventions) is out of scope.
 *
 * Players can be passed as objects (rows from `tt_players`) or as
 * arrays with `id` / `first_name` / `last_name` keys — both shapes
 * occur in the codebase (PHP repository rows vs JS-bound dictionaries).
 */
class PlayerShortName {

    /**
     * Resolve a list of players into a `[ player_id => short_name ]` map.
     *
     * @param iterable<int|string, object|array<string,mixed>> $players
     * @return array<int, string>  player_id => short display name
     */
    public static function resolve( iterable $players ): array {
        $first_counts = [];
        $normalised   = [];

        foreach ( $players as $p ) {
            $row = self::normalise( $p );
            if ( $row['id'] <= 0 ) continue;
            $normalised[ $row['id'] ] = $row;
            $key = self::firstKey( $row['first'] );
            if ( $key === '' ) continue;
            $first_counts[ $key ] = ( $first_counts[ $key ] ?? 0 ) + 1;
        }

        $out = [];
        foreach ( $normalised as $pid => $row ) {
            $first = $row['first'];
            $last  = $row['last'];
            $key   = self::firstKey( $first );

            if ( $first === '' && $last === '' ) {
                $out[ $pid ] = '—';
                continue;
            }
            if ( $first === '' ) {
                $out[ $pid ] = $last;
                continue;
            }
            if ( $last === '' || ( $first_counts[ $key ] ?? 0 ) <= 1 ) {
                $out[ $pid ] = $first;
                continue;
            }
            $initial = self::firstChar( $last );
            $out[ $pid ] = $initial === '' ? $first : $first . ' ' . $initial;
        }

        return $out;
    }

    /**
     * @param object|array<string,mixed> $p
     * @return array{id:int,first:string,last:string}
     */
    private static function normalise( $p ): array {
        if ( is_array( $p ) ) {
            $id    = isset( $p['id'] ) ? (int) $p['id'] : 0;
            $first = isset( $p['first_name'] ) ? trim( (string) $p['first_name'] ) : '';
            $last  = isset( $p['last_name'] )  ? trim( (string) $p['last_name'] )  : '';
        } else {
            $id    = isset( $p->id ) ? (int) $p->id : 0;
            $first = isset( $p->first_name ) ? trim( (string) $p->first_name ) : '';
            $last  = isset( $p->last_name )  ? trim( (string) $p->last_name )  : '';
        }
        return [ 'id' => $id, 'first' => $first, 'last' => $last ];
    }

    private static function firstKey( string $first ): string {
        if ( $first === '' ) return '';
        if ( function_exists( 'mb_strtolower' ) ) {
            return mb_strtolower( $first, 'UTF-8' );
        }
        return strtolower( $first );
    }

    private static function firstChar( string $s ): string {
        if ( $s === '' ) return '';
        if ( function_exists( 'mb_substr' ) ) {
            return mb_strtoupper( mb_substr( $s, 0, 1, 'UTF-8' ), 'UTF-8' );
        }
        return strtoupper( substr( $s, 0, 1 ) );
    }
}
