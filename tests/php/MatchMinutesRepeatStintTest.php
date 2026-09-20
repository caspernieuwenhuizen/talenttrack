<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Modules\MatchExecution\Domain\MatchStints;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;

/**
 * #3850 — a player who comes off and goes back on in the same half.
 *
 * Minutes used to be derived from one "came off" and one "came on" minute
 * per player per half, so a second event overwrote the first: a starter off
 * at 20' and back at 25' was credited 20 minutes rather than 30, and a
 * substitute on at 10', off at 25' and back at 30' was credited nothing at
 * all — `max( 0, 25 - 30 )`. That figure is what `tt_attendance` stores, so
 * it reached the minutes report, the player's Minutes tab and the monthly
 * team report as a player who had not played.
 *
 * The spells are now walked once, in {@see MatchStints}, and both the
 * persisted minutes and the squad timeline read that walk — the timeline
 * used to hold a copy of the same shape, so its bars agreed with the wrong
 * total instead of exposing it.
 */
final class MatchMinutesRepeatStintTest extends WP_UnitTestCase {

    private const HALF = 35;

    private string $t_exec;
    private string $t_subs;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $this->t_exec = $wpdb->prefix . 'tt_match_execution';
        $this->t_subs = $wpdb->prefix . 'tt_match_execution_substitutions';
    }

    private function seedExecution(): int {
        global $wpdb;
        $wpdb->insert( $this->t_exec, [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => 1,
            'activity_id'   => 5150,
            'match_prep_id' => 1,
            'state'         => MatchExecutionState::PENDING_REVIEW,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seedSub( int $exec_id, int $half, int $minute, int $off, int $on ): void {
        global $wpdb;
        $wpdb->insert( $this->t_subs, [
            'event_uuid'     => wp_generate_uuid4(),
            'club_id'        => 1,
            'execution_id'   => $exec_id,
            'half'           => $half,
            'minute_in_half' => $minute,
            'player_off_id'  => $off,
            'player_on_id'   => $on,
        ] );
    }

    /** @return array<int,int> */
    private function minutes( int $exec_id, array $half1, array $half2 = [] ): array {
        return ( new MatchExecutionRepository() )->computeMinutes(
            $exec_id, $half1, $half2, self::HALF, self::HALF
        );
    }

    /** Row one of the table on the issue. */
    public function test_a_starter_back_on_in_the_same_half_keeps_both_spells(): void {
        $exec_id = $this->seedExecution();
        $this->seedSub( $exec_id, 1, 20, 5, 15 );
        $this->seedSub( $exec_id, 1, 25, 15, 5 );

        $minutes = $this->minutes( $exec_id, [ 1, 2, 3, 4, 5 ] );

        $this->assertSame( 30, $minutes[5], '0-20 plus 25-35' );
        $this->assertSame( 5, $minutes[15], 'the player who replaced him played 20-25' );
    }

    /** Row two — the one that recorded a player as not having played. */
    public function test_a_substitute_back_on_in_the_same_half_is_not_zeroed(): void {
        $exec_id = $this->seedExecution();
        $this->seedSub( $exec_id, 1, 10, 1, 15 );
        $this->seedSub( $exec_id, 1, 25, 15, 1 );
        $this->seedSub( $exec_id, 1, 30, 2, 15 );

        $minutes = $this->minutes( $exec_id, [ 1, 2, 3, 4, 5 ] );

        $this->assertSame( 20, $minutes[15], '10-25 plus 30-35' );
        $this->assertNotSame( 0, $minutes[15], 'twenty minutes on the pitch is not "did not play"' );
    }

    public function test_spells_sum_across_both_halves(): void {
        $exec_id = $this->seedExecution();
        $this->seedSub( $exec_id, 1, 20, 5, 15 );
        $this->seedSub( $exec_id, 1, 25, 15, 5 );
        $this->seedSub( $exec_id, 2, 10, 5, 15 );

        $minutes = $this->minutes( $exec_id, [ 1, 2, 3, 4, 5 ], [ 1, 2, 3, 4, 5 ] );

        $this->assertSame( 40, $minutes[5], '30 in the first half, 10 in the second' );
        $this->assertSame( 30, $minutes[15], '5 in the first half, 25 in the second' );
    }

    /** The common case has to come out exactly as it did before. */
    public function test_a_single_substitution_is_unchanged(): void {
        $exec_id = $this->seedExecution();
        $this->seedSub( $exec_id, 1, 10, 5, 15 );

        $minutes = $this->minutes( $exec_id, [ 1, 2, 3, 4, 5 ] );

        $this->assertSame( 35, $minutes[1], 'a starter who stays on plays the half' );
        $this->assertSame( 10, $minutes[5] );
        $this->assertSame( 25, $minutes[15] );
    }

    public function test_a_reversed_substitution_does_not_count(): void {
        global $wpdb;
        $exec_id = $this->seedExecution();
        $this->seedSub( $exec_id, 1, 10, 5, 15 );
        $wpdb->update(
            $this->t_subs,
            [ 'reversed_at' => current_time( 'mysql', true ) ],
            [ 'execution_id' => $exec_id ]
        );

        $minutes = $this->minutes( $exec_id, [ 1, 2, 3, 4, 5 ] );

        $this->assertSame( 35, $minutes[5] );
        $this->assertArrayNotHasKey( 15, $minutes );
    }

    // ---- the spells themselves, which the timeline draws ------------------

    /** @param array<array{int,int,int,int}> $subs half, minute, off, on */
    private static function subs( array $subs ): array {
        $out = [];
        foreach ( $subs as [ $half, $minute, $off, $on ] ) {
            $out[] = (object) [
                'half'           => $half,
                'minute_in_half' => $minute,
                'player_off_id'  => $off,
                'player_on_id'   => $on,
            ];
        }
        return $out;
    }

    public function test_the_timeline_draws_two_bars_with_a_gap(): void {
        $intervals = MatchStints::intervals(
            self::subs( [ [ 1, 20, 5, 15 ], [ 1, 25, 15, 5 ] ] ),
            [ 1, 5 ],
            [],
            self::HALF,
            self::HALF
        );

        $this->assertSame( [ [ 0, 20 ], [ 25, 35 ] ], $intervals[5] );
        $this->assertSame( [ [ 20, 25 ] ], $intervals[15] );
    }

    /** The second half sits after the first on one 0'→FT track. */
    public function test_second_half_spells_are_absolute_minutes(): void {
        $intervals = MatchStints::intervals(
            self::subs( [ [ 2, 10, 5, 15 ] ] ),
            [ 5 ],
            [ 5 ],
            self::HALF,
            self::HALF
        );

        $this->assertSame( [ [ 0, 35 ], [ 35, 45 ] ], $intervals[5] );
        $this->assertSame( [ [ 45, 70 ] ], $intervals[15] );
    }

    /** The total the timeline prints is the sum of the bars it drew. */
    public function test_the_timeline_total_equals_the_spells(): void {
        $intervals = MatchStints::intervals(
            self::subs( [ [ 1, 10, 1, 15 ], [ 1, 25, 15, 1 ], [ 1, 30, 2, 15 ] ] ),
            [ 1, 2 ],
            [],
            self::HALF,
            self::HALF
        );
        $minutes = MatchStints::minutes( $intervals );

        foreach ( $intervals as $pid => $spells ) {
            $sum = 0;
            foreach ( $spells as $spell ) $sum += $spell[1] - $spell[0];
            $this->assertSame( $sum, $minutes[ $pid ] );
        }
        $this->assertSame( 20, $minutes[15] );
    }

    /**
     * A log that contradicts the pitch changes nothing rather than
     * inventing a negative spell: nobody can come off who is not on, and
     * a second "on" must not restart a spell already running.
     */
    public function test_a_contradictory_log_invents_nothing(): void {
        $intervals = MatchStints::intervals(
            self::subs( [ [ 1, 10, 99, 1 ], [ 1, 20, 0, 1 ] ] ),
            [ 1 ],
            [],
            self::HALF,
            self::HALF
        );

        $this->assertSame( [ [ 0, 35 ] ], $intervals[1], 'the starter played the half, once' );
        $this->assertArrayNotHasKey( 99, $intervals );
    }

    /** A sub logged past the whistle cannot buy a player extra minutes. */
    public function test_a_minute_past_the_half_is_clamped_to_it(): void {
        $intervals = MatchStints::intervals(
            self::subs( [ [ 1, 44, 5, 15 ] ] ),
            [ 5 ],
            [],
            self::HALF,
            self::HALF
        );

        $this->assertSame( [ [ 0, 35 ] ], $intervals[5] );
        $this->assertArrayNotHasKey( 15, $intervals, 'nobody comes on after the whistle' );
    }
}
