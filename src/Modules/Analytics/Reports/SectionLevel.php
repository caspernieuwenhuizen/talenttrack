<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SectionLevel (#4133, epic #4094) — the one Summary / Details choice every
 * team monthly report section with two levels of detail offers.
 *
 * One control a coach learns once, instead of a different option on every
 * section. Which sections have it, and what each level holds, is theirs:
 *
 * | Section       | Summary                                        | Details                                    |
 * | ------------- | ---------------------------------------------- | ------------------------------------------ |
 * | Evaluations   | category averages, coverage, movers, spread    | + the player × category grid               |
 * | Tests         | the stat strip per test                        | + the ranked readings (and the change)     |
 * | Attendance    | squad average, who is below, absences by kind  | the bar per player                         |
 * | Minutes share | median, norm, who is under it                  | the bar per player                         |
 * | Matches       | the record and the results                     | + scorers, and squads per match            |
 *
 * The level is one key, `level`, in the section's option bag. Each section
 * records its default as absence (`BlockOptionsInterface`), so which default
 * applies is the section's own: Details for attendance, minutes share and
 * matches, Summary for tests and evaluations — what each printed before the
 * choice existed.
 *
 * Whether a layout can print Details is not decided here but in
 * `TeamMonthlyReportLayout::supportsDetails()`, the one rule the panel, the
 * estimate and the paper all read.
 */
final class SectionLevel {

    public const KEY = 'level';

    public const SUMMARY = 'summary';
    public const DETAILS = 'details';

    /** @var list<string> */
    public const ALL = [ self::SUMMARY, self::DETAILS ];

    /** The sections that offer the choice, in print order. */
    public const BLOCKS = [
        TeamMonthlyReportBlock::EVALUATIONS,
        TeamMonthlyReportBlock::ATTENDANCE,
        TeamMonthlyReportBlock::MINUTES,
        TeamMonthlyReportBlock::MATCHES,
        TeamMonthlyReportBlock::TESTS,
    ];

    /** Does this section offer Summary / Details? */
    public static function offeredBy( string $block ): bool {
        return in_array( $block, self::BLOCKS, true );
    }

    /**
     * A level as given, or null when it is not one: the caller decides what
     * a missing or unusable level means, because the default is the section's.
     *
     * @param mixed $raw
     */
    public static function parse( $raw ): ?string {
        if ( ! is_string( $raw ) ) return null;
        $level = strtolower( trim( $raw ) );
        return in_array( $level, self::ALL, true ) ? $level : null;
    }

    /**
     * The two choices, in the order the panel offers them.
     *
     * @return array<string,string>
     */
    public static function labels(): array {
        return [
            self::SUMMARY => _x( 'Summary', 'monthly report section level', 'talenttrack' ),
            self::DETAILS => _x( 'Details', 'monthly report section level', 'talenttrack' ),
        ];
    }

    public static function label( string $level ): string {
        return self::labels()[ $level ] ?? '';
    }

    /**
     * A checkbox arrives as "1" or "on", a URL as "0" or "false", stored JSON
     * as a real boolean. Null for anything that is none of those, so the
     * caller can tell "said no" from "said nothing usable".
     *
     * @param mixed $value
     */
    public static function boolish( $value ): ?bool {
        if ( is_bool( $value ) ) return $value;
        if ( is_int( $value ) ) return $value === 1 ? true : ( $value === 0 ? false : null );
        if ( ! is_string( $value ) ) return null;

        $value = strtolower( trim( $value ) );
        if ( in_array( $value, [ '1', 'true', 'on', 'yes' ], true ) )  return true;
        if ( in_array( $value, [ '0', 'false', 'off', 'no' ], true ) ) return false;

        return null;
    }
}
