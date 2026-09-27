<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EvaluationsBlockOptions (#4134, epic #4094) — what the team monthly report's
 * Evaluations section is told to show.
 *
 * - `level` — the Summary / Details choice every section shares
 *   (`SectionLevel`). Summary is the default: category averages, coverage,
 *   movers and spread. Details adds the player × category grid.
 * - `types` — which evaluation types count, as ids from the `eval_type`
 *   lookup. Empty means every type, which is the default, so an academy that
 *   adds a type later gets it counted without editing a saved report.
 * - `sub` — under Details, list the subcategories under their main category.
 *   The player report's `RatingsBlockOptions` choice (#3989) in this
 *   section's words; a request rather than a promise, since the section only
 *   shows it when something in the window was rated at subcategory level.
 *
 * Defaults are recorded as absence (`BlockOptionsInterface`).
 */
final class EvaluationsBlockOptions implements BlockOptionsInterface {

    public const LEVEL = SectionLevel::KEY;
    public const TYPES = 'types';
    public const SUB   = 'sub';

    public const DEFAULT_LEVEL = SectionLevel::SUMMARY;

    /** Past this many ids a type list is a hand-edited URL, not a choice. */
    private const MAX_TYPES = 30;

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalise( array $raw ): array {
        $out = [];

        $level = SectionLevel::parse( $raw[ self::LEVEL ] ?? null ) ?? self::DEFAULT_LEVEL;
        if ( $level !== self::DEFAULT_LEVEL ) $out[ self::LEVEL ] = $level;

        $types = self::ids( $raw[ self::TYPES ] ?? null );
        if ( $types !== [] ) $out[ self::TYPES ] = $types;

        if ( $level === SectionLevel::DETAILS && SectionLevel::boolish( $raw[ self::SUB ] ?? null ) === true ) {
            $out[ self::SUB ] = true;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $raw
     * @return list<string>
     */
    public static function unknownKeys( array $raw ): array {
        return array_values( array_filter(
            array_map( 'strval', array_keys( $raw ) ),
            static fn( string $key ): bool => ! in_array( $key, [ self::LEVEL, self::TYPES, self::SUB ], true )
        ) );
    }

    /** @param array<string,mixed> $options */
    public static function level( array $options ): string {
        return SectionLevel::parse( $options[ self::LEVEL ] ?? null ) ?? self::DEFAULT_LEVEL;
    }

    /**
     * The evaluation types that count; empty means every type.
     *
     * @param array<string,mixed> $options
     * @return list<int>
     */
    public static function typeIds( array $options ): array {
        return self::ids( $options[ self::TYPES ] ?? null );
    }

    /**
     * Under Details, are subcategories asked for?
     *
     * @param array<string,mixed> $options
     */
    public static function withSubcategories( array $options ): bool {
        return self::level( $options ) === SectionLevel::DETAILS
            && SectionLevel::boolish( $options[ self::SUB ] ?? null ) === true;
    }

    /**
     * A list of positive ids from a list or a comma-separated string, since
     * the option arrives from a checkbox group, a URL and stored JSON.
     *
     * @param mixed $raw
     * @return list<int>
     */
    private static function ids( $raw ): array {
        if ( $raw === null ) return [];
        $items = is_array( $raw ) ? $raw : ( is_scalar( $raw ) ? explode( ',', (string) $raw ) : [] );

        $out = [];
        foreach ( $items as $item ) {
            if ( ! is_scalar( $item ) ) continue;
            $id = (int) trim( (string) $item );
            if ( $id > 0 && ! in_array( $id, $out, true ) ) $out[] = $id;
            if ( count( $out ) >= self::MAX_TYPES ) break;
        }
        sort( $out );
        return $out;
    }

    /** The label of the subcategories switch. */
    public static function subLabel(): string {
        return _x( 'With subcategories', 'monthly report evaluations option', 'talenttrack' );
    }
}
