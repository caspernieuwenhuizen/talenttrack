<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;

/**
 * #2268 / #2269 / #2271 — repository-level guards for the match-execution
 * overhaul:
 *
 *   - #2269 `reverseSubstitution()` soft-deletes a logged sub (mirrors the
 *     goal-event undo) so `listSubstitutions()` skips it thereafter.
 *   - #2268 `onPitchPlayerIds()` derives the on-pitch roster from the
 *     starting XI + the non-reversed sub log, which the REST layer uses to
 *     reject roster-impossible swaps. #3849 makes it a question about a
 *     point in the match: which half's line-up took the pitch, and which
 *     substitutions had happened by then.
 *   - #2271 `reopenForCorrections()` transitions a FINALIZED execution back
 *     to PENDING_REVIEW, and is a no-op from any other state.
 */
final class MatchExecutionRepositoryUndoTest extends WP_UnitTestCase {

    private string $t_exec;
    private string $t_subs;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $this->t_exec = $wpdb->prefix . 'tt_match_execution';
        $this->t_subs = $wpdb->prefix . 'tt_match_execution_substitutions';
    }

    private function seedExecution( string $state = MatchExecutionState::SECOND_HALF ): int {
        global $wpdb;
        $wpdb->insert( $this->t_exec, [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => 1,
            'activity_id'   => 4242,
            'match_prep_id' => 1,
            'state'         => $state,
            'home_score'    => 0,
            'away_score'    => 0,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seedSub( int $exec_id, string $uuid, int $off, int $on, int $half = 2, int $minute = 60 ): void {
        global $wpdb;
        $wpdb->insert( $this->t_subs, [
            'event_uuid'     => $uuid,
            'club_id'        => 1,
            'execution_id'   => $exec_id,
            'half'           => $half,
            'minute_in_half' => $minute,
            'player_off_id'  => $off,
            'player_on_id'   => $on,
        ] );
    }

    public function test_reverse_substitution_soft_deletes_and_hides_from_list(): void {
        $exec_id = $this->seedExecution();
        $this->seedSub( $exec_id, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', 10, 20 );

        $repo = new MatchExecutionRepository();
        $this->assertCount( 1, $repo->listSubstitutions( $exec_id ), 'sub is listed before undo' );

        $this->assertTrue( $repo->reverseSubstitution( 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa' ) );
        $this->assertCount( 0, $repo->listSubstitutions( $exec_id ), 'reversed sub is hidden from the list' );
    }

    public function test_reverse_substitution_is_idempotent_and_rejects_empty_uuid(): void {
        $exec_id = $this->seedExecution();
        $this->seedSub( $exec_id, 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb', 11, 21 );
        $repo = new MatchExecutionRepository();

        $this->assertFalse( $repo->reverseSubstitution( '' ), 'empty uuid is rejected' );
        $this->assertTrue( $repo->reverseSubstitution( 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb' ) );
        // A second reverse is a harmless no-op UPDATE (still "succeeds").
        $this->assertTrue( $repo->reverseSubstitution( 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb' ) );
    }

    public function test_on_pitch_roster_reflects_starting_xi_plus_non_reversed_subs(): void {
        $exec_id  = $this->seedExecution();
        $starting = [ 1, 2, 3, 4, 5 ];
        // Sub: 5 off, 15 on, second half. Roster becomes 1..4 + 15.
        $this->seedSub( $exec_id, 'cccccccc-cccc-4ccc-cccc-cccccccccccc', 5, 15 );

        $repo    = new MatchExecutionRepository();
        $on_pitch = $repo->onPitchPlayerIds( $exec_id, $starting, [], 2 );

        sort( $on_pitch );
        $this->assertSame( [ 1, 2, 3, 4, 15 ], $on_pitch, 'subbed-off leaves, subbed-on joins' );
        $this->assertNotContains( 5, $on_pitch, 'the subbed-off player is no longer on the pitch' );
        $this->assertContains( 15, $on_pitch, 'the subbed-on player is on the pitch' );
    }

    public function test_on_pitch_roster_ignores_a_reversed_sub(): void {
        $exec_id  = $this->seedExecution();
        $starting = [ 1, 2, 3 ];
        $this->seedSub( $exec_id, 'dddddddd-dddd-4ddd-dddd-dddddddddddd', 3, 30 );
        ( new MatchExecutionRepository() )->reverseSubstitution( 'dddddddd-dddd-4ddd-dddd-dddddddddddd' );

        $on_pitch = ( new MatchExecutionRepository() )->onPitchPlayerIds( $exec_id, $starting, [], 2 );
        sort( $on_pitch );
        $this->assertSame( [ 1, 2, 3 ], $on_pitch, 'a reversed sub does not change the roster' );
    }

    // ---- #3849: the pitch is a question about a point in the match -------

    /**
     * The bug as filed: a match with a second-half line-up and no
     * substitutions logged had the four players who played the second half
     * listed as available on the bench, beside their own 35-minute bars.
     */
    public function test_a_second_half_line_up_is_the_pitch_at_the_final_whistle(): void {
        $exec_id = $this->seedExecution( MatchExecutionState::PENDING_REVIEW );

        $on_pitch = ( new MatchExecutionRepository() )->onPitchPlayerIds(
            $exec_id, [ 1, 2, 3, 4 ], [ 1, 2, 5, 6 ], 2
        );

        sort( $on_pitch );
        $this->assertSame( [ 1, 2, 5, 6 ], $on_pitch );
    }

    /** The same match, asked about the first half, answers the first XI. */
    public function test_the_first_half_still_answers_the_first_half_line_up(): void {
        $exec_id = $this->seedExecution( MatchExecutionState::PENDING_REVIEW );

        $on_pitch = ( new MatchExecutionRepository() )->onPitchPlayerIds(
            $exec_id, [ 1, 2, 3, 4 ], [ 1, 2, 5, 6 ], 1
        );

        sort( $on_pitch );
        $this->assertSame( [ 1, 2, 3, 4 ], $on_pitch );
    }

    /**
     * A second-half line-up replaces the pitch at the interval, so a
     * first-half substitution has already been accounted for by it and must
     * not be applied a second time on top.
     */
    public function test_a_first_half_sub_does_not_carry_into_a_second_half_line_up(): void {
        $exec_id = $this->seedExecution( MatchExecutionState::PENDING_REVIEW );
        $this->seedSub( $exec_id, 'eeeeeeee-eeee-4eee-eeee-eeeeeeeeeeee', 3, 9, 1, 20 );

        $on_pitch = ( new MatchExecutionRepository() )->onPitchPlayerIds(
            $exec_id, [ 1, 2, 3, 4 ], [ 1, 2, 5, 6 ], 2
        );

        sort( $on_pitch );
        $this->assertSame( [ 1, 2, 5, 6 ], $on_pitch );
        $this->assertNotContains( 9, $on_pitch, 'the half-2 line-up already says who takes the pitch' );
    }

    /** No half-2 line-up: the first XI plays on, and so do its subs. */
    public function test_without_a_second_half_line_up_the_first_xi_plays_on(): void {
        $exec_id = $this->seedExecution( MatchExecutionState::PENDING_REVIEW );
        $this->seedSub( $exec_id, 'ffffffff-ffff-4fff-ffff-ffffffffffff', 3, 9, 1, 20 );
        $this->seedSub( $exec_id, '11111111-1111-4111-1111-111111111111', 4, 10, 2, 50 );

        $on_pitch = ( new MatchExecutionRepository() )->onPitchPlayerIds(
            $exec_id, [ 1, 2, 3, 4 ], [], 2
        );

        sort( $on_pitch );
        $this->assertSame( [ 1, 2, 9, 10 ], $on_pitch );
    }

    /**
     * A line-up of empty slots is not a line-up. Otherwise a prep that
     * merely has half-2 rows with no players would empty the pitch.
     */
    public function test_an_empty_second_half_line_up_is_no_line_up(): void {
        $exec_id = $this->seedExecution( MatchExecutionState::PENDING_REVIEW );

        $on_pitch = ( new MatchExecutionRepository() )->onPitchPlayerIds(
            $exec_id, [ 1, 2, 3 ], [ 0, 0 ], 2
        );

        sort( $on_pitch );
        $this->assertSame( [ 1, 2, 3 ], $on_pitch );
    }

    /**
     * The minute narrows the half being asked about, so a forgotten
     * first-half swap is judged before the swaps that followed it — and
     * everything in an earlier half still counts, because that half is over.
     */
    public function test_the_minute_narrows_the_half_being_asked_about(): void {
        $exec_id = $this->seedExecution( MatchExecutionState::PENDING_REVIEW );
        $this->seedSub( $exec_id, '22222222-2222-4222-2222-222222222222', 3, 9, 1, 20 );
        $this->seedSub( $exec_id, '33333333-3333-4333-3333-333333333333', 4, 10, 1, 30 );

        $repo = new MatchExecutionRepository();

        $at_25 = $repo->onPitchPlayerIds( $exec_id, [ 1, 2, 3, 4 ], [], 1, 25 );
        sort( $at_25 );
        $this->assertSame( [ 1, 2, 4, 9 ], $at_25, 'the 30th-minute swap has not happened yet' );

        $at_35 = $repo->onPitchPlayerIds( $exec_id, [ 1, 2, 3, 4 ], [], 1, 35 );
        sort( $at_35 );
        $this->assertSame( [ 1, 2, 9, 10 ], $at_35 );
    }

    /** A first-half swap is not judged against the second half's pitch. */
    public function test_an_earlier_half_counts_in_full_when_a_later_minute_is_asked(): void {
        $exec_id = $this->seedExecution( MatchExecutionState::PENDING_REVIEW );
        $this->seedSub( $exec_id, '44444444-4444-4444-4444-444444444444', 3, 9, 1, 20 );
        $this->seedSub( $exec_id, '55555555-5555-4555-5555-555555555555', 9, 11, 2, 60 );

        $on_pitch = ( new MatchExecutionRepository() )->onPitchPlayerIds(
            $exec_id, [ 1, 2, 3, 4 ], [], 2, 10
        );

        sort( $on_pitch );
        $this->assertSame( [ 1, 2, 4, 9 ], $on_pitch, 'the first half is over; the 60th minute has not happened' );
    }

    public function test_reopen_transitions_finalized_back_to_pending_review(): void {
        $exec_id = $this->seedExecution( MatchExecutionState::FINALIZED );
        $repo    = new MatchExecutionRepository();

        $this->assertTrue( $repo->reopenForCorrections( $exec_id ) );

        global $wpdb;
        $state = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT state FROM {$this->t_exec} WHERE id = %d",
            $exec_id
        ) );
        $this->assertSame( MatchExecutionState::PENDING_REVIEW, $state );
    }

    public function test_reopen_is_a_no_op_from_a_non_finalized_state(): void {
        $exec_id = $this->seedExecution( MatchExecutionState::PENDING_REVIEW );
        $repo    = new MatchExecutionRepository();

        $this->assertFalse( $repo->reopenForCorrections( $exec_id ), 'only FINALIZED can be re-opened' );

        global $wpdb;
        $state = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT state FROM {$this->t_exec} WHERE id = %d",
            $exec_id
        ) );
        $this->assertSame( MatchExecutionState::PENDING_REVIEW, $state, 'state is unchanged' );
    }
}
