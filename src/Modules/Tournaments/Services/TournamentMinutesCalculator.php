<?php
namespace TT\Modules\Tournaments\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TournamentMinutesCalculator (#3561, epic #3558) — how many minutes a
 * rotation plan gives one player in one fixture.
 *
 * This is the one copy of the tournament minutes maths. It used to live
 * inside `TournamentsRestController::computeTotals()`, keyed by tournament
 * and reachable only from the minutes ticker; the player file needs the
 * same answer keyed by **player**, across every tournament, and a second
 * copy would have drifted. `computeTotals()` is a caller now, and
 * `TournamentMinutesParityTest` pins the two together.
 *
 * ## The assumption, written down once
 *
 * A fixture's periods are equal length. `substitution_windows` is the
 * canonical source for how many there are — N windows make N+1 periods —
 * and each period is `round( duration_min / periods )`. That is what the
 * planner divides the pitch time by, so it is what the report has to
 * divide it by too. It is an assumption rather than a measurement, and it
 * is here, in one place, so a future change to how a tournament day is
 * structured has exactly one line to move.
 *
 * ## Where the minutes come from
 *
 * The **rotation plan**, never `tt_attendance`. There is no per-fixture
 * record of what was actually played: a tournament day's attendance is one
 * total for the day. Once a fixture completes the planner locks its
 * assignments, so the plan of a completed fixture *is* the rotation that
 * was used. The two are never added together (epic decision).
 */
final class TournamentMinutesCalculator {

    /** Named a position in the opening period. */
    public const ROLE_START = 'start';

    /** Played, but not from the first whistle. */
    public const ROLE_SUB = 'sub';

    /** In the squad for this fixture and on the bench for all of it. */
    public const ROLE_BENCH = 'bench';

    /** The `position_code` the planner writes for a bench slot. */
    public const BENCH = 'BENCH';

    /**
     * How one fixture divides up: its length, how many periods, and how
     * long each one is.
     *
     * @param mixed $windows The `substitution_windows` column, as the
     *                       stored JSON string or already decoded.
     * @return array{duration:int, periods:int, per_period:int}
     */
    public static function fixtureShape( int $duration_min, $windows ): array {
        $duration = max( 0, $duration_min );

        if ( is_string( $windows ) ) {
            $windows = json_decode( $windows, true );
        }
        $count = is_array( $windows ) ? count( $windows ) : 0;

        // N windows make N+1 periods, so there is always at least one and
        // the division never needs guarding.
        $periods    = $count + 1;
        $per_period = (int) round( $duration / $periods );

        return [
            'duration'   => $duration,
            'periods'    => $periods,
            'per_period' => $per_period,
        ];
    }

    /**
     * What one player's assignments in one fixture come to.
     *
     * A bench-only fixture is `0` minutes and role `bench` — the player was
     * there, which is not the same as not being in the squad, and the two
     * must not read the same on a child's record.
     *
     * @param array{duration:int, periods:int, per_period:int} $shape
     * @param list<array{period_index:int|string, position_code:string}> $assignments
     *        This player's rows for this fixture, bench rows included.
     * @return array{minutes:int, periods_played:int, started:bool, full:bool, role:string, positions:list<string>}
     */
    public static function forPlayer( array $shape, array $assignments ): array {
        $periods_played = [];
        $positions      = [];
        $started        = false;

        foreach ( $assignments as $row ) {
            $position = (string) $row['position_code'];
            $index    = (int) $row['period_index'];

            if ( $position === self::BENCH ) continue;

            $periods_played[ $index ] = true;
            if ( $position !== '' && ! in_array( $position, $positions, true ) ) {
                $positions[] = $position;
            }
            if ( $index === 0 ) $started = true;
        }

        $count   = count( $periods_played );
        $periods = (int) $shape['periods'];

        if ( $count === 0 ) {
            $role = self::ROLE_BENCH;
        } elseif ( $started ) {
            $role = self::ROLE_START;
        } else {
            $role = self::ROLE_SUB;
        }

        return [
            'minutes'        => $count * (int) $shape['per_period'],
            'periods_played' => $count,
            'started'        => $started,
            // Every period of the fixture, so a shortened plan cannot read
            // as a full match by having fewer periods to fill.
            'full'           => $periods > 0 && $count === $periods,
            'role'           => $role,
            'positions'      => $positions,
        ];
    }
}
