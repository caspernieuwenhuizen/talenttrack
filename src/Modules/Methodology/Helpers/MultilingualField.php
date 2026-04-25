<?php
namespace TT\Modules\Methodology\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MultilingualField — helpers for the JSON-keyed multilingual fields
 * used by the methodology catalogue tables.
 *
 * Storage shape (any string field):
 *   {"nl": "Aanvallend positiespel", "en": "Attacking positional play"}
 *
 * Storage shape (any array-of-strings field, e.g. tasks, bullets):
 *   {"nl": ["...", "..."], "en": ["...", "..."]}
 *
 * Locale resolution:
 *   1. Current WP locale (`determine_locale()`).
 *   2. Map the full locale (`nl_NL`) to a short code (`nl`).
 *   3. Try the short code → fallback to `nl` (source language for the
 *      shipped methodology) → fallback to `en` → empty string / array.
 *
 * Why short codes (`nl`, `en`) instead of full locales (`nl_NL`,
 * `en_US`): the methodology library is admin-curated content where
 * regional variants don't matter. `nl_BE` and `nl_NL` get the same
 * Dutch text. Short codes are also less work for Casper to author.
 */
final class MultilingualField {

    public const FALLBACK_PRIMARY = 'nl';
    public const FALLBACK_SECONDARY = 'en';

    /**
     * Resolve a stored JSON string to a locale-rendered scalar string.
     *
     * @param string|null $json   Raw column value (JSON or null)
     * @param string|null $locale Override locale; defaults to determine_locale()
     */
    public static function string( ?string $json, ?string $locale = null ): string {
        $decoded = self::decode( $json );
        if ( ! is_array( $decoded ) ) return '';
        $key = self::shortCode( $locale ?? determine_locale() );
        if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) && $decoded[ $key ] !== '' ) {
            return (string) $decoded[ $key ];
        }
        if ( isset( $decoded[ self::FALLBACK_PRIMARY ] ) && is_string( $decoded[ self::FALLBACK_PRIMARY ] ) ) {
            return (string) $decoded[ self::FALLBACK_PRIMARY ];
        }
        if ( isset( $decoded[ self::FALLBACK_SECONDARY ] ) && is_string( $decoded[ self::FALLBACK_SECONDARY ] ) ) {
            return (string) $decoded[ self::FALLBACK_SECONDARY ];
        }
        return '';
    }

    /**
     * Resolve a stored JSON array-of-strings to a locale-rendered array.
     *
     * @return string[]
     */
    public static function stringList( ?string $json, ?string $locale = null ): array {
        $decoded = self::decode( $json );
        if ( ! is_array( $decoded ) ) return [];
        $key = self::shortCode( $locale ?? determine_locale() );
        $candidates = [ $key, self::FALLBACK_PRIMARY, self::FALLBACK_SECONDARY ];
        foreach ( $candidates as $c ) {
            if ( isset( $decoded[ $c ] ) && is_array( $decoded[ $c ] ) ) {
                $list = [];
                foreach ( $decoded[ $c ] as $v ) {
                    if ( is_string( $v ) && $v !== '' ) $list[] = $v;
                }
                if ( ! empty( $list ) ) return $list;
            }
        }
        return [];
    }

    /**
     * Resolve a stored line-guidance JSON map (per-line strings or
     * arrays). Returns an array keyed by line slug:
     *   ['aanvallers' => 'string-or-list', 'middenvelders' => '...']
     *
     * Each line value is locale-rendered to a string; arrays are
     * joined by linebreaks for compact rendering. If you need the
     * raw list, decode + access directly.
     *
     * @return array<string,string>
     */
    public static function lineMap( ?string $json, ?string $locale = null ): array {
        $decoded = self::decode( $json );
        if ( ! is_array( $decoded ) ) return [];
        $out = [];
        foreach ( $decoded as $line => $payload ) {
            if ( ! is_string( $line ) ) continue;
            // Each line entry is itself a per-locale value: {"nl":"...","en":"..."}.
            // Re-use the string resolver.
            if ( is_string( $payload ) || ( is_array( $payload ) && self::looksLikeStringPayload( $payload ) ) ) {
                $value = is_string( $payload ) ? $payload : self::string( wp_json_encode( $payload ), $locale );
                $out[ (string) $line ] = $value;
            } elseif ( is_array( $payload ) && self::looksLikeStringList( $payload ) ) {
                $list = self::stringList( wp_json_encode( $payload ), $locale );
                $out[ (string) $line ] = implode( "\n", $list );
            }
        }
        return $out;
    }

    /**
     * Build a multilingual JSON value for storage. Pass an associative
     * array keyed by short locale.
     *
     * @param array<string,mixed> $values e.g. ['nl' => 'Naam', 'en' => 'Name']
     */
    public static function encode( array $values ): string {
        $clean = [];
        foreach ( $values as $k => $v ) {
            $key = self::shortCode( (string) $k );
            if ( is_array( $v ) ) {
                $arr = [];
                foreach ( $v as $entry ) {
                    if ( is_string( $entry ) && $entry !== '' ) $arr[] = $entry;
                }
                $clean[ $key ] = $arr;
            } elseif ( is_string( $v ) && $v !== '' ) {
                $clean[ $key ] = $v;
            }
        }
        return (string) wp_json_encode( $clean );
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function decode( ?string $json ): ?array {
        if ( ! is_string( $json ) || $json === '' ) return null;
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Map a full WP locale to the short code used in storage.
     */
    public static function shortCode( string $locale ): string {
        $base = strtolower( substr( $locale, 0, 2 ) );
        return $base !== '' ? $base : 'nl';
    }

    /** @param array<string,mixed> $arr */
    private static function looksLikeStringPayload( array $arr ): bool {
        foreach ( $arr as $k => $v ) {
            if ( is_string( $v ) ) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $arr */
    private static function looksLikeStringList( array $arr ): bool {
        foreach ( $arr as $k => $v ) {
            if ( is_array( $v ) ) return true;
        }
        return false;
    }
}
