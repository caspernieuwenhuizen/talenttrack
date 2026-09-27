<?php
namespace TT\Modules\MatchExecution\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MatchStints (#3850) — the spells a player spent on the pitch, derived
 * from the half line-ups and the substitution log.
 *
 * Minutes and the squad timeline were each derived from their own pair of
 * maps — one "came off" minute and one "came on" minute per player per
 * half. A second event for the same player in the same half overwrote the
 * first, so a player who came off and went back on lost the second spell
 * entirely: a starter off at 20' and back at 25' was credited 20 minutes
 * instead of 30, and a substitute on at 10', off at 25' and back at 30'
 * was credited nothing at all, because `max( 0, 25 - 30 )` is zero. Coming
 * back on is two taps during a match and common in youth football, so the
 * shape could not hold.
 *
 * A player is on the pitch over a set of intervals, not between one pair of
 * minutes, so that is what this walks the log to build. Both surfaces read
 * it, which is the other half of the bug: the timeline drew bars agreeing
 * with the wrong total rather than exposing it.
 *
 * Minutes are absolute — the second half starts at the first half's length
 * — so a timeline can draw them on one 0'→FT track.
 */
final class MatchStints {

    /**
     * Every spell on the pitch, per player, as absolute `[on, off]` minutes.
     *
     * Walks each half in order: everyone in that half's line-up is on from
     * its first minute, a `player_off` closes their current spell, a
     * `player_on` opens one, and whatever is still open at the final
     * whistle closes there. A substitution that contradicts the pitch —
     * taking off somebody who is not on it, bringing on somebody who
     * already is — changes nothing rather than inventing a negative spell.
     *
     * @param iterable<object> $subs           non-reversed substitutions, chronological (->half, ->minute_in_half, ->player_off_id, ->player_on_id)
     * @param list<int>        $starting_half1
     * @param list<int>        $starting_half2
     * @return array<int, list<array{0:int,1:int}>> player_id => spells
     */
    public static function intervals(
        iterable $subs,
        array $starting_half1,
        array $starting_half2,
        int $half1_length,
        int $half2_length
    ): array {
        return self::intervalsForPeriods(
            $subs,
            [ 1 => $starting_half1, 2 => $starting_half2 ],
            [ 1 => $half1_length, 2 => $half2_length ]
        );
    }

    /**
     * The same walk over any number of periods (#4059).
     *
     * `intervals()` is the two-halves case of this. The line-up column is a
     * period number rather than a pair of halves, so a match played in
     * quarters walks four periods here instead of losing the last two.
     *
     * Periods are walked in ascending order of their key, each starting where
     * the previous one ended. A period with no line-up starts empty, and a
     * substitution naming a period that is not in `$lengths` is ignored.
     *
     * @param iterable<object>     $subs     non-reversed substitutions, chronological
     * @param array<int,list<int>> $lineups  period => player ids on at its first minute
     * @param array<int,int>       $lengths  period => its length in minutes
     * @return array<int, list<array{0:int,1:int}>> player_id => spells
     */
    public static function intervalsForPeriods( iterable $subs, array $lineups, array $lengths ): array {
        $rows = [];
        foreach ( $subs as $sub ) $rows[] = $sub;

        ksort( $lengths );

        $intervals = [];
        $offset    = 0;

        foreach ( $lengths as $half => $length ) {
            $half     = (int) $half;
            $starting = $lineups[ $half ] ?? [];
            $half_end = $offset + max( 0, (int) $length );

            /** @var array<int,int> $open player_id => minute their current spell began */
            $open = [];
            foreach ( $starting as $pid ) {
                $pid = (int) $pid;
                if ( $pid > 0 ) $open[ $pid ] = $offset;
            }

            foreach ( $rows as $sub ) {
                if ( (int) ( $sub->half ?? 0 ) !== $half ) continue;
                $minute = $offset + max( 0, (int) ( $sub->minute_in_half ?? 0 ) );
                $minute = max( $offset, min( $half_end, $minute ) );

                $off = (int) ( $sub->player_off_id ?? 0 );
                $on  = (int) ( $sub->player_on_id ?? 0 );

                if ( $off > 0 && isset( $open[ $off ] ) ) {
                    self::close( $intervals, $off, $open[ $off ], $minute );
                    unset( $open[ $off ] );
                }
                // Already on: a repeated "on" would otherwise restart the
                // spell and lose the minutes before it.
                if ( $on > 0 && ! isset( $open[ $on ] ) ) {
                    $open[ $on ] = $minute;
                }
            }

            foreach ( $open as $pid => $start ) {
                self::close( $intervals, (int) $pid, (int) $start, $half_end );
            }

            $offset = $half_end;
        }

        // The walk visits the periods in order, and within a period in
        // minute order, so each player's spells come out chronological.
        return $intervals;
    }

    /**
     * What each player's match looked like from the touchline (#4059, #4060):
     * did they start, did they come on, did they go off.
     *
     * Read off the same spells the persisted minutes are summed from, so the
     * appearance columns and the minutes beside them cannot disagree.
     *
     *  - **started**  — on the pitch at minute 0;
     *  - **came_on**  — a spell begins after minute 0;
     *  - **went_off** — a spell ends before full time.
     *
     * A player on for both halves has two spells that touch at half time
     * (`[0,35]` and `[35,70]`); they are one stay on the pitch, so touching
     * spells are joined before anything is read. Without that every
     * ninety-minute player would "go off" at the break and "come on" again.
     *
     * Each fact is per match, not per event: a player who goes off and comes
     * back twice still came on once in this match, the way a start is.
     *
     * @param array<int, list<array{0:int,1:int}>> $intervals from `intervals()` / `intervalsForPeriods()`
     * @return array<int, array{started:bool, came_on:bool, went_off:bool}>
     */
    public static function appearances( array $intervals, int $full_time ): array {
        $out = [];
        foreach ( $intervals as $pid => $spells ) {
            $pid = (int) $pid;
            if ( $pid <= 0 || $spells === [] ) continue;

            usort( $spells, static function ( array $a, array $b ): int {
                return $a[0] <=> $b[0];
            } );

            $facts = [ 'started' => false, 'came_on' => false, 'went_off' => false ];
            $start = null;
            $end   = 0;
            foreach ( $spells as $spell ) {
                if ( $start !== null && $spell[0] <= $end ) {
                    $end = max( $end, $spell[1] );
                    continue;
                }
                if ( $start !== null ) self::readSpell( $facts, $start, $end, $full_time );
                $start = $spell[0];
                $end   = $spell[1];
            }
            if ( $start !== null ) self::readSpell( $facts, $start, $end, $full_time );

            $out[ $pid ] = $facts;
        }
        return $out;
    }

    /**
     * Minutes per player: the sum of their spells.
     *
     * @param array<int, list<array{0:int,1:int}>> $intervals
     * @return array<int, int>
     */
    public static function minutes( array $intervals ): array {
        $out = [];
        foreach ( $intervals as $pid => $spells ) {
            $total = 0;
            foreach ( $spells as $spell ) {
                $total += max( 0, $spell[1] - $spell[0] );
            }
            $out[ (int) $pid ] = $total;
        }
        return $out;
    }

    /**
     * @param array{started:bool, came_on:bool, went_off:bool} $facts
     */
    private static function readSpell( array &$facts, int $start, int $end, int $full_time ): void {
        if ( $start <= 0 ) {
            $facts['started'] = true;
        } else {
            $facts['came_on'] = true;
        }
        if ( $end < $full_time ) $facts['went_off'] = true;
    }

    /**
     * @param array<int, list<array{0:int,1:int}>> $intervals
     */
    private static function close( array &$intervals, int $player_id, int $start, int $end ): void {
        if ( $player_id <= 0 || $end <= $start ) return;
        $intervals[ $player_id ][] = [ $start, $end ];
    }
}
