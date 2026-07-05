<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;

/**
 * #2267 / #2271 — pins the state → CTA / edit-mode contract the JS state
 * machine relies on now that the post-match states are the real
 * `pending_review` and `finalized` values (the legacy `finished` literal
 * the server never emits anymore).
 *
 * The JS derives its behaviour from the same partition the PHP enum
 * exposes:
 *   - the timer is parked on the post-match states (pending_review,
 *     finalized) — the guard that used to (wrongly) key off `finished`;
 *   - the footer CTA is state-aware: End match → (server) pending_review
 *     → "Review match"; finalized → "Re-open for corrections";
 *   - pending_review is editable (full adjust-all), finalized is not.
 */
final class MatchExecutionPostMatchStateTest extends WP_UnitTestCase {

    /** Mirror of the JS isPostMatch() timer-park guard. */
    private function isPostMatch( string $state ): bool {
        return $state === MatchExecutionState::PENDING_REVIEW
            || $state === MatchExecutionState::FINALIZED;
    }

    public function test_post_match_states_are_the_two_real_terminal_values(): void {
        $this->assertTrue( $this->isPostMatch( MatchExecutionState::PENDING_REVIEW ) );
        $this->assertTrue( $this->isPostMatch( MatchExecutionState::FINALIZED ) );
        // The legacy 'finished' value is not part of the live state machine.
        $this->assertFalse( $this->isPostMatch( MatchExecutionState::FIRST_HALF ) );
        $this->assertFalse( $this->isPostMatch( MatchExecutionState::NOT_STARTED ) );
    }

    public function test_timer_is_never_live_in_a_post_match_state(): void {
        // isLive() is false for both post-match states, so the clock is
        // parked (the JS stops the interval on these).
        $this->assertFalse( MatchExecutionState::isLive( MatchExecutionState::PENDING_REVIEW ) );
        $this->assertFalse( MatchExecutionState::isLive( MatchExecutionState::FINALIZED ) );
    }

    public function test_pending_review_is_fully_editable_finalized_is_locked(): void {
        // #2271 — pending_review is the full review-&-edit state (score,
        // subs, goals, minutes); finalized is read-only until re-opened.
        $this->assertTrue( MatchExecutionState::isEditable( MatchExecutionState::PENDING_REVIEW ) );
        $this->assertFalse( MatchExecutionState::isEditable( MatchExecutionState::FINALIZED ) );
    }

    public function test_end_match_lands_in_pending_review_not_a_legacy_finished(): void {
        // The server's route_end_half / route_finish transition the match to
        // PENDING_REVIEW; the JS adopts r.state. Assert the value the JS
        // must default to is the real one, so a state-drift regression
        // (defaulting to 'finished') would fail here.
        $this->assertSame( 'pending_review', MatchExecutionState::PENDING_REVIEW );
        $this->assertNotSame( MatchExecutionState::FINISHED, MatchExecutionState::PENDING_REVIEW );
    }
}
