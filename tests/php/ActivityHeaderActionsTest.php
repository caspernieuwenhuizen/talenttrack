<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Modules\Activities\Services\ActivityHeaderActions;
use TT\Modules\Activities\Services\ActivityRatingProgress;

/**
 * #2685 — the activity detail header's run actions are status-aware.
 *
 * The header used to build Edit, match prep, the match-day CTA and the
 * training-run CTA before it had read `activity_status_key`, so a
 * completed training offered "Run this training" and a played match still
 * offered "Start match". The resolvers under test carry the rule that
 * fixes it: planned is the only status that may mutate the record;
 * everything else gets a read affordance, or nothing when there is
 * nothing to read.
 */
final class ActivityHeaderActionsTest extends WP_UnitTestCase {

    // ---- match prep: the label finally tracks whether a prep exists ----

    public function test_match_prep_label_reflects_whether_a_prep_exists(): void {
        $this->assertSame(
            'Plan match prep',
            ActivityHeaderActions::matchPrepLabel( true, false ),
            'planned match, no prep row — the wizard entry state'
        );
        $this->assertSame(
            'Match prep',
            ActivityHeaderActions::matchPrepLabel( true, true ),
            'planned match with a prep row is not still being "planned"'
        );
    }

    public function test_match_prep_reads_view_once_the_activity_is_no_longer_planned(): void {
        $this->assertSame( 'View match prep', ActivityHeaderActions::matchPrepLabel( false, true ) );
        $this->assertSame(
            'View match prep',
            ActivityHeaderActions::matchPrepLabel( false, false ),
            'a finished match never invites planning, prep row or not'
        );
    }

    // ---- match execution: no second kick-off on a finished match ----

    public function test_planned_match_keeps_the_full_state_machine(): void {
        $this->assertSame(
            'Resume match',
            ActivityHeaderActions::matchExecutionLabel( true, MatchExecutionState::FIRST_HALF, true )
        );
        $this->assertSame(
            'View match',
            ActivityHeaderActions::matchExecutionLabel( true, MatchExecutionState::FINALIZED, true )
        );
        $this->assertSame(
            'Start match',
            ActivityHeaderActions::matchExecutionLabel( true, MatchExecutionState::NOT_STARTED, true )
        );
        $this->assertNull(
            ActivityHeaderActions::matchExecutionLabel( true, MatchExecutionState::NOT_STARTED, false ),
            'off match day there is no start CTA (#1520)'
        );
    }

    public function test_completed_match_offers_view_only(): void {
        foreach ( [ MatchExecutionState::PENDING_REVIEW, MatchExecutionState::FINALIZED ] as $state ) {
            $this->assertSame(
                'View match',
                ActivityHeaderActions::matchExecutionLabel( false, $state, true ),
                "post-live state {$state} reads as View match"
            );
        }

        $this->assertNull(
            ActivityHeaderActions::matchExecutionLabel( false, MatchExecutionState::NOT_STARTED, true ),
            'no "Start match" on a completed activity, even on its own date — the #2685 report'
        );
        $this->assertNull(
            ActivityHeaderActions::matchExecutionLabel( false, MatchExecutionState::FIRST_HALF, true ),
            'no "Resume match" on a completed activity'
        );
    }

    // ---- training run ----

    public function test_planned_training_run_labels_are_unchanged(): void {
        $this->assertSame( 'Run this training', ActivityHeaderActions::trainingRunLabel( true, false ) );
        $this->assertSame( 'Continue this training', ActivityHeaderActions::trainingRunLabel( true, true ) );
    }

    public function test_finished_training_reads_view_and_hides_when_no_plan_ran(): void {
        $this->assertSame(
            'View this training',
            ActivityHeaderActions::trainingRunLabel( false, true ),
            'the plan that was run is still worth opening'
        );
        $this->assertNull(
            ActivityHeaderActions::trainingRunLabel( false, false ),
            'with no plan attached the button lands on the attach form — an invitation to start work on a closed record'
        );
    }

    // ---- rating: three states, and gone once everyone is rated ----

    public function test_rating_label_follows_progress_not_status(): void {
        $this->assertSame( 'Rate players', ActivityHeaderActions::ratingLabel( ActivityRatingProgress::NONE ) );
        $this->assertSame( 'Continue rating', ActivityHeaderActions::ratingLabel( ActivityRatingProgress::PARTIAL ) );
        $this->assertNull(
            ActivityHeaderActions::ratingLabel( ActivityRatingProgress::COMPLETE ),
            'nothing left to rate — review happens through the Ratings grid button in the same header'
        );
    }
}
