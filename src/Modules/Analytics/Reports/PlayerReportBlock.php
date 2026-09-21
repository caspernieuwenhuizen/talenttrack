<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerReportBlock (#3872, epic #3871) — the fixed vocabulary of blocks a
 * player report is composed from.
 *
 * One list for the composer, the REST validator, the online view and the PDF,
 * as `TeamMonthlyReportBlock` is for the squad. `ALL` is the print order: what
 * the conversation is about first, the evidence after it.
 *
 * `letterhead` is not optional — a document that leaves the room without
 * saying which player and which period it covers is not a report.
 *
 * `notes` is a blank area to write in during the talk, as on the team report.
 * The player's staff notes are `thread_notes`, so the two are never confused.
 */
final class PlayerReportBlock {

    public const LETTERHEAD      = 'letterhead';
    public const STATUS          = 'status';
    public const TALKING_POINTS  = 'talking_points';
    public const RATINGS         = 'ratings';
    public const ATTENDANCE      = 'attendance';
    public const MINUTES         = 'minutes';
    public const GOALS           = 'goals';
    public const PDP             = 'pdp';
    public const NOTES           = 'notes';
    public const MATCHES         = 'matches';
    public const TESTS           = 'tests';
    public const JOURNEY         = 'journey';
    public const INJURIES        = 'injuries';
    public const BEHAVIOUR       = 'behaviour';
    public const POTENTIAL       = 'potential';
    public const THREAD_NOTES    = 'thread_notes';

    /** Print order. */
    public const ALL = [
        self::LETTERHEAD,
        self::STATUS,
        self::TALKING_POINTS,
        self::RATINGS,
        self::ATTENDANCE,
        self::MINUTES,
        self::GOALS,
        self::PDP,
        self::NOTES,
        self::MATCHES,
        self::TESTS,
        self::JOURNEY,
        self::INJURIES,
        self::BEHAVIOUR,
        self::POTENTIAL,
        self::THREAD_NOTES,
    ];

    /**
     * What a report opens on: the conversation set, sized for one page.
     * Everything else is one tick away.
     */
    public const DEFAULT_BLOCKS = [
        self::LETTERHEAD,
        self::STATUS,
        self::TALKING_POINTS,
        self::RATINGS,
        self::ATTENDANCE,
        self::MINUTES,
        self::GOALS,
        self::PDP,
        self::NOTES,
    ];

    public static function isValid( string $key ): bool {
        return in_array( $key, self::ALL, true );
    }

    /**
     * Keys that are not blocks, so a caller can refuse them rather than drop
     * them. A typo silently ignored renders a report missing a section nobody
     * asked to remove.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public static function unknown( array $keys ): array {
        return array_values( array_filter( $keys, static fn( string $k ): bool => ! self::isValid( $k ) ) );
    }

    /**
     * A selection in the order it was given, with the letterhead first. An
     * empty selection is the conversation set — unlike the team report, where
     * it is everything, because a player report with every block is several
     * pages nobody asked for.
     *
     * #3962 — the order is the coach's: the report prints its sections in the
     * order they were put in, so a conversation that starts with the tests has
     * the tests first. A selection that was never reordered arrives in print
     * order and stays in it. Unknown keys and repeats are dropped.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public static function normalise( array $keys ): array {
        if ( $keys === [] ) return self::DEFAULT_BLOCKS;

        $out = [ self::LETTERHEAD ];
        foreach ( $keys as $key ) {
            if ( self::isValid( $key ) && ! in_array( $key, $out, true ) ) $out[] = $key;
        }
        return $out;
    }

    /**
     * Do attendance and playing time sit next to each other in this order?
     * Then the printed copy sets them side by side, and the layout estimate
     * counts the pair as one row of figures. Apart, each prints where it was
     * put.
     *
     * @param list<string> $blocks
     */
    public static function pairsAttendance( array $blocks ): bool {
        $a = array_search( self::ATTENDANCE, $blocks, true );
        $m = array_search( self::MINUTES, $blocks, true );
        return $a !== false && $m !== false && abs( $a - $m ) === 1;
    }
}
