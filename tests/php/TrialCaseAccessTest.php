<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\License\LicenseGate;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;
use TT\Shared\Frontend\FrontendTrialCaseView;

/**
 * #3222 — the access claims `docs/trials.md` makes about a trial case.
 *
 * These are claims about who can read a judgement the academy is forming
 * about a child, so they are pinned rather than assumed. The doc said
 * "other coaches do not see the case at all unless they are assigned to
 * it", which reads as though assignment is what grants access. It is not:
 * whether a persona reaches trial cases is a matrix permission, and
 * assignment narrows it further. Both halves are asserted below.
 *
 * The fix under test is `canOpenCase()`. Entry used to be gated on
 * `canViewSynthesis()` alone, which excluded the one persona whose only
 * job on a case is to write an input — the seed grants `assistant_coach`
 * `trial_inputs: change` and no `trial_synthesis`, so an assigned
 * assistant coach was entitled to submit an input and could not open the
 * screen holding the field.
 *
 * The policy cases below are deterministic. The three that render the
 * view depend on Trials being in plan, which these suites deliberately do
 * not arrange (see `MatchDayPlanGateTest`), so they check the gate first
 * and skip rather than assert something the license decided.
 */
final class TrialCaseAccessTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $case_id;
    private int $player_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'first_name' => 'Trial',
            'last_name'  => 'Player',
            'status'     => 'trial',
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_tracks", [
            'club_id' => $this->club,
            'name'    => 'Standard',
        ] );
        $track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id'    => $this->club,
            'player_id'  => $this->player_id,
            'track_id'   => $track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'status'     => 'open',
            'uuid'       => wp_generate_uuid4(),
        ] );
        $this->case_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        unset( $_GET['tt_view'], $_GET['id'], $_GET['tab'] );
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        parent::tear_down();
    }

    /** @var callable|null */
    private $cap_filter = null;

    /** @var array<int, array<string, bool>> user id => caps granted */
    private array $granted = [];

    /**
     * A user holding exactly the named trial capabilities.
     *
     * `add_cap()` is not enough here. `AuthorizationModule::filterUserHasCap`
     * makes `LegacyCapMapper` authoritative for every `tt_*` cap, so a raw
     * grant on a persona-less user is recomputed against the matrix and
     * overridden — the same trap `ExerciseLibraryRestTest` documents. The
     * filter below runs at priority 999, after the bridge, so it decides.
     *
     * That is the right shape for this test: the subject is
     * `TrialCaseAccessPolicy`'s composition of the three capabilities, not
     * which persona the seed happens to grant them to. The seed itself is
     * asserted separately, against `config/authorization_seed.php`.
     */
    private function userWith( string ...$caps ): int {
        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->granted[ $uid ] = [];
        foreach ( $caps as $cap ) {
            $this->granted[ $uid ][ $cap ] = true;
        }

        if ( $this->cap_filter === null ) {
            $granted          = &$this->granted;
            $this->cap_filter = static function ( $allcaps, $caps_needed, $args, $user ) use ( &$granted ) {
                $uid = is_object( $user ) ? (int) $user->ID : 0;
                if ( ! isset( $granted[ $uid ] ) ) return $allcaps;

                // Withhold every trial capability, then grant back exactly
                // the ones this user is supposed to hold — so "has input,
                // not synthesis" is expressible, which is the whole case.
                foreach ( [ 'tt_manage_trials', 'tt_view_trial_synthesis', 'tt_submit_trial_input' ] as $cap ) {
                    unset( $allcaps[ $cap ] );
                }
                foreach ( $granted[ $uid ] as $cap => $_ ) {
                    $allcaps[ $cap ] = true;
                }
                return $allcaps;
            };
            add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );
        }

        return $uid;
    }

    private function assign( int $user_id ): void {
        ( new TrialCaseStaffRepository() )->assign( $this->case_id, $user_id, 'Assistant coach' );
    }

    /**
     * Render the case view, or skip when Trials is out of plan — the
     * upgrade panel is a different screen and asserting against it would
     * be testing the license, not the access policy.
     */
    private function renderFor( int $user_id, ?string $tab = null ): string {
        if ( class_exists( LicenseGate::class ) && ! LicenseGate::allows( 'trial_module' ) ) {
            $this->markTestSkipped( 'Trials is out of plan in this install; the view renders the upgrade panel.' );
        }

        wp_set_current_user( $user_id );
        $_GET['tt_view'] = 'trial-case';
        $_GET['id']      = (string) $this->case_id;
        if ( $tab !== null ) $_GET['tab'] = $tab;

        ob_start();
        FrontendTrialCaseView::render( $user_id, false );
        return (string) ob_get_clean();
    }

    // --- the policy: deterministic --------------------------------------

    /**
     * The dead grant, at the layer that decides it. An assistant coach
     * holds `tt_submit_trial_input` and not `tt_view_trial_synthesis`;
     * assigned to a case they must be able to open it, or the capability
     * is reachable over REST and by no screen at all.
     */
    public function test_an_assigned_input_only_coach_may_open_the_case(): void {
        $uid = $this->userWith( 'tt_submit_trial_input' );
        $this->assign( $uid );

        $this->assertTrue( TrialCaseAccessPolicy::canSubmitInput( $uid, $this->case_id ) );
        $this->assertTrue( TrialCaseAccessPolicy::canOpenCase( $uid, $this->case_id ) );
        $this->assertFalse(
            TrialCaseAccessPolicy::canViewSynthesis( $uid, $this->case_id ),
            'Opening a case must not carry the right to read other coaches\' input.'
        );
    }

    /**
     * Claim: other coaches do not see the case. The mechanism is the
     * matrix, not assignment — so a user with no trial capability at all
     * is refused even when they ARE on the case's staff list.
     */
    public function test_assignment_alone_does_not_open_a_case(): void {
        $uid = $this->userWith();
        $this->assign( $uid );

        $this->assertFalse( TrialCaseAccessPolicy::canOpenCase( $uid, $this->case_id ) );
    }

    /**
     * Claim: staff inputs stay private until released. Both per-case gates
     * require assignment for a non-manager, so holding the capability
     * without being on the case reaches nothing.
     */
    public function test_the_capability_without_the_assignment_reaches_nothing(): void {
        $viewer = $this->userWith( 'tt_view_trial_synthesis' );
        $writer = $this->userWith( 'tt_submit_trial_input' );

        $this->assertFalse( TrialCaseAccessPolicy::canViewSynthesis( $viewer, $this->case_id ) );
        $this->assertFalse( TrialCaseAccessPolicy::canSubmitInput( $writer, $this->case_id ) );
        $this->assertFalse( TrialCaseAccessPolicy::canOpenCase( $viewer, $this->case_id ) );
        $this->assertFalse( TrialCaseAccessPolicy::canOpenCase( $writer, $this->case_id ) );
    }

    /**
     * Claim: creating, deciding and archiving stay with the head of
     * development. Neither per-case capability promotes anyone to manager.
     */
    public function test_no_per_case_capability_makes_someone_a_manager(): void {
        $uid = $this->userWith( 'tt_view_trial_synthesis', 'tt_submit_trial_input' );
        $this->assign( $uid );

        $this->assertTrue( TrialCaseAccessPolicy::canOpenCase( $uid, $this->case_id ) );
        $this->assertFalse( TrialCaseAccessPolicy::isManager( $uid ) );
        $this->assertFalse( TrialCaseAccessPolicy::canManageCase( $uid, $this->case_id ) );
    }

    /** A manager reaches everything without being assigned. */
    public function test_a_manager_opens_any_case_unassigned(): void {
        $uid = $this->userWith( 'tt_manage_trials' );

        $this->assertTrue( TrialCaseAccessPolicy::canOpenCase( $uid, $this->case_id ) );
        $this->assertTrue( TrialCaseAccessPolicy::canViewSynthesis( $uid, $this->case_id ) );
        $this->assertTrue( TrialCaseAccessPolicy::canManageCase( $uid, $this->case_id ) );
    }

    /**
     * The seed is the reason this issue exists: `assistant_coach` gets
     * change on `trial_inputs` and nothing on `trial_synthesis`. If that
     * ever changes, the persona no longer falls between the two gates and
     * `canOpenCase()` should be re-read rather than silently kept.
     */
    public function test_the_seed_still_gives_assistant_coach_input_without_synthesis(): void {
        $seed = require TT_PLUGIN_DIR . 'config/authorization_seed.php';

        $entities = [];
        foreach ( $seed as $row ) {
            if ( ( $row['persona'] ?? '' ) !== 'assistant_coach' ) continue;
            $entities[ (string) $row['entity'] ][] = (string) $row['activity'];
        }

        $this->assertContains( 'change', $entities['trial_inputs'] ?? [] );
        $this->assertArrayNotHasKey( 'trial_synthesis', $entities );
        $this->assertArrayNotHasKey( 'trial_cases', $entities );
    }

    // --- the view: skipped when Trials is out of plan --------------------

    public function test_the_view_lets_an_assigned_input_only_coach_in(): void {
        $uid = $this->userWith( 'tt_submit_trial_input' );
        $this->assign( $uid );

        $html = $this->renderFor( $uid );

        $this->assertStringNotContainsString( 'You are not assigned to this case.', $html );
        $this->assertStringContainsString( 'Staff inputs', $html );
    }

    /**
     * Widening entry must not widen reading: Execution aggregates other
     * coaches' input, so an input-only coach does not get the tab.
     */
    public function test_the_view_withholds_execution_from_an_input_only_coach(): void {
        $uid = $this->userWith( 'tt_submit_trial_input' );
        $this->assign( $uid );

        $this->assertStringNotContainsString( 'Execution', $this->renderFor( $uid ) );
    }

    /**
     * And cannot be reached by typing the URL — the tab is re-checked when
     * the body renders, not only when the strip is built.
     */
    public function test_the_view_refuses_a_forced_execution_tab(): void {
        $uid = $this->userWith( 'tt_submit_trial_input' );
        $this->assign( $uid );

        $html = $this->renderFor( $uid, 'execution' );

        // Falls back to Overview rather than rendering the synthesis.
        $this->assertStringContainsString( 'Assigned staff', $html );
    }

    public function test_the_view_refuses_someone_with_no_trial_capability(): void {
        $uid = $this->userWith();
        $this->assign( $uid );

        $this->assertStringContainsString(
            'You are not assigned to this case.',
            $this->renderFor( $uid )
        );
    }
}
