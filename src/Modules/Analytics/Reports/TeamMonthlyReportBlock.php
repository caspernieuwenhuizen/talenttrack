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
