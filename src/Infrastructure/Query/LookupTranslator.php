<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LookupTranslator — picks the right display text for a `tt_lookups` row
 * in the current user's locale.
 *
 * v3.6.0 introduces a `translations` JSON column on tt_lookups (see
 * migration 0014). The JSON is keyed by WP locale (e.g. `nl_NL`,
 * `de_DE`, `fr_FR`) and carries a `name` + optional `description` per
 * locale. Admin-added lookup values now translate cleanly without
 * forcing the developer to ship .po updates for every new row.
 *
 * Seeded lookups (position, foot_option, etc.) still work via the .po
 * because the translator falls back to `__($lookup->name, ...)` when
 * no matching translation is stored.
 */
class LookupTranslator {

    /**
     * Resolve the best display name for a lookup row.
     *
     * @param object|null $lookup Row from `tt_lookups` (or null-safe).
     */
    public static function name( ?object $lookup ): string {
        if ( ! $lookup ) return '';
        $raw = (string) ( $lookup->name ?? '' );
        if ( $raw === '' ) return '';

        $stored = self::storedForCurrentLocale( $lookup, 'name' );
        if ( $stored !== null && $stored !== '' ) {
            return $stored;
        }
        return (string) __( $raw, 'talenttrack' );
    }

    /**
     * Resolve the description text, same resolution chain as `name()`.
     */
    public static function description( ?object $lookup ): string {
        if ( ! $lookup ) return '';
        $raw = (string) ( $lookup->description ?? '' );
        if ( $raw === '' ) return '';

        $stored = self::storedForCurrentLocale( $lookup, 'description' );
        if ( $stored !== null && $stored !== '' ) {
            return $stored;
        }
        return (string) __( $raw, 'talenttrack' );
    }

    /**
     * Decode the `translations` JSON blob into an associative array of
     * locale => [name, description]. Safe on null / malformed input.
     *
     * @return array<string, array{name?:string,description?:string}>
     */
    public static function decode( ?object $lookup ): array {
        if ( ! $lookup ) return [];
        $raw = (string) ( $lookup->translations ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];
        $out = [];
        foreach ( $decoded as $locale => $fields ) {
            if ( ! is_string( $locale ) || ! is_array( $fields ) ) continue;
            $out[ $locale ] = [
                'name'        => isset( $fields['name'] ) ? (string) $fields['name'] : '',
                'description' => isset( $fields['description'] ) ? (string) $fields['description'] : '',
            ];
        }
        return $out;
    }

    /**
     * Encode translations back to JSON suitable for the `translations`
     * column. Strips empty strings so partial entries don't bloat the
     * blob. Returns null when the resulting set is empty so the DB
     * column stores NULL.
     *
     * @param array<string, array{name?:string,description?:string}> $input
     */
    public static function encode( array $input ): ?string {
        $clean = [];
        foreach ( $input as $locale => $fields ) {
            if ( ! is_string( $locale ) ) continue;
            $name = isset( $fields['name'] ) ? trim( (string) $fields['name'] ) : '';
            $desc = isset( $fields['description'] ) ? trim( (string) $fields['description'] ) : '';
            if ( $name === '' && $desc === '' ) continue;
            $entry = [];
            if ( $name !== '' ) $entry['name'] = $name;
            if ( $desc !== '' ) $entry['description'] = $desc;
            $clean[ $locale ] = $entry;
        }
        if ( ! $clean ) return null;
        return (string) wp_json_encode( $clean );
    }

    /**
     * Translate a lookup value addressed by (type, stored-name) without
     * the caller needing to fetch the row. Handy for consumers that
     * store the lookup name (e.g. `tt_players.preferred_foot = 'Right'`)
     * and want to render the translated version.
     *
     * Results are cached per-request — all consumers calling this in the
     * same page load share one `get_lookups()` query per lookup type.
     */
    public static function byTypeAndName( string $type, string $stored_name ): string {
        if ( $stored_name === '' ) return '';
        static $cache = [];
        if ( ! isset( $cache[ $type ] ) ) {
            $cache[ $type ] = [];
            foreach ( QueryHelpers::get_lookups( $type ) as $row ) {
                $cache[ $type ][ (string) $row->name ] = $row;
            }
        }
        $row = $cache[ $type ][ $stored_name ] ?? null;
        if ( $row === null ) {
            // Stored value doesn't match any current lookup row —
            // probably renamed. Best-effort: hand it to __() so the
            // .po can still translate seeded values.
            return (string) __( $stored_name, 'talenttrack' );
        }
        return self::name( $row );
    }

    /**
     * List of WP locales that actually have a .mo installed on the site,
     * plus the site's default locale. Guaranteed to include at least
     * en_US as a canonical option even on English-only installs.
     *
     * @return string[]
     */
    public static function installedLocales(): array {
        $available = function_exists( 'get_available_languages' ) ? (array) get_available_languages() : [];
        $site      = (string) ( function_exists( 'get_locale' ) ? get_locale() : 'en_US' );
        $locales   = array_unique( array_filter( array_merge( [ 'en_US', $site ], $available ) ) );
        sort( $locales );
        return $locales;
    }

    /**
     * Pick the value for the current request locale from a decoded
     * translations blob, returning null if nothing matches.
     */
    private static function storedForCurrentLocale( object $lookup, string $field ): ?string {
        $all = self::decode( $lookup );
        if ( ! $all ) return null;

        $locale = function_exists( 'determine_locale' )
            ? (string) determine_locale()
            : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );

        if ( isset( $all[ $locale ][ $field ] ) && $all[ $locale ][ $field ] !== '' ) {
            return (string) $all[ $locale ][ $field ];
        }

        // Try language-only match (e.g. 'nl_NL' → 'nl_BE', 'de_DE' → 'de_AT').
        $lang = substr( $locale, 0, 2 );
        if ( $lang !== '' ) {
            foreach ( $all as $loc => $fields ) {
                if ( substr( $loc, 0, 2 ) === $lang && isset( $fields[ $field ] ) && $fields[ $field ] !== '' ) {
                    return (string) $fields[ $field ];
                }
            }
        }
        return null;
    }
}
