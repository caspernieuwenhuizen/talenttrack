<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;

/**
 * #2261 — the match-execution view derives its initial edit-mode from the
 * execution state: a live in-progress match opens with the mutating controls
 * (sub / score / goal) already revealed, while post-match states keep the
 * #2222 read-only-by-default accidental-edit guard.
 *
 * The view computes `data-edit-mode` as
 * `MatchExecutionState::isLive( $state ) ? 'on' : 'off'`, so these pin the
 * state-partition contract that fix relies on:
 *
 *   - the three live states are "live" AND editable (open in edit-mode "on");
 *   - PENDING_REVIEW is editable but NOT live (opens "off", Edit-to-enable);
 *   - FINALIZED / NOT_STARTED are neither live nor editable.
 */
final class MatchExecutionStateLiveEditModeTest extends WP_UnitTestCase {

    private function initialEditMode( string $state ): string {
        return MatchExecutionState::isLive( $state ) ? 'on' : 'off';
    }

    public function test_live_states_open_in_edit_mode_on(): void {
        foreach ( [
            MatchExecutionState::FIRST_HALF,
            MatchExecutionState::HALF_TIME,
            MatchExecutionState::SECOND_HALF,
        ] as $state ) {
            $this->assertTrue( MatchExecutionState::isLive( $state ), $state . ' should be live' );
            $this->assertTrue( MatchExecutionState::isEditable( $state ), $state . ' should be editable' );
            $this->assertSame( 'on', $this->initialEditMode( $state ), $state . ' should open in edit-mode on' );
        }
    }

    public function test_pending_review_stays_read_only_by_default(): void {
        $this->assertFalse( MatchExecutionState::isLive( MatchExecutionState::PENDING_REVIEW ) );
        $this->assertTrue( MatchExecutionState::isEditable( MatchExecutionState::PENDING_REVIEW ) );
        $this->assertSame( 'off', $this->initialEditMode( MatchExecutionState::PENDING_REVIEW ) );
    }

    public function test_finalized_is_neither_live_nor_editable(): void {
        $this->assertFalse( MatchExecutionState::isLive( MatchExecutionState::FINALIZED ) );
        $this->assertFalse( MatchExecutionState::isEditable( MatchExecutionState::FINALIZED ) );
        $this->assertSame( 'off', $this->initialEditMode( MatchExecutionState::FINALIZED ) );
    }

    public function test_not_started_is_neither_live_nor_editable(): void {
        $this->assertFalse( MatchExecutionState::isLive( MatchExecutionState::NOT_STARTED ) );
        $this->assertFalse( MatchExecutionState::isEditable( MatchExecutionState::NOT_STARTED ) );
        $this->assertSame( 'off', $this->initialEditMode( MatchExecutionState::NOT_STARTED ) );
    }
}
