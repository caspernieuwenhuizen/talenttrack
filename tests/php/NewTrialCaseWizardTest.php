<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Services\TrialCaseOpener;
use TT\Modules\Trials\Wizards\NewTrialCaseWizard;
use TT\Modules\Trials\Wizards\TrialDetailsStep;
use TT\Modules\Trials\Wizards\TrialPlayerStep;
use TT\Modules\Trials\Wizards\TrialStaffStep;

/**
 * #3221 — the trial-case wizard, and the write path it shares with the
 * flat form.
 *
 * The point of this issue was never the wizard on its own: it was that
 * adding one would have made a **third** way to open a trial case, in a
 * module that has already paid for a second twice. #3115 found the flat
 * form creating players with a raw insert that skipped
 * `tt_player_created`; #3130 found `tt_trial_started` fired by three of
 * its four callers. Neither errored — the data was just less complete
 * depending on which screen you used.
 *
 * So the assertions that matter most here are about `TrialCaseOpener`
 * being the single write path and announcing what it should.
 */
final class NewTrialCaseWizardTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $track;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_trial_tracks", [
            'club_id'               => $this->club,
            'name'                  => 'Standard',
            'default_duration_days' => 28,
        ] );
        $this->track = (int) $wpdb->insert_id;
    }

    private function insertPlayer( string $status = 'active' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'first_name' => 'Wizard',
            'last_name'  => 'Trialist',
            'status'     => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    // --- the wizard's shape ---------------------------------------------

    public function test_the_wizard_is_registered_with_three_steps(): void {
        $wizard = new NewTrialCaseWizard();

        $this->assertSame( 'trial-case', $wizard->slug() );
        $this->assertSame( 'tt_manage_trials', $wizard->requiredCap() );
        $this->assertSame( 'player', $wizard->firstStepSlug() );
        $this->assertCount( 3, $wizard->steps() );
    }

    /** The chain has to actually connect, or a step is unreachable. */
    public function test_the_steps_chain_to_a_final_step(): void {
        $this->assertSame( 'details', ( new TrialPlayerStep() )->nextStep( [] ) );
        $this->assertSame( 'staff', ( new TrialDetailsStep() )->nextStep( [] ) );
        $this->assertNull( ( new TrialStaffStep() )->nextStep( [] ), 'staff is the final step' );
    }

    // --- step 1 validation ----------------------------------------------

    public function test_picking_nobody_is_refused(): void {
        $out = ( new TrialPlayerStep() )->validate( [], [] );
        $this->assertInstanceOf( \WP_Error::class, $out );
    }

    /**
     * Picking somebody AND typing somebody else is ambiguous, and guessing
     * is how the wrong child ends up on a trial case.
     */
    public function test_picking_one_player_and_typing_another_is_refused(): void {
        $out = ( new TrialPlayerStep() )->validate( [
            'player_id' => $this->insertPlayer(),
            'new_first' => 'Someone',
            'new_last'  => 'Else',
            'new_dob'   => '2012-04-01',
        ], [] );

        $this->assertInstanceOf( \WP_Error::class, $out );
        $this->assertSame( 'ambiguous_player', $out->get_error_code() );
    }

    public function test_a_half_filled_new_player_is_refused(): void {
        $out = ( new TrialPlayerStep() )->validate( [ 'new_first' => 'Only' ], [] );

        $this->assertInstanceOf( \WP_Error::class, $out );
        $this->assertSame( 'incomplete_player', $out->get_error_code() );
    }

    public function test_a_complete_new_player_is_carried_forward_unwritten(): void {
        global $wpdb;
        $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_players" );

        $out = ( new TrialPlayerStep() )->validate( [
            'new_first' => 'Nieuwe',
            'new_last'  => 'Speler',
            'new_dob'   => '2012-04-01',
        ], [] );

        $this->assertIsArray( $out );
        $this->assertSame( 0, $out['player_id'] );
        $this->assertSame( 'Nieuwe', $out['new_first'] );

        // Nothing written yet — model C. A wizard abandoned here must not
        // leave a player behind.
        $this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_players" ) );
    }

    // --- step 2 validation ----------------------------------------------

    public function test_an_end_before_the_start_is_refused(): void {
        $out = ( new TrialDetailsStep() )->validate( [
            'track_id'   => $this->track,
            'start_date' => '2026-09-10',
            'end_date'   => '2026-09-01',
        ], [] );

        $this->assertInstanceOf( \WP_Error::class, $out );
        $this->assertSame( 'bad_range', $out->get_error_code() );
    }

    public function test_a_track_is_required(): void {
        $out = ( new TrialDetailsStep() )->validate( [
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
        ], [] );

        $this->assertInstanceOf( \WP_Error::class, $out );
        $this->assertSame( 'no_track', $out->get_error_code() );
    }

    // --- step 3 validation ----------------------------------------------

    /** Two slots naming the same person is a slip, not two assignments. */
    public function test_duplicate_staff_slots_collapse(): void {
        $uid = self::factory()->user->create();

        $out = ( new TrialStaffStep() )->validate( [
            'staff_user_id'    => [ $uid, $uid, 0 ],
            'staff_role_label' => [ 'Lead', 'Also lead', '' ],
        ], [] );

        $this->assertCount( 1, $out['staff'] );
        $this->assertSame( $uid, $out['staff'][0]['user_id'] );
    }

    public function test_no_staff_is_allowed(): void {
        $out = ( new TrialStaffStep() )->validate( [], [] );
        $this->assertSame( [], $out['staff'] );
    }

    // --- the shared write path ------------------------------------------

    /**
     * The assertion this whole issue is for: opening a case announces
     * `tt_trial_started`, because the repository does it for every caller
     * (#3130). If a future wizard step ever writes its own row instead,
     * this fails.
     */
    public function test_opening_a_case_announces_the_trial(): void {
        $player = $this->insertPlayer();

        $fired = [];
        $spy   = static function ( int $case_id, int $player_id ) use ( &$fired ): void {
            $fired[] = $player_id;
        };
        add_action( 'tt_trial_started', $spy, 10, 2 );

        $case = ( new TrialCaseOpener() )->open( [
            'player_id'  => $player,
            'track_id'   => $this->track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
        ] );

        remove_action( 'tt_trial_started', $spy, 10 );

        $this->assertIsInt( $case );
        $this->assertGreaterThan( 0, $case );
        $this->assertSame( [ $player ], $fired );
    }

    public function test_opening_a_case_flips_the_player_to_trial(): void {
        global $wpdb;
        $player = $this->insertPlayer( 'active' );

        ( new TrialCaseOpener() )->open( [
            'player_id'  => $player,
            'track_id'   => $this->track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
        ] );

        $this->assertSame(
            PlayerStatus::TRIAL,
            (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT status FROM {$this->p}tt_players WHERE id = %d",
                $player
            ) )
        );
    }

    public function test_staff_assignments_are_written(): void {
        global $wpdb;
        $player = $this->insertPlayer();
        $uid    = self::factory()->user->create();

        $case = ( new TrialCaseOpener() )->open( [
            'player_id'  => $player,
            'track_id'   => $this->track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'staff'      => [ [ 'user_id' => $uid, 'role_label' => 'Lead assessor' ] ],
        ] );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id, role_label FROM {$this->p}tt_trial_case_staff WHERE case_id = %d",
            (int) $case
        ) );

        $this->assertNotNull( $row );
        $this->assertSame( $uid, (int) $row->user_id );
        $this->assertSame( 'Lead assessor', (string) $row->role_label );
    }

    /** #1201's cross-club pointing class, kept closed. */
    public function test_a_player_from_another_club_is_refused(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club + 1,
            'first_name' => 'Foreign',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $foreign = (int) $wpdb->insert_id;

        $out = ( new TrialCaseOpener() )->open( [
            'player_id'  => $foreign,
            'track_id'   => $this->track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
        ] );

        $this->assertInstanceOf( \WP_Error::class, $out );
        $this->assertSame( 'foreign_player', $out->get_error_code() );
    }

    public function test_incomplete_data_is_refused(): void {
        $out = ( new TrialCaseOpener() )->open( [ 'player_id' => $this->insertPlayer() ] );

        $this->assertInstanceOf( \WP_Error::class, $out );
        $this->assertSame( 'incomplete', $out->get_error_code() );
    }
}
