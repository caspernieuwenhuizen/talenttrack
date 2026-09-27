<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TeamMonthlyReportBlock (#3458, epic #3457) — the fixed vocabulary of blocks a
 * team monthly report is composed from.
 *
 * One list, shared by the composer, the REST validator, the online view and the
 * PDF exporter, so "which blocks exist" cannot be answered four ways. The
 * order of `ALL` is the order the report prints in: the thing the meeting is
 * about comes first, the evidence after it.
 *
 * `letterhead` is not optional. A document that leaves the building without
 * saying which team and which month it covers is not a report, so it is added
 * to every composition rather than offered as a choice.
 */
final class TeamMonthlyReportBlock {

    public const LETTERHEAD = 'letterhead';
    public const COVERAGE   = 'coverage';
    public const KPI        = 'kpi';
    public const STATUS     = 'status';
    public const ATTENDANCE = 'attendance';
    public const MINUTES    = 'minutes';
    public const MATCHES    = 'matches';
    public const ATTENTION  = 'attention';
    public const CHANGES    = 'changes';
    public const TESTS      = 'tests';
    public const ROSTER     = 'roster';
    public const NOTES      = 'notes';
    public const QUALITY    = 'quality';

    /** Print order. */
    public const ALL = [
        self::LETTERHEAD,
        self::COVERAGE,
        self::KPI,
        self::STATUS,
        self::ATTENDANCE,
        self::MINUTES,
        self::MATCHES,
        self::ATTENTION,
        self::CHANGES,
        self::TESTS,
        self::ROSTER,
        self::NOTES,
        self::QUALITY,
    ];

    /**
     * #4096 — the sections that open with a numbered header, on screen and
     * on paper. The letterhead, the coverage banner and the headline tiles
     * sit above the numbered sections and carry no header of their own.
     */
    public const HEADED = [
        self::STATUS,
        self::ATTENDANCE,
        self::MINUTES,
        self::MATCHES,
        self::ATTENTION,
        self::CHANGES,
        self::TESTS,
        self::ROSTER,
        self::NOTES,
        self::QUALITY,
    ];

    /**
     * A section's title, the same on screen and on paper (#4096).
     */
    public static function title( string $key ): string {
        switch ( $key ) {
            case self::LETTERHEAD: return _x( 'Letterhead', 'team monthly report section', 'talenttrack' );
            case self::COVERAGE:   return _x( 'Data coverage', 'team monthly report section', 'talenttrack' );
            case self::KPI:        return _x( 'Headline numbers', 'team monthly report section', 'talenttrack' );
            case self::STATUS:     return _x( 'Squad status', 'team monthly report section', 'talenttrack' );
            case self::ATTENDANCE: return _x( 'Attendance', 'team monthly report section', 'talenttrack' );
            case self::MINUTES:    return _x( 'Minutes share', 'team monthly report section', 'talenttrack' );
            case self::MATCHES:    return _x( 'Matches', 'team monthly report section', 'talenttrack' );
            case self::ATTENTION:  return _x( 'Needs a conversation', 'team monthly report section', 'talenttrack' );
            case self::CHANGES:    return _x( 'What changed', 'team monthly report section', 'talenttrack' );
            case self::TESTS:      return _x( 'Tests', 'team monthly report section', 'talenttrack' );
            case self::ROSTER:     return _x( 'Player by player', 'team monthly report section', 'talenttrack' );
            case self::NOTES:      return _x( 'Decisions and actions', 'team monthly report section', 'talenttrack' );
            case self::QUALITY:    return _x( 'Data quality', 'team monthly report section', 'talenttrack' );
        }
        return '';
    }

    public static function isValid( string $key ): bool {
        return in_array( $key, self::ALL, true );
    }

    /**
     * Keys that are not blocks, so a caller can refuse them rather than drop
     * them. Silently ignoring a typo would render a report missing a section
     * nobody asked to remove.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public static function unknown( array $keys ): array {
        return array_values( array_filter( $keys, static fn( string $k ): bool => ! self::isValid( $k ) ) );
    }

    /**
     * A composition in print order with the letterhead guaranteed. An empty
     * selection means "everything", which is what a first-time generation
     * should show. Unknown keys must be refused by the caller first — they
     * are dropped here only so this method has one job.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public static function normalise( array $keys ): array {
        if ( $keys === [] ) return self::ALL;

        $wanted = array_flip( $keys );
        $wanted[ self::LETTERHEAD ] = true;

        return array_values( array_filter( self::ALL, static fn( string $k ): bool => isset( $wanted[ $k ] ) ) );
    }
}
