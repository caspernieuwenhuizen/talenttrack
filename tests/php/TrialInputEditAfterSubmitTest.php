<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\License\LicenseGate;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;
use TT\Modules\Trials\Rest\TrialsRestController;
use TT\Shared\Frontend\FrontendTrialCaseView;

/**
 * #3649 — the Staff inputs tab follows the policy the API already follows.
 *
 * `TrialCaseAccessPolicy` says the decision, not the submission, is the
 * line, and `TrialsRestController::upsert_input()` enforces exactly that:
 * an edit to a submitted input is accepted while the case is `open` or
 * `extended` and refused with 409 once it is decided. The screen did not.
 * `renderOwnInputForm()` returned early the moment `submitted_at` was set,
 * hiding the form and printing "To edit after submit, ask the head of
 * development" — to everybody, including the head of development, and
 * pointing at a reopen action the Trials module has never had.
 *
 * So the two halves below are asserted together on purpose. The view tests
 * are the fix; the REST tests pin the contract the view is now following,
 * so a later change to either surface cannot quietly reopen the gap.
 *
 * `TrialInputFreezeTest` covers the freeze itself — what the API refuses
 * after the decision, and that the stored text survives it. This file is
 * about what happens *before* the decision, and about what the screen
 * shows.
 */
final class TrialInputEditAfterSubmitTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $case_id;
    private int $coach;

    private const ORIGINAL = 'Strong first ten metres, reads the second ball well.';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'first_name' => 'Edit',
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
        unset( $_GET['tt_view'], $_GET['id'], $_GET['tab'] );
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
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

    private function storedInput(): ?object {
        return ( new TrialStaffInputsRepository() )->findForCaseUser( $this->case_id, $this->coach );
    }

    private function submittedAt(): string {
        $row = $this->storedInput();
        return (string) ( $row->submitted_at ?? '' );
    }

    private function storedNotes(): string {
        $row = $this->storedInput();
        return (string) ( $row->free_text_notes ?? '' );
    }

    /** Write a submitted input the way the API does, then verify it. */
    private function seedSubmittedInput(): void {
        $repo = new TrialStaffInputsRepository();
        $repo->upsertDraft( $this->case_id, $this->coach, [
            'overall_rating'  => 7.5,
            'free_text_notes' => self::ORIGINAL,
        ] );
        $repo->submit( $this->case_id, $this->coach );
        $this->assertNotSame( '', $this->submittedAt(), 'Precondition: the input is submitted.' );
    }

    /**
     * Render the Staff inputs tab, or skip when Trials is out of plan —
     * the upgrade panel is a different screen and asserting against it
     * would be testing the license.
     */
    private function renderInputsTab( int $user_id ): string {
        if ( class_exists( LicenseGate::class ) && ! LicenseGate::allows( 'trial_module' ) ) {
            $this->markTestSkipped( 'Trials is out of plan in this install; the view renders the upgrade panel.' );
        }

        wp_set_current_user( $user_id );
        $_GET['tt_view'] = 'trial-case';
        $_GET['id']      = (string) $this->case_id;
        $_GET['tab']     = 'inputs';

        ob_start();
        FrontendTrialCaseView::render( $user_id, false );
        return (string) ob_get_clean();
    }

    // ── the screen, which is where the bug lived ───────────────────────

    public function test_a_submitted_input_still_renders_its_form_before_the_decision(): void {
        $this->seedSubmittedInput();

        $html = $this->renderInputsTab( $this->coach );

        $this->assertStringContainsString(
            'tt-trial-input-form',
            $html,
            'The policy still allows a write, so the form has to be on the screen.'
        );
        $this->assertStringContainsString(
            esc_textarea( self::ORIGINAL ),
            $html,
            'And it is prefilled with what the coach already wrote.'
        );
        $this->assertStringContainsString(
            '7.5',
            $html,
            'The rating comes back too, not just the notes.'
        );
    }

    /**
     * The message the head of development was told to ask themselves
     * about. It named an action the Trials module does not have, so it is
     * gone from the tab entirely rather than narrowed to some other role.
     */
    public function test_nobody_is_told_to_ask_the_head_of_development(): void {
        $this->seedSubmittedInput();

        $this->assertStringNotContainsString(
            'ask the head of development',
            $this->renderInputsTab( $this->coach )
        );
    }

    /**
     * One button, and it saves a draft. Offering "Submit input" again
     * would re-stamp `submitted_at` and lose when the input was actually
     * handed in; offering "Save draft" would read as an un-submit.
     */
    public function test_a_submitted_input_offers_save_changes_and_not_submit(): void {
        $this->seedSubmittedInput();

        $html = $this->renderInputsTab( $this->coach );

        $this->assertStringContainsString( 'Save changes', $html );
        $this->assertStringNotContainsString( 'Save draft', $html );
        $this->assertStringNotContainsString( 'Submit input', $html );
        $this->assertStringContainsString(
            'name="submit_action" value="draft"',
            $html,
            'Saving changes must not run submit() a second time.'
        );
    }

    /** A draft is untouched by the fix: both buttons, as before. */
    public function test_a_draft_input_keeps_save_draft_and_submit(): void {
        ( new TrialStaffInputsRepository() )->upsertDraft( $this->case_id, $this->coach, [
            'free_text_notes' => 'Half written.',
        ] );

        $html = $this->renderInputsTab( $this->coach );

        $this->assertStringContainsString( 'Save draft', $html );
        $this->assertStringContainsString( 'Submit input', $html );
        $this->assertStringNotContainsString( 'Save changes', $html );
    }

    /** After the decision there is no form at all, as there never was. */
    public function test_a_decided_case_shows_no_own_input_form(): void {
        $this->seedSubmittedInput();
        $this->setStatus( 'decided' );

        $this->assertStringNotContainsString( 'tt-trial-input-form', $this->renderInputsTab( $this->coach ) );
    }

    /**
     * The screen's own save path. Posting the form leaves `submitted_at`
     * where it was, so the input still counts towards the head of
     * development's "N of M submitted" and the release button.
     */
    public function test_saving_changes_from_the_screen_keeps_the_input_submitted(): void {
        $this->seedSubmittedInput();
        $was = $this->submittedAt();

        wp_set_current_user( $this->coach );
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tt_trial_action'      => 'save_input',
            'tt_trial_input_nonce' => wp_create_nonce( 'tt_trial_input_' . $this->case_id ),
            'submit_action'        => 'draft',
            'overall_rating'       => '7.5',
            'free_text_notes'      => 'Strong first ten metres, reads the game well.',
        ];

        $this->renderInputsTab( $this->coach );

        $this->assertSame( 'Strong first ten metres, reads the game well.', $this->storedNotes() );
        $this->assertSame( $was, $this->submittedAt(), 'An edit is not an un-submit.' );
        $this->assertCount(
            1,
            ( new TrialStaffInputsRepository() )->listForCase( $this->case_id, true ),
            'And the case still counts one submitted input.'
        );
    }

    // ── the REST contract the screen now follows ──────────────────────

    /** Drive the REST route exactly as a client would. */
    private function postInput( string $notes ): \WP_REST_Response {
        wp_set_current_user( $this->coach );

        $r = new \WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases/' . $this->case_id . '/inputs' );
        $r->set_param( 'id', $this->case_id );
        $r->set_body( (string) wp_json_encode( [ 'free_text_notes' => $notes ] ) );
        $r->set_header( 'Content-Type', 'application/json' );

        return TrialsRestController::upsert_input( $r );
    }

    public function test_the_api_keeps_submitted_at_when_a_submitted_input_is_edited(): void {
        $this->seedSubmittedInput();
        $was = $this->submittedAt();

        $response = $this->postInput( 'Reads the game well; quicker over ten than over thirty.' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'Reads the game well; quicker over ten than over thirty.', $this->storedNotes() );
        $this->assertSame(
            $was,
            $this->submittedAt(),
            'The submission time is when the coach handed it in, not when they last touched it.'
        );
    }

    public function test_the_api_refuses_the_same_edit_after_the_decision(): void {
        $this->seedSubmittedInput();
        $this->setStatus( 'decided' );

        $this->assertSame( 409, $this->postInput( 'A different account of the same child.' )->get_status() );
        $this->assertSame( self::ORIGINAL, $this->storedNotes() );
    }
}
