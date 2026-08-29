<?php
namespace TT\Tests\Php;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\TrialDecisionPlayerStatusSubscriber;
use WP_UnitTestCase;

/**
 * #3116 — recording a trial decision has to move the player.
 *
 * It did not. `TrialCasesRepository::recordDecision()` updated the
 * trial-case row and nothing else, and the hook's only listener wrote the
 * timeline. So an admitted player stayed on `trial` indefinitely: the
 * academy said yes to a child and the record said otherwise.
 *
 * The assertions that matter most are the two that are easy to get wrong:
 * *decline with encouragement* must NOT archive the player, and `admit` /
 * `deny_final` must each still produce exactly one `SIGNED` / `RELEASED`
 * journey event — the double-emit is the likely regression, since
 * `JourneyEventSubscriber` emits those both from the decision hook and
 * from a player status diff.
 */
final class TrialDecisionPlayerStatusTest extends WP_UnitTestCase {

    private int $user_id = 0;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );
    }

    public function test_admit_makes_the_player_active(): void {
        $id = $this->makeTrialPlayer();

        $this->decide( $id, TrialCaseDecision::ADMIT );

        $this->assertSame( PlayerStatus::ACTIVE, $this->statusOf( $id ) );
        $this->assertNull( $this->archivedAtOf( $id ) );
    }

    public function test_a_final_decline_releases_and_archives(): void {
        $id = $this->makeTrialPlayer();

        $this->decide( $id, TrialCaseDecision::DENY_FINAL );

        $this->assertSame( PlayerStatus::RELEASED, $this->statusOf( $id ) );
        $this->assertNotNull( $this->archivedAtOf( $id ), 'a final decline ends the relationship' );
    }

    /**
     * The point of the whole issue: "not now, come back" must not archive
     * the family's record.
     */
    public function test_a_decline_with_encouragement_leaves_the_player_on_the_books(): void {
        $id = $this->makeTrialPlayer();

        $this->decide( $id, TrialCaseDecision::DENY_ENCOURAGEMENT );

        $this->assertSame( PlayerStatus::INACTIVE, $this->statusOf( $id ) );
        $this->assertNull(
            $this->archivedAtOf( $id ),
            'archiving a player the club encouraged to come back says the opposite of what the club told them'
        );
    }

    /** A decision that means "still running" leaves the player on trial. */
    public function test_an_open_ended_decision_leaves_the_status_alone(): void {
        $id = $this->makeTrialPlayer();

        $this->decide( $id, TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP );

        $this->assertSame( PlayerStatus::TRIAL, $this->statusOf( $id ) );
    }

    public function test_a_player_who_is_no_longer_on_trial_is_left_alone(): void {
        $id = $this->makeTrialPlayer( PlayerStatus::ACTIVE );

        $this->decide( $id, TrialCaseDecision::DENY_ENCOURAGEMENT );

        $this->assertSame(
            PlayerStatus::ACTIVE,
            $this->statusOf( $id ),
            'a decision must not walk an already-promoted player backwards'
        );
    }

    public function test_recording_the_same_decision_twice_is_a_no_op(): void {
        $id = $this->makeTrialPlayer();

        $this->decide( $id, TrialCaseDecision::ADMIT );
        $this->decide( $id, TrialCaseDecision::ADMIT );

        $this->assertSame( PlayerStatus::ACTIVE, $this->statusOf( $id ) );
    }

    /**
     * The regression this fix could plausibly introduce.
     * `JourneyEventSubscriber` emits SIGNED / RELEASED from the decision
     * hook AND from a player status diff, so a status write that fired
     * `tt_player_save_diff` would double them.
     */
    public function test_admit_produces_exactly_one_signed_event(): void {
        $id = $this->makeTrialPlayer();

        $this->decideThroughTheHook( $id, TrialCaseDecision::ADMIT );

        // Also proves the subscriber is actually wired to the hook — the
        // event count alone would pass with it unregistered.
        $this->assertSame( PlayerStatus::ACTIVE, $this->statusOf( $id ) );
        $this->assertSame( 1, $this->countEvents( $id, 'signed' ) );
    }

    public function test_a_final_decline_produces_exactly_one_released_event(): void {
        $id = $this->makeTrialPlayer();

        $this->decideThroughTheHook( $id, TrialCaseDecision::DENY_FINAL );

        $this->assertSame( PlayerStatus::RELEASED, $this->statusOf( $id ) );
        $this->assertSame( 1, $this->countEvents( $id, 'released' ) );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** Call the subscriber directly — the unit under test. */
    private function decide( int $player_id, string $decision ): void {
        TrialDecisionPlayerStatusSubscriber::onDecisionRecorded(
            1,
            $player_id,
            $decision,
            current_time( 'mysql', true )
        );
    }

    /**
     * Fire the hook itself, so every listener runs — the journey
     * subscriber included. That is what the event-count assertions need;
     * the status assertions do not care which way it is invoked.
     */
    private function decideThroughTheHook( int $player_id, string $decision ): void {
        do_action(
            'tt_trial_decision_recorded',
            $this->makeCase( $player_id ),
            $player_id,
            $decision,
            current_time( 'mysql', true )
        );
    }

    private function makeCase( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_trial_cases', [
            'club_id'    => (int) CurrentClub::id(),
            'player_id'  => $player_id,
            'track_id'   => 0,
            'start_date' => '2026-01-05',
            'end_date'   => '2026-02-05',
            'status'     => 'decided',
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
            'last_name'     => 'Player',
            'date_of_birth' => '2012-03-03',
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

    private function archivedAtOf( int $player_id ): ?string {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT archived_at FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ) );
        return $value === null ? null : (string) $value;
    }

    private function countEvents( int $player_id, string $event_type ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $player_id,
            $event_type
        ) );
    }
}
