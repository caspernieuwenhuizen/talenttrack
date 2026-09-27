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
 * Three options:
 *
 * - `definitions` — which tests. Empty means every test in the window, which
 *   is what the section did before this existed and stays the default.
 * - `level` (#4133) — the Summary / Details choice every section shares
 *   (`SectionLevel`). Summary is the stat strip per test, and the default;
 *   Details adds each player's reading, ranked.
 * - `change` (#4133) — under Details, whether the ranked table also shows the
 *   change since each player's previous reading. On by default.
 *
 * #4133 folds the older four-way `show` into those two: `summary` is Summary;
 * `values`, `trend` and `values_trend` are Details, with the change on for
 * `trend` and `values_trend`. A saved view, a snapshot's composition or a
 * schedule that stored `show` is mapped on read, and REST still accepts it
 * for one release. What the report prints is still spelled as a `show` value
 * in its payload (`show()`), so a snapshot frozen before the choice existed
 * renders as it did.
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

    public const LEVEL  = SectionLevel::KEY;
    public const CHANGE = 'change';

    public const DEFAULT_LEVEL = SectionLevel::SUMMARY;

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

        [ $level, $change ] = self::levelAndChange( $raw );
        if ( $level === SectionLevel::DETAILS ) {
            $out[ self::LEVEL ] = $level;
            if ( ! $change ) $out[ self::CHANGE ] = false;
        }

        // Defaults are recorded as absence, so two compositions that render the
        // same report compare equal — `TeamMonthlyReportComposition::same()`
        // hashes the bag, and `['level' => 'summary']` would otherwise read as
        // a different report from `[]`.
        return $out;
    }

    /**
     * @param array<string,mixed> $raw
     * @return list<string>
     */
    public static function unknownKeys( array $raw ): array {
        // `show` is the deprecated spelling (#4133), still accepted.
        $known = [ 'definitions', self::LEVEL, self::CHANGE, 'show' ];

        return array_values( array_filter(
            array_map( 'strval', array_keys( $raw ) ),
            static fn( string $key ): bool => ! in_array( $key, $known, true )
        ) );
    }

    /**
     * The level and the change switch a raw bag asks for. `level` and
     * `change` win; a bag that has only the old `show` is mapped from it.
     *
     * @param array<string,mixed> $raw
     * @return array{0:string, 1:bool}
     */
    private static function levelAndChange( array $raw ): array {
        $level  = SectionLevel::parse( $raw[ self::LEVEL ] ?? null );
        $change = SectionLevel::boolish( $raw[ self::CHANGE ] ?? null );

        if ( $level === null ) {
            $show = is_scalar( $raw['show'] ?? null ) ? sanitize_key( (string) $raw['show'] ) : '';
            if ( in_array( $show, self::SHOW, true ) && $show !== self::SHOW_SUMMARY ) {
                $level  = SectionLevel::DETAILS;
                $change = $change ?? ( $show !== self::SHOW_VALUES );
            }
        }

        return [ $level ?? self::DEFAULT_LEVEL, $change ?? true ];
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
     * The level a composition asked for, defaulted.
     *
     * @param array<string,mixed> $options
     */
    public static function level( array $options ): string {
        return self::levelAndChange( $options )[0];
    }

    /**
     * Under Details, is the change since the previous reading shown?
     *
     * @param array<string,mixed> $options
     */
    public static function withChange( array $options ): bool {
        return self::levelAndChange( $options )[1];
    }

    /**
     * What the section prints, as the `show` value the report's payload and
     * renderer read: the summary, the readings, or the readings and change.
     *
     * The report asks through here rather than reading the keys itself, so
     * the fallback lives in one place — an option bag saved before the level
     * existed, one that still says `show`, and one naming a value this version
     * does not know all land here.
     *
     * @param array<string,mixed> $options
     */
    public static function show( array $options ): string {
        [ $level, $change ] = self::levelAndChange( $options );
        if ( $level !== SectionLevel::DETAILS ) return self::SHOW_SUMMARY;
        return $change ? self::SHOW_VALUES_TREND : self::SHOW_VALUES;
    }

    /**
     * A payload's `show` value as it was stored, or the summary when it is not
     * one. The payload is read as it stands rather than mapped like an option
     * bag, so a snapshot frozen with `trend` still prints the change alone.
     *
     * @param mixed $show
     */
    public static function storedShow( $show ): string {
        return is_string( $show ) && in_array( $show, self::SHOW, true ) ? $show : self::SHOW_SUMMARY;
    }

    /**
     * The level a `show` value prints at, for a payload — a snapshot's, too —
     * that spells what it prints the old way.
     */
    public static function levelOfShow( string $show ): string {
        return self::showsPlayers( $show ) ? SectionLevel::DETAILS : SectionLevel::SUMMARY;
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
        return in_array( $show, self::SHOW, true ) && $show !== self::SHOW_SUMMARY;
    }

    /** The label of the "with change" switch the panel shows under Details. */
    public static function changeLabel(): string {
        return _x( 'With change since the previous reading', 'monthly report tests option', 'talenttrack' );
    }
}
