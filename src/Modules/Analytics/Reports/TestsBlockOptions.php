<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TestsBlockOptions (#3515, epic #3513) — what the tests section is told to
 * show.
 *
 * The section used to report every test the squad took in the window, and only
 * ever as a summary: how many were tested, who moved which way. A meeting about
 * the sprint test got the jump test and the Yo-Yo alongside it, and never the
 * readings that would make any of them mean something.
 *
 * Two options:
 *
 * - `definitions` — which tests. Empty means every test in the window, which
 *   is what the section did before this existed and stays the default.
 * - `show` — how much per test: the summary, the readings, the change since
 *   each player's previous reading, or readings and change together.
 *
 * **An unknown value falls back; it does not fail.** A definition deleted since
 * the composition was saved, or a `show` value from a later version of the
 * report, must still open a document. Only an unknown *key* is reported, and
 * only to callers that asked for strictness — see `BlockOptionsInterface`.
 */
final class TestsBlockOptions implements BlockOptionsInterface {

    public const SHOW_SUMMARY      = 'summary';
    public const SHOW_VALUES       = 'values';
    public const SHOW_TREND        = 'trend';
    public const SHOW_VALUES_TREND = 'values_trend';

    /** @var list<string> */
    public const SHOW = [
        self::SHOW_SUMMARY,
        self::SHOW_VALUES,
        self::SHOW_TREND,
        self::SHOW_VALUES_TREND,
    ];

    public const DEFAULT_SHOW = self::SHOW_SUMMARY;

    /**
     * How many tests one composition may name.
     *
     * Not a storage limit — it is the point past which the option has stopped
     * being a choice. A composition naming fifty definitions is a hand-edited
     * URL, not a coach picking the tests for a meeting.
     */
    private const MAX_DEFINITIONS = 20;

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalise( array $raw ): array {
        $out = [];

        $definitions = self::definitions( $raw['definitions'] ?? null );
        if ( $definitions !== [] ) $out['definitions'] = $definitions;

        $show = is_scalar( $raw['show'] ?? null ) ? sanitize_key( (string) $raw['show'] ) : '';
        if ( in_array( $show, self::SHOW, true ) && $show !== self::DEFAULT_SHOW ) {
            $out['show'] = $show;
        }

        // Defaults are recorded as absence, so two compositions that render the
        // same report compare equal — `TeamMonthlyReportComposition::same()`
        // hashes the bag, and `['show' => 'summary']` would otherwise read as
        // a different report from `[]`.
        return $out;
    }

    /**
     * @param array<string,mixed> $raw
     * @return list<string>
     */
    public static function unknownKeys( array $raw ): array {
        $known = [ 'definitions', 'show' ];

        return array_values( array_filter(
            array_map( 'strval', array_keys( $raw ) ),
            static fn( string $key ): bool => ! in_array( $key, $known, true )
        ) );
    }

    /**
     * Which tests to show, as definition ids.
     *
     * Accepts a list or a comma-separated string, because the same option
     * arrives from a checkbox group, a URL and stored JSON. Order is the
     * caller's: a coach who put the sprint test first meant it first.
     *
     * @param mixed $raw
     * @return list<int>
     */
    private static function definitions( $raw ): array {
        if ( $raw === null ) return [];

        $items = is_array( $raw )
            ? $raw
            : ( is_scalar( $raw ) ? explode( ',', (string) $raw ) : [] );

        $out = [];
        foreach ( $items as $item ) {
            if ( ! is_scalar( $item ) ) continue;
            $id = (int) trim( (string) $item );
            if ( $id > 0 && ! in_array( $id, $out, true ) ) $out[] = $id;
            if ( count( $out ) >= self::MAX_DEFINITIONS ) break;
        }

        return $out;
    }

    /**
     * The `show` value a composition asked for, defaulted.
     *
     * The report asks through here rather than reading the key itself, so the
     * fallback lives in one place — an option bag saved before `show` existed
     * and one naming a value this version does not know are the same case.
     *
     * @param array<string,mixed> $options
     */
    public static function show( array $options ): string {
        $show = is_scalar( $options['show'] ?? null ) ? (string) $options['show'] : '';

        return in_array( $show, self::SHOW, true ) ? $show : self::DEFAULT_SHOW;
    }

    /**
     * The definition ids a composition asked for; empty means every test in
     * the window.
     *
     * @param array<string,mixed> $options
     * @return list<int>
     */
    public static function definitionIds( array $options ): array {
        return self::definitions( $options['definitions'] ?? null );
    }

    /** Does this `show` value put each player's reading on the page? */
    public static function showsValues( string $show ): bool {
        return $show === self::SHOW_VALUES || $show === self::SHOW_VALUES_TREND;
    }

    /** Does this `show` value put the change since the previous reading on the page? */
    public static function showsTrend( string $show ): bool {
        return $show === self::SHOW_TREND || $show === self::SHOW_VALUES_TREND;
    }

    /** Does this `show` value put a player table on the page at all? */
    public static function showsPlayers( string $show ): bool {
        return $show !== self::SHOW_SUMMARY;
    }

    /** The labels for the `show` picker, in the order it offers them. */
    public static function showLabels(): array {
        return [
            self::SHOW_SUMMARY      => _x( 'Summary only', 'monthly report tests option', 'talenttrack' ),
            self::SHOW_VALUES       => _x( 'Readings', 'monthly report tests option', 'talenttrack' ),
            self::SHOW_TREND        => _x( 'Change since last time', 'monthly report tests option', 'talenttrack' ),
            self::SHOW_VALUES_TREND => _x( 'Readings and change', 'monthly report tests option', 'talenttrack' ),
        ];
    }
}
