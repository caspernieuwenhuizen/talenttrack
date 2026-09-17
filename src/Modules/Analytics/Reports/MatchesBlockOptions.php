<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MatchesBlockOptions (#3516, epic #3513) — how much of the results section
 * to print.
 *
 * The section carries three things and a meeting rarely wants all three. A
 * staff meeting about results wants the record and who scored; a meeting about
 * exposure wants the per-match squads, which are the longest part of the
 * report and overlap the minutes section.
 *
 * So the squads default **off**. Both other parts default on: a results
 * section with no record is not a results section.
 *
 * Following `TestsBlockOptions`, a default is recorded as **absence**. Two
 * compositions that render the same report have to compare equal, because
 * `TeamMonthlyReportComposition::same()` hashes the bag — `['show_record' =>
 * true]` reading as a different report from `[]` is how a saved view stops
 * matching the preset it was made from.
 */
final class MatchesBlockOptions implements BlockOptionsInterface {

    public const SHOW_RECORD  = 'show_record';
    public const SHOW_SCORERS = 'show_scorers';
    public const SHOW_SQUADS  = 'show_squads';

    /** Option key => what it does when nobody says otherwise. */
    private const DEFAULTS = [
        self::SHOW_RECORD  => true,
        self::SHOW_SCORERS => true,
        self::SHOW_SQUADS  => false,
    ];

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalise( array $raw ): array {
        $out = [];
        foreach ( self::DEFAULTS as $key => $default ) {
            if ( ! array_key_exists( $key, $raw ) ) continue;
            $value = self::boolish( $raw[ $key ] );
            if ( $value !== null && $value !== $default ) {
                $out[ $key ] = $value;
            }
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
            static fn( string $key ): bool => ! array_key_exists( $key, self::DEFAULTS )
        ) );
    }

    /**
     * Is this part of the section switched on?
     *
     * The report asks through here rather than reading the key, so the default
     * lives in one place: a bag saved before the option existed and a bag that
     * never mentioned it are the same case.
     *
     * @param array<string,mixed> $options
     */
    public static function shows( array $options, string $key ): bool {
        if ( ! array_key_exists( $key, self::DEFAULTS ) ) return false;

        $value = array_key_exists( $key, $options ) ? self::boolish( $options[ $key ] ) : null;

        return $value ?? self::DEFAULTS[ $key ];
    }

    /**
     * The same option bag arrives from a checkbox, a query string and stored
     * JSON, so `false`, `"0"`, `"false"` and `""` all have to mean off. A value
     * that means neither returns null and lets the default stand, rather than
     * being coerced to false — `"maybe"` is a malformed request, not a request
     * to hide the record.
     *
     * @param mixed $raw
     */
    private static function boolish( $raw ): ?bool {
        if ( is_bool( $raw ) ) return $raw;
        if ( is_int( $raw ) ) return $raw !== 0;
        if ( ! is_string( $raw ) ) return null;

        $value = strtolower( trim( $raw ) );
        if ( in_array( $value, [ '1', 'true', 'yes', 'on' ], true ) ) return true;
        if ( in_array( $value, [ '0', 'false', 'no', 'off', '' ], true ) ) return false;

        return null;
    }

    /**
     * The labels for the section's own controls, in the order it offers them.
     *
     * @return array<string,string>
     */
    public static function labels(): array {
        return [
            self::SHOW_RECORD  => _x( 'Record', 'monthly report matches option', 'talenttrack' ),
            self::SHOW_SCORERS => _x( 'Scorers and assists', 'monthly report matches option', 'talenttrack' ),
            self::SHOW_SQUADS  => _x( 'Squad per match', 'monthly report matches option', 'talenttrack' ),
        ];
    }

    /** @return list<string> */
    public static function keys(): array {
        return array_keys( self::DEFAULTS );
    }
}
