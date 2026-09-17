<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MatchesBlockOptions (#3516, epic #3513) — how much of the match section to
 * print.
 *
 * Three parts, three switches, because a staff meeting that is only about
 * results should not have to print three squad tables to see the record:
 *
 * - `record` — played, won, drawn, lost, goals for and against. On by default.
 * - `scorers` — goals and assists per player. On by default.
 * - `squads` — who played in each match and for how long. **Off** by default:
 *   it is the longest part of the section and it overlaps the minutes block,
 *   so a full report would otherwise print the same numbers twice.
 *
 * The defaults are recorded as absence, like every other block's (#3515): a
 * bag holding only defaults would make two compositions that render the same
 * report hash differently, and a saved view would stop reporting itself active.
 */
final class MatchesBlockOptions implements BlockOptionsInterface {

    public const RECORD  = 'record';
    public const SCORERS = 'scorers';
    public const SQUADS  = 'squads';

    /**
     * Option key => what it means when nothing is recorded.
     *
     * @var array<string,bool>
     */
    public const DEFAULTS = [
        self::RECORD  => true,
        self::SCORERS => true,
        self::SQUADS  => false,
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
            if ( $value !== null && $value !== $default ) $out[ $key ] = $value;
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
     * Asked through here rather than by reading the key, so a bag saved before
     * the option existed and one holding an unusable value are the same case:
     * both get the default.
     *
     * @param array<string,mixed> $options
     */
    public static function shows( array $options, string $part ): bool {
        $default = self::DEFAULTS[ $part ] ?? false;
        if ( ! array_key_exists( $part, $options ) ) return $default;

        $value = self::boolish( $options[ $part ] );

        return $value ?? $default;
    }

    /**
     * A checkbox arrives as "1" or "on", a URL as "0" or "false", stored JSON
     * as a real boolean. Null for anything that is none of those, so the
     * caller can tell "said no" from "said nothing usable".
     *
     * @param mixed $value
     */
    private static function boolish( $value ): ?bool {
        if ( is_bool( $value ) ) return $value;
        if ( is_int( $value ) ) return $value === 1 ? true : ( $value === 0 ? false : null );
        if ( ! is_string( $value ) ) return null;

        $value = strtolower( trim( $value ) );
        if ( in_array( $value, [ '1', 'true', 'on', 'yes' ], true ) )  return true;
        if ( in_array( $value, [ '0', 'false', 'off', 'no' ], true ) ) return false;

        return null;
    }

    /**
     * The labels for the section's switches, in the order the panel offers
     * them.
     *
     * @return array<string,string>
     */
    public static function labels(): array {
        return [
            self::RECORD  => _x( 'Record', 'monthly report matches option', 'talenttrack' ),
            self::SCORERS => _x( 'Scorers and assists', 'monthly report matches option', 'talenttrack' ),
            self::SQUADS  => _x( 'Squad and minutes per match', 'monthly report matches option', 'talenttrack' ),
        ];
    }
}
