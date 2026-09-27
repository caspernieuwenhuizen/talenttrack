<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MatchesBlockOptions (#3516, epic #3513) — how much of the match section to
 * print.
 *
 * #4133 — the section takes the Summary / Details choice every section shares
 * (`SectionLevel`, key `level`):
 *
 * - **Summary** — the record and the results.
 * - **Details** — the default, and what the section printed before the
 *   choice: the summary plus the parts switched on under it.
 *
 * Under Details, two switches decide what is added, because a staff meeting
 * that is only about results should not have to print three squad tables:
 *
 * - `scorers` — goals and assists per player. On by default.
 * - `squads` — who played in each match and for how long. **Off** by default:
 *   it is the longest part of the section and it overlaps the minutes block,
 *   so a full report would otherwise print the same numbers twice.
 *
 * `record` — played, won, drawn, lost, goals — is part of both levels. It can
 * still be switched off, which a saved composition from before #4133 may have
 * done; that is kept rather than lost.
 *
 * A bag from before the level existed is mapped on read: scorers and squads
 * both off is the summary, anything else Details. Details with both off is
 * the summary by another name and is stored as the summary, so two
 * compositions that print the same section compare equal.
 *
 * The defaults are recorded as absence, like every other block's (#3515): a
 * bag holding only defaults would make two compositions that render the same
 * report hash differently, and a saved view would stop reporting itself active.
 */
final class MatchesBlockOptions implements BlockOptionsInterface {

    public const RECORD  = 'record';
    public const SCORERS = 'scorers';
    public const SQUADS  = 'squads';

    public const LEVEL = SectionLevel::KEY;

    public const DEFAULT_LEVEL = SectionLevel::DETAILS;

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

    /** The parts Details adds to the summary. */
    public const DETAIL_PARTS = [ self::SCORERS, self::SQUADS ];

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalise( array $raw ): array {
        $parts = [];
        foreach ( self::DEFAULTS as $key => $default ) {
            $value         = array_key_exists( $key, $raw ) ? SectionLevel::boolish( $raw[ $key ] ) : null;
            $parts[ $key ] = $value ?? $default;
        }

        $level = SectionLevel::parse( $raw[ self::LEVEL ] ?? null ) ?? self::DEFAULT_LEVEL;
        if ( $level === SectionLevel::DETAILS && ! $parts[ self::SCORERS ] && ! $parts[ self::SQUADS ] ) {
            $level = SectionLevel::SUMMARY;
        }

        $out = [];
        if ( $level !== self::DEFAULT_LEVEL ) $out[ self::LEVEL ] = $level;
        foreach ( self::DEFAULTS as $key => $default ) {
            // Under the summary the detail parts print nothing whatever they
            // say, so they are not kept.
            if ( $level === SectionLevel::SUMMARY && in_array( $key, self::DETAIL_PARTS, true ) ) continue;
            if ( $parts[ $key ] !== $default ) $out[ $key ] = $parts[ $key ];
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
            static fn( string $key ): bool => ! array_key_exists( $key, self::DEFAULTS ) && $key !== self::LEVEL
        ) );
    }

    /**
     * The level a composition asked for, defaulted.
     *
     * @param array<string,mixed> $options
     */
    public static function level( array $options ): string {
        return SectionLevel::parse( self::normalise( $options )[ self::LEVEL ] ?? null ) ?? self::DEFAULT_LEVEL;
    }

    /**
     * Does this part of the section print?
     *
     * Asked through here rather than by reading the key, so a bag saved before
     * the option existed and one holding an unusable value are the same case:
     * both get the default. The detail parts print only under Details.
     *
     * @param array<string,mixed> $options
     */
    public static function shows( array $options, string $part ): bool {
        if ( in_array( $part, self::DETAIL_PARTS, true ) && self::level( $options ) !== SectionLevel::DETAILS ) {
            return false;
        }
        return self::part( $options, $part );
    }

    /**
     * A part's own switch, whatever the level — what the panel ticks under
     * Details.
     *
     * @param array<string,mixed> $options
     */
    public static function part( array $options, string $part ): bool {
        $default = self::DEFAULTS[ $part ] ?? false;
        if ( ! array_key_exists( $part, $options ) ) return $default;

        return SectionLevel::boolish( $options[ $part ] ) ?? $default;
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
