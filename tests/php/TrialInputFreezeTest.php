<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;
use TT\Modules\Trials\Rest\TrialsRestController;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;

/**
 * #3238 — a submitted trial input cannot be rewritten after the decision.
 *
 * A staff input is the evidence behind a decision about a minor: whether
 * the academy wanted them, and why. `upsertDraft()` updated the existing
 * row whenever it found one, looking at neither `submitted_at` nor the
 * case status, so an assigned coach could revise through the API what they
 * had said about a child **after** the academy decided on the strength of
 * it. `updated_at` moved and the previous wording was gone.
 *
 * The screen hid this — `renderInputsTab()` renders the own-input form only
 * while the case is `open` or `extended` — which is exactly why the tests
 * below drive the **REST route** rather than the view. A view-level test
 * passes against the broken code and proves nothing.
 *
 * Two things are asserted in opposite directions, because a fix that only
 * closes the hole is not enough:
 *
 *   - the write is refused once the case leaves `open` / `extended`, and
 *     the stored text is byte-identical afterwards; and
 *   - the coach can still **open** the decided case. Being unable to
 *     rewrite your input is not the same as being locked out of the case
 *     you wrote it on, and routing entry through the frozen predicate
 *     would reintroduce #3222 from the other side.
 */
final class TrialInputFreezeTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $case_id;
    private int $coach;

    private const ORIGINAL = 'Sharp over the first ten metres, reads the second ball well.';
    private const REWRITE  = 'Actually he was poor throughout and I never rated him.';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'first_name' => 'Freeze',
            'last_name'  => 'Subject',
            'status'     => 'trial',
        ] );
        $player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_tracks", [
            'club_id' => $this->club,
            'name'    => 'Standard',
        ] );
        $track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'track_id'   => $track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'status'     => 'open',
            'uuid'       => wp_generate_uuid4(),
        ] );
        $this->case_id = (int) $wpdb->insert_id;

        $this->coach = $this->assignedCoach();
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @var callable|null */
    private $cap_filter = null;

    /** @var array<int, bool> */
    private array $granted = [];

    /**
     * An assigned coach holding `tt_submit_trial_input` and nothing else.
     *
     * `add_cap()` is not enough: `AuthorizationModule::filterUserHasCap`
     * makes `LegacyCapMapper` authoritative for every `tt_*` cap, so a raw
     * grant on a persona-less user is recomputed against the matrix and
     * overridden. The filter below runs at 999, after the bridge.
     */
    private function assignedCoach(): int {
        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->granted[ $uid ] = true;

        if ( $this->cap_filter === null ) {
            $granted          = &$this->granted;
            $this->cap_filter = static function ( $allcaps, $caps_needed, $args, $user ) use ( &$granted ) {
                $uid = is_object( $user ) ? (int) $user->ID : 0;
                if ( ! isset( $granted[ $uid ] ) ) return $allcaps;

                // Not a manager: a manager passes assignment checks
                // unconditionally and would not exercise the coach path.
                unset( $allcaps['tt_manage_trials'], $allcaps['tt_view_trial_synthesis'] );
                $allcaps['tt_submit_trial_input'] = true;
                return $allcaps;
            };
            add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );
        }

        ( new TrialCaseStaffRepository() )->assign( $this->case_id, $uid, 'Assistant coach' );
        return $uid;
    }

    private function setStatus( string $status ): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_trial_cases", [ 'status' => $status ], [ 'id' => $this->case_id ] );
    }

    private function storedNotes(): string {
        $row = ( new TrialStaffInputsRepository() )->findForCaseUser( $this->case_id, $this->coach );
        return (string) ( $row->free_text_notes ?? '' );
    }

    /** Drive the REST route exactly as a client would. */
    private function postInput( string $notes, bool $submit = false ): \WP_REST_Response {
        wp_set_current_user( $this->coach );

        $r = new \WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases/' . $this->case_id . '/inputs' );
        $r->set_param( 'id', $this->case_id );
        $r->set_body( (string) wp_json_encode( [
            'free_text_notes' => $notes,
            'overall_rating'  => 7.0,
            'submit'          => $submit,
        ] ) );
        $r->set_header( 'Content-Type', 'application/json' );

        return TrialsRestController::upsert_input( $r );
    }

    // ── the predicate ──────────────────────────────────────────────────

    public function test_only_open_and_extended_accept_input(): void {
        foreach ( [ 'open', 'extended' ] as $status ) {
            $this->setStatus( $status );
            $this->assertTrue(
                TrialCaseAccessPolicy::caseAcceptsInput( $this->case_id ),
                "A case that is '{$status}' is still gathering input."
            );
        }
        foreach ( [ 'decided', 'archived' ] as $status ) {
            $this->setStatus( $status );
            $this->assertFalse(
                TrialCaseAccessPolicy::caseAcceptsInput( $this->case_id ),
                "A case that is '{$status}' has had its inputs acted on."
            );
        }
    }

    /** Fail closed: a write to a case that does not exist has nothing to do. */
    public function test_an_unknown_case_accepts_nothing(): void {
        $this->assertFalse( TrialCaseAccessPolicy::caseAcceptsInput( 0 ) );
        $this->assertFalse( TrialCaseAccessPolicy::caseAcceptsInput( 999999 ) );
    }

    // ── the API, which is where the bug lived ──────────────────────────

    public function test_a_submitted_input_can_still_be_corrected_before_the_decision(): void {
        $this->postInput( self::ORIGINAL, true );

        $response = $this->postInput( 'Sharp over the first ten metres, reads the game well.' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame(
            'Sharp over the first ten metres, reads the game well.',
            $this->storedNotes(),
            'A coach fixing their own wording before anybody has acted on it is normal practice.'
        );
    }

    public function test_the_api_refuses_to_rewrite_an_input_after_the_decision(): void {
        $this->postInput( self::ORIGINAL, true );
        $this->assertSame( self::ORIGINAL, $this->storedNotes(), 'Precondition.' );

        $this->setStatus( 'decided' );
        $response = $this->postInput( self::REWRITE );

        $this->assertSame(
            409,
            $response->get_status(),
            'The caller is entitled to write on this case; the case state is what rejects it.'
        );
        $this->assertSame(
            self::ORIGINAL,
            $this->storedNotes(),
            'The evidence behind a decision about a child must survive the decision.'
        );
    }

    /** An archived case is past it too, and by the same reasoning. */
    public function test_the_api_refuses_on_an_archived_case(): void {
        $this->postInput( self::ORIGINAL, true );
        $this->setStatus( 'archived' );

        $this->assertSame( 409, $this->postInput( self::REWRITE )->get_status() );
        $this->assertSame( self::ORIGINAL, $this->storedNotes() );
    }

    /**
     * The refusal is not a silent no-op. An endpoint that returns success
     * and writes nothing is how a coach believes they corrected something
     * they did not.
     */
    public function test_the_refusal_names_its_reason(): void {
        $this->postInput( self::ORIGINAL, true );
        $this->setStatus( 'decided' );

        $data   = $this->postInput( self::REWRITE )->get_data();
        $errors = is_array( $data ) ? (array) ( $data['errors'] ?? [] ) : [];

        $this->assertSame(
            'case_closed_to_input',
            (string) ( $errors[0]['code'] ?? '' ),
            'The response has to say why, distinctly from "not assigned".'
        );
        $this->assertNotSame(
            '',
            (string) ( $errors[0]['message'] ?? '' ),
            'And say it in words a coach reads, not just a code.'
        );
    }

    // ── and the thing the fix must not break ───────────────────────────

    /**
     * #3222 from the other direction. An assigned assistant coach holds
     * `trial_inputs: change` and no `trial_synthesis`, so `canOpenCase()`
     * reaches the case through the input branch. If that branch consulted
     * the frozen predicate, recording a decision would lock them out of the
     * case entirely — including the input they wrote on it.
     */
    public function test_an_assigned_coach_can_still_open_a_decided_case(): void {
        $this->setStatus( 'decided' );

        $this->assertFalse(
            TrialCaseAccessPolicy::canSubmitInput( $this->coach, $this->case_id ),
            'They may no longer write.'
        );
        $this->assertTrue(
            TrialCaseAccessPolicy::isInputAuthor( $this->coach, $this->case_id ),
            'It is still their case to have written on.'
        );
        $this->assertTrue(
            TrialCaseAccessPolicy::canOpenCase( $this->coach, $this->case_id ),
            'Being unable to rewrite an input is not being locked out of the case.'
        );
    }

    /** An unassigned user is refused for the original reason, not the new one. */
    public function test_an_unassigned_user_is_still_refused_as_unassigned(): void {
        $outsider = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->granted[ $outsider ] = true;

        wp_set_current_user( $outsider );
        $r = new \WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases/' . $this->case_id . '/inputs' );
        $r->set_param( 'id', $this->case_id );
        $r->set_body( (string) wp_json_encode( [ 'free_text_notes' => 'x' ] ) );
        $r->set_header( 'Content-Type', 'application/json' );

        $this->assertSame( 403, TrialsRestController::upsert_input( $r )->get_status() );
    }
}
