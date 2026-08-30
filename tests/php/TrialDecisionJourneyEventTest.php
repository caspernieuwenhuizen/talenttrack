<?php
namespace TT\Tests\Php;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Rest\TrialsRestController;
use TT\Modules\Workflow\Forms\AwaitTeamOfferDecisionForm;
use TT\Modules\Workflow\Forms\ReviewTrialGroupMembershipForm;
use WP_UnitTestCase;

/**
 * #3138 — a trial that ends closes on the player's timeline, whichever
 * screen ended it.
 *
 * `tt_trial_cases.decision` carries six values and
 * `TrialCasesRepository::recordDecision()` accepted three of them, so the
 * two workflow forms wrote the rest straight through `update()` and
 * announced nothing. A trial that ended because the family declined the
 * offered position showed on the player's timeline as a trial that
 * started and never finished.
 *
 * Two of the six say the opposite of an ending, and the assertions here
 * say so deliberately rather than by omission: `continue_in_trial_group`
 * means the trial is still running, and `offered_team_position` is
 * mid-conversation.
 */
final class TrialDecisionJourneyEventTest extends WP_UnitTestCase {

    private int $user_id = 0;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );
    }

    /* ---- the gap the issue was filed about ------------------------------ */

    public function test_a_declined_offer_closes_the_trial_on_the_timeline(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        ( new AwaitTeamOfferDecisionForm() )->serializeResponse(
            [ 'outcome' => 'declined', 'notes' => 'Family chose another club.' ],
            $this->task( $case_id, $player_id )
        );

        $this->assertSame( 1, $this->countEvents( $player_id, 'trial_ended' ) );
        $this->assertSame(
            TrialCaseDecision::DECLINED_OFFERED_POSITION,
            $this->decisionOf( $case_id )
        );
    }

    /**
     * The player's status is written once, by one owner. It used to be
     * written by the form on the accepted branch; the decision subscriber
     * owns it now, and the form's copy is gone.
     */
    public function test_an_accepted_offer_signs_the_player_once(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        ( new AwaitTeamOfferDecisionForm() )->serializeResponse(
            [ 'outcome' => 'accepted', 'notes' => 'Signed for the U15.' ],
            $this->task( $case_id, $player_id )
        );

        $this->assertSame( PlayerStatus::ACTIVE, $this->statusOf( $player_id ) );
        $this->assertSame( 1, $this->countEvents( $player_id, 'trial_ended' ) );
        $this->assertSame( 1, $this->countEvents( $player_id, 'signed' ), 'one signed entry, not one per writer' );
    }

    /** The family declining is not the club releasing them. */
    public function test_a_declined_offer_leaves_the_player_on_the_books(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        ( new AwaitTeamOfferDecisionForm() )->serializeResponse(
            [ 'outcome' => 'declined', 'notes' => 'Family chose another club.' ],
            $this->task( $case_id, $player_id )
        );

        $this->assertSame( PlayerStatus::INACTIVE, $this->statusOf( $player_id ) );
        $this->assertSame( 0, $this->countEvents( $player_id, 'released' ) );
    }

    /** Nothing was decided, so nothing is announced. */
    public function test_no_response_announces_nothing(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        ( new AwaitTeamOfferDecisionForm() )->serializeResponse(
            [ 'outcome' => 'no_response', 'notes' => 'Two reminders, no reply.' ],
            $this->task( $case_id, $player_id )
        );

        $this->assertSame( 0, $this->countEvents( $player_id, 'trial_ended' ) );
        $this->assertSame( PlayerStatus::TRIAL, $this->statusOf( $player_id ) );
        $this->assertSame( '', $this->decisionOf( $case_id ), 'no decision was taken' );
        $this->assertNotNull( $this->caseColumn( $case_id, 'archived_at' ) );
    }

    /* ---- the two that must stay silent, said deliberately ---------------- */

    public function test_continuing_in_the_trial_group_writes_no_ending(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        ( new ReviewTrialGroupMembershipForm() )->serializeResponse(
            [ 'decision' => 'continue_in_trial_group', 'rationale' => 'Another block to look at him.' ],
            $this->task( $case_id, $player_id )
        );

        $this->assertSame(
            0,
            $this->countEvents( $player_id, 'trial_ended' ),
            'the decision explicitly means the trial is still running'
        );
        $this->assertSame( TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP, $this->decisionOf( $case_id ) );
        $this->assertSame( TrialCasesRepository::STATUS_EXTENDED, (string) $this->caseColumn( $case_id, 'status' ) );
        $this->assertNotNull( $this->caseColumn( $case_id, 'continued_until' ), 'the 90-day extension is still stamped' );
        $this->assertSame( PlayerStatus::TRIAL, $this->statusOf( $player_id ) );
    }

    public function test_offering_a_place_writes_no_ending_and_leaves_the_case_open(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        ( new ReviewTrialGroupMembershipForm() )->serializeResponse(
            [ 'decision' => 'offer_team_position', 'rationale' => 'Offer a place in the U15.' ],
            $this->task( $case_id, $player_id )
        );

        $this->assertSame(
            0,
            $this->countEvents( $player_id, 'trial_ended' ),
            'an offer is mid-conversation - the family has not answered'
        );
        $this->assertSame( TrialCaseDecision::OFFERED_TEAM_POSITION, $this->decisionOf( $case_id ) );
        $this->assertSame(
            TrialCasesRepository::STATUS_OPEN,
            (string) $this->caseColumn( $case_id, 'status' ),
            'the final disposition lands in the next task, so the case stays open'
        );
        $this->assertSame( PlayerStatus::TRIAL, $this->statusOf( $player_id ) );
    }

    /* ---- the two the sweep found ---------------------------------------- */

    public function test_a_final_decline_from_the_group_review_reaches_the_timeline(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        ( new ReviewTrialGroupMembershipForm() )->serializeResponse(
            [ 'decision' => 'decline_final', 'rationale' => 'Not at the level for the age group.' ],
            $this->task( $case_id, $player_id )
        );

        $this->assertSame( 1, $this->countEvents( $player_id, 'trial_ended' ) );
        $this->assertSame( 1, $this->countEvents( $player_id, 'released' ) );
        $this->assertSame( PlayerStatus::RELEASED, $this->statusOf( $player_id ) );
        $this->assertNotNull( $this->caseColumn( $case_id, 'archived_at' ), 'the case is still archived' );
    }

    /* ---- the write path ------------------------------------------------- */

    public function test_the_repository_announces_the_decision_exactly_once(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        $seen = [];
        add_action( 'tt_trial_decision_recorded', static function ( $cid, $pid, $decision ) use ( &$seen ): void {
            $seen[] = [ (int) $cid, (int) $pid, (string) $decision ];
        }, 10, 4 );

        ( new TrialCasesRepository() )->recordDecision(
            $case_id, TrialCaseDecision::ADMIT, $this->user_id, 'Signed.'
        );

        $this->assertSame( [ [ $case_id, $player_id, TrialCaseDecision::ADMIT ] ], $seen );
    }

    /** The REST route no longer fires the hook itself on top of the repository. */
    public function test_the_rest_route_does_not_double_announce(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        $fired = 0;
        add_action( 'tt_trial_decision_recorded', static function () use ( &$fired ): void { $fired++; }, 10, 4 );

        $request = new \WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases/' . $case_id . '/decision' );
        $request->set_param( 'id', $case_id );
        $request->set_body( (string) wp_json_encode( [
            'decision' => TrialCaseDecision::ADMIT,
            'notes'    => 'Thirty characters of justification, at least.',
        ] ) );
        $request->set_header( 'content-type', 'application/json' );

        $response = TrialsRestController::record_decision( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $fired );
        $this->assertSame( 1, $this->countEvents( $player_id, 'trial_ended' ) );
    }

    /**
     * The endpoint keeps its own narrower surface. The rolling-membership
     * decisions belong to the workflow chain that spawns the next task;
     * recording one over HTTP would move the case without moving the chain.
     */
    public function test_the_rest_route_still_refuses_the_workflow_only_decisions(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        $request = new \WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases/' . $case_id . '/decision' );
        $request->set_param( 'id', $case_id );
        $request->set_body( (string) wp_json_encode( [
            'decision' => TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP,
            'notes'    => 'Thirty characters of justification, at least.',
        ] ) );
        $request->set_header( 'content-type', 'application/json' );

        $this->assertSame( 400, TrialsRestController::record_decision( $request )->get_status() );
        $this->assertSame( '', $this->decisionOf( $case_id ) );
    }

    /**
     * A caller with nothing to say about the summaries must not blank
     * them. Every workflow form is such a caller.
     */
    public function test_recording_a_decision_does_not_blank_the_summaries(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        ( new TrialCasesRepository() )->update( $case_id, [
            'strengths_summary' => 'Quick over five metres.',
            'growth_areas'      => 'Weak foot.',
        ] );

        ( new TrialCasesRepository() )->recordDecision(
            $case_id, TrialCaseDecision::ADMIT, $this->user_id, 'Signed.'
        );

        $this->assertSame( 'Quick over five metres.', (string) $this->caseColumn( $case_id, 'strengths_summary' ) );
        $this->assertSame( 'Weak foot.', (string) $this->caseColumn( $case_id, 'growth_areas' ) );
    }

    public function test_an_unknown_decision_is_refused_and_announces_nothing(): void {
        $player_id = $this->makeTrialPlayer();
        $case_id   = $this->makeCase( $player_id );

        $fired = 0;
        add_action( 'tt_trial_decision_recorded', static function () use ( &$fired ): void { $fired++; }, 10, 4 );

        $ok = ( new TrialCasesRepository() )->recordDecision(
            $case_id, 'maybe_next_year', $this->user_id, 'Notes.'
        );

        $this->assertFalse( $ok );
        $this->assertSame( 0, $fired );
        $this->assertSame( '', $this->decisionOf( $case_id ) );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return array<string,mixed> */
    private function task( int $case_id, int $player_id ): array {
        return [
            'trial_case_id'    => $case_id,
            'player_id'        => $player_id,
            'prospect_id'      => 0,
            'assignee_user_id' => $this->user_id,
        ];
    }

    private function makeCase( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_trial_cases', [
            'club_id'    => (int) CurrentClub::id(),
            'player_id'  => $player_id,
            'track_id'   => 0,
            'start_date' => '2026-01-05',
            'end_date'   => '2026-02-05',
            'status'     => TrialCasesRepository::STATUS_OPEN,
            'uuid'       => wp_generate_uuid4(),
            'created_by' => $this->user_id,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeTrialPlayer( string $status = PlayerStatus::TRIAL ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Trial',
            'last_name'     => 'Decider',
            'date_of_birth' => '2011-01-01',
            'status'        => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function statusOf( int $player_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ) );
    }

    private function decisionOf( int $case_id ): string {
        return (string) $this->caseColumn( $case_id, 'decision' );
    }

    /** @return mixed */
    private function caseColumn( int $case_id, string $column ) {
        global $wpdb;
        // Column names are literals from this test, never caller input.
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT `{$column}` FROM {$wpdb->prefix}tt_trial_cases WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $case_id
        ) );
    }

    private function countEvents( int $player_id, string $type ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $player_id,
            $type
        ) );
    }
}
