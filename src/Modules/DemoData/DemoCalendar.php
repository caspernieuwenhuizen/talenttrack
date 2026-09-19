<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoCalendar — the run's shape in time: which seasons the history window
 * covers, when each season's evaluation rounds and PDP conversations fall,
 * and which days carry a training or a fixture.
 *
 * Four generators used to answer those questions separately and disagree.
 * The evaluation stream ran on its own two-a-week clock, the PDP cycle was
 * spaced across whatever single season happened to exist, and the fixture
 * list was a rule buried in the activity loop that nothing else could read
 * — so an evaluation was never evidence for a conversation and a match
 * evaluation was never about a match. One calendar, derived from the same
 * window, is what makes those line up (#3401, #3402).
 *
 * Pure and deterministic: same window, same answers, in any step of a run
 * split across thirty requests. Nothing here draws from the seeded RNG.
 */
final class DemoCalendar {

    /** Rounds per season. Fixed, not configurable — start, two mid, end (#3401). */
    public const ROUNDS_PER_SEASON = 4;

    /**
     * Where each round sits in its season, as a fraction of the season's
     * length. Not an even divide: the first round is a start-of-season
     * baseline and the last is the end-of-season review, which is what an
     * academy does and what a verdict is written against.
     *
     * @var list<float>
     */
    private const ROUND_FRACTIONS = [ 0.06, 0.36, 0.66, 0.96 ];

    /** Days between a round's evaluations and the conversation that reviews them. */
    private const EVIDENCE_LEAD_DAYS = 5;

    /** Weeks generated ahead of today, so a demo install has a next fixture (#3030). */
    public const HORIZON_WEEKS = 4;

    /**
     * The week grid, as days after Monday (ISO 1): trainings on Tuesday and
     * Thursday, and the fixture on Saturday, which is youth match day.
     *
     * These used to be offsets from the window start, and the window start
     * is "now minus N weeks", so the weekday was whatever day the generator
     * happened to run on. A Monday run put every match on a Thursday and
     * none on a Saturday (#3660).
     */
    private const TRAINING_DAYS = [ 1, 3 ];

    private const GAME_DAY = 5;

    /**
     * Wall-clock times per kind of slot, `H:i:s` as the TIME columns store
     * them. A training is an evening session; a game has a morning kick-off
     * and a time players report before it. Fixed rather than rolled, so the
     * same seed produces the same academy (#3676).
     *
     * @var array<string, array{start:string, end:string, presence:?string}>
     */
    public const SLOT_TIMES = [
        'training' => [ 'start' => '18:30:00', 'end' => '20:00:00', 'presence' => null ],
        'game'     => [ 'start' => '10:00:00', 'end' => '11:30:00', 'presence' => '09:15:00' ],
    ];

    private int $weeks;

    private int $now;

    public function __construct( int $weeks, ?int $now = null ) {
        $this->weeks = max( 1, $weeks );
        $this->now   = $now ?? time();
    }

    public function now(): int {
        return $this->now;
    }

    public function windowStart(): int {
        return $this->now - $this->weeks * WEEK_IN_SECONDS;
    }

    /**
     * The seasons the window touches, oldest first — one per season-year,
     * on the club's August-to-June convention (the same one `Activator`
     * seeds a fresh install with).
     *
     * @return list<array{index:int, name:string, start_date:string, end_date:string, is_current:bool}>
     */
    public function seasons(): array {
        $first = self::seasonYearOf( $this->windowStart() );
        $last  = self::seasonYearOf( $this->now );

        $out   = [];
        $index = 0;
        for ( $year = $first; $year <= $last; $year++ ) {
            $out[] = [
                'index'      => $index,
                'name'       => $year . '/' . ( $year + 1 ),
                'start_date' => $year . '-08-01',
                'end_date'   => ( $year + 1 ) . '-06-30',
                'is_current' => $year === $last,
            ];
            $index++;
        }
        return $out;
    }

    public function seasonCount(): int {
        return count( $this->seasons() );
    }

    /**
     * The index of the season a date belongs to. Dates before the first
     * season and inside the July gap between two seasons resolve to the
     * last season that had started — a training in July belongs to the
     * season just finished, not to nothing.
     */
    public function seasonIndexForDate( string $date ): int {
        $index = 0;
        foreach ( $this->seasons() as $season ) {
            if ( $date >= $season['start_date'] ) {
                $index = $season['index'];
            }
        }
        return $index;
    }

    /**
     * How far through the window a date sits, 0 at the oldest season's start
     * and 1 at the newest season's end.
     *
     * Measured in seasons rather than in days from the window's first
     * training: an archetype that climbs a step a season has to climb it
     * between the same two rounds whatever fraction of the earliest season
     * the window happens to start inside.
     */
    public function progressForDate( string $date ): float {
        $seasons = $this->seasons();
        $count   = count( $seasons );
        if ( $count === 0 ) return 1.0;

        $index  = $this->seasonIndexForDate( $date );
        $season = $seasons[ $index ];

        $start = (int) strtotime( (string) $season['start_date'] . ' 00:00:00 UTC' );
        $end   = (int) strtotime( (string) $season['end_date'] . ' 00:00:00 UTC' );
        $ts    = (int) strtotime( $date . ' 00:00:00 UTC' );

        $within = ( $end > $start && $ts > 0 )
            ? max( 0.0, min( 1.0, ( $ts - $start ) / ( $end - $start ) ) )
            : 0.0;

        return max( 0.0, min( 1.0, ( $index + $within ) / $count ) );
    }

    /**
     * When the four conversations of a season's PDP cycle are scheduled.
     *
     * @param array{start_date:string, end_date:string} $season
     * @return list<string> `Y-m-d`, ordered
     */
    public function conversationDates( array $season ): array {
        $start = (int) strtotime( $season['start_date'] . ' 00:00:00 UTC' );
        $end   = (int) strtotime( $season['end_date'] . ' 00:00:00 UTC' );
        if ( $start <= 0 || $end <= $start ) return [];

        $span = $end - $start;
        $out  = [];
        foreach ( self::ROUND_FRACTIONS as $fraction ) {
            $out[] = gmdate( 'Y-m-d', $start + (int) round( $span * $fraction ) );
        }
        return $out;
    }

    /**
     * When each round's evaluations are written — a few days ahead of the
     * conversation that reviews them, so `EvidencePacket::forConversation()`
     * finds them inside the window it opens at the previous conversation.
     *
     * @param array{start_date:string, end_date:string} $season
     * @return list<string> `Y-m-d`, ordered
     */
    public function roundDates( array $season ): array {
        $out = [];
        foreach ( $this->conversationDates( $season ) as $when ) {
            $ts = (int) strtotime( $when . ' 00:00:00 UTC' );
            if ( $ts <= 0 ) continue;
            $date = gmdate( 'Y-m-d', $ts - self::EVIDENCE_LEAD_DAYS * DAY_IN_SECONDS );
            $out[] = max( $date, (string) $season['start_date'] );
        }
        return $out;
    }

    /**
     * Every training and fixture slot the run writes, oldest first: two a
     * week across the window, plus the four-week horizon ahead of today,
     * with the second slot of every third week a game.
     *
     * The rule used to live inside `ActivityGenerator`'s loop, which is why
     * a "match evaluation" was never about a match — nothing else could see
     * which days were fixtures (#3401).
     *
     * Weeks run from the first Monday on or after the window start, so a
     * training is always on a Tuesday or Thursday and a fixture always on a
     * Saturday, whichever day the generator runs. On or after rather than
     * before: no slot lands ahead of the window `DemoRoster` opens the
     * squad's history at. `ts` is the slot's start time, so `is_future`
     * means "has not kicked off yet".
     *
     * @return list<array{date:string, ts:int, week:int, slot:int, is_game:bool, is_future:bool, subtype:?string, start_time:string, end_time:string, time_of_presence:?string}>
     */
    public function activitySlots(): array {
        $monday = $this->firstMondayOnOrAfter( $this->windowStart() );
        $total  = $this->weeks + self::HORIZON_WEEKS;
        $sub    = [ 'League', 'League', 'Cup', 'Friendly' ];

        $out = [];
        for ( $w = 0; $w < $total; $w++ ) {
            for ( $s = 0; $s < 2; $s++ ) {
                $is_game = ( $s === 1 && ( $w % 3 ) === 2 );
                $day     = $is_game ? self::GAME_DAY : self::TRAINING_DAYS[ $s ];
                $times   = self::SLOT_TIMES[ $is_game ? 'game' : 'training' ];
                $date_ts = $monday + ( ( $w * 7 ) + $day ) * DAY_IN_SECONDS;
                $ts      = $date_ts + self::secondsOfDay( $times['start'] );

                $out[] = [
                    'date'             => gmdate( 'Y-m-d', $date_ts ),
                    'ts'               => $ts,
                    'week'             => $w,
                    'slot'             => $s,
                    'is_game'          => $is_game,
                    'is_future'        => $ts >= $this->now,
                    'subtype'          => $is_game ? $sub[ $w % count( $sub ) ] : null,
                    'start_time'       => $times['start'],
                    'end_time'         => $times['end'],
                    'time_of_presence' => $times['presence'],
                ];
            }
        }
        return $out;
    }

    /** Midnight UTC of the first Monday on or after the day `$ts` falls on. */
    private function firstMondayOnOrAfter( int $ts ): int {
        $midnight = intdiv( $ts, DAY_IN_SECONDS ) * DAY_IN_SECONDS;
        $weekday  = (int) gmdate( 'N', $midnight );
        return $midnight + ( ( 8 - $weekday ) % 7 ) * DAY_IN_SECONDS;
    }

    private static function secondsOfDay( string $time ): int {
        return (int) substr( $time, 0, 2 ) * HOUR_IN_SECONDS + (int) substr( $time, 3, 2 ) * MINUTE_IN_SECONDS;
    }

    /**
     * The season-year a timestamp falls in. July starts the next one, which
     * is the convention `Activator` seeds and every generated season keeps.
     */
    private static function seasonYearOf( int $ts ): int {
        $year  = (int) gmdate( 'Y', $ts );
        $month = (int) gmdate( 'n', $ts );
        return $month >= 7 ? $year : $year - 1;
    }
}
