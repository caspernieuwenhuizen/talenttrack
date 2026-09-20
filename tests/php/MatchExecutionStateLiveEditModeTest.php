<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;

/**
 * #2261 / #3549 — the match-execution view derives its initial edit-mode and
 * whether it offers an Edit toggle from the execution state.
 *
 *   - Before kickoff and during play the controls are on screen
 *     (`data-edit-mode="on"`) and there is no toggle: before kickoff they are
 *     disabled until Start, during play they simply work (#3549).
 *   - PENDING_REVIEW keeps the #2222 read-only-by-default accidental-edit
 *     guard: opens "off", with the Edit toggle to turn editing on.
 *   - FINALIZED is read-only: "off", no toggle.
 */
final class MatchExecutionStateLiveEditModeTest extends WP_UnitTestCase {

    public function test_live_states_open_in_edit_mode_without_a_toggle(): void {
        foreach ( [
            MatchExecutionState::FIRST_HALF,
            MatchExecutionState::HALF_TIME,
            MatchExecutionState::SECOND_HALF,
        ] as $state ) {
            $this->assertTrue( MatchExecutionState::isLive( $state ), $state . ' should be live' );
            $this->assertTrue( MatchExecutionState::isEditable( $state ), $state . ' should be editable' );
            $this->assertTrue( MatchExecutionState::opensInEditMode( $state ), $state . ' should open in edit-mode on' );
            $this->assertFalse( MatchExecutionState::hasEditToggle( $state ), $state . ' should offer no Edit toggle' );
        }
    }

    public function test_not_started_shows_its_controls_without_a_toggle(): void {
        $this->assertFalse( MatchExecutionState::isLive( MatchExecutionState::NOT_STARTED ) );
        $this->assertFalse( MatchExecutionState::isEditable( MatchExecutionState::NOT_STARTED ) );
        $this->assertTrue( MatchExecutionState::opensInEditMode( MatchExecutionState::NOT_STARTED ) );
        $this->assertFalse( MatchExecutionState::hasEditToggle( MatchExecutionState::NOT_STARTED ) );
    }

    public function test_pending_review_stays_read_only_by_default_behind_the_toggle(): void {
        $this->assertFalse( MatchExecutionState::isLive( MatchExecutionState::PENDING_REVIEW ) );
        $this->assertTrue( MatchExecutionState::isEditable( MatchExecutionState::PENDING_REVIEW ) );
        $this->assertFalse( MatchExecutionState::opensInEditMode( MatchExecutionState::PENDING_REVIEW ) );
        $this->assertTrue( MatchExecutionState::hasEditToggle( MatchExecutionState::PENDING_REVIEW ) );
    }

    public function test_finalized_is_read_only_with_no_toggle(): void {
        $this->assertFalse( MatchExecutionState::isLive( MatchExecutionState::FINALIZED ) );
        $this->assertFalse( MatchExecutionState::isEditable( MatchExecutionState::FINALIZED ) );
        $this->assertFalse( MatchExecutionState::opensInEditMode( MatchExecutionState::FINALIZED ) );
        $this->assertFalse( MatchExecutionState::hasEditToggle( MatchExecutionState::FINALIZED ) );
    }

    /**
     * #3849 — which half's line-up is on the pitch. Half time answers the
     * first half: the second-half XI takes the pitch at the kick-off after
     * the interval, not at the whistle before it.
     */
    public function test_half_reached_follows_the_state(): void {
        foreach ( [
            MatchExecutionState::NOT_STARTED,
            MatchExecutionState::FIRST_HALF,
            MatchExecutionState::HALF_TIME,
            '',
        ] as $state ) {
            $this->assertSame( 1, MatchExecutionState::halfReached( $state ), $state . ' is still the first half' );
        }

        foreach ( [
            MatchExecutionState::SECOND_HALF,
            MatchExecutionState::PENDING_REVIEW,
            MatchExecutionState::FINALIZED,
            MatchExecutionState::FINISHED,
        ] as $state ) {
            $this->assertSame( 2, MatchExecutionState::halfReached( $state ), $state . ' has reached the second half' );
        }
    }
}
