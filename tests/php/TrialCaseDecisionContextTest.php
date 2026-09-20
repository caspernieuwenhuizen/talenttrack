<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialExtensionsRepository;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;

/**
 * #3801 — what `GET trial-cases/{id}` now carries.
 *
 * The case record used to be a header. Everything a decision needs existed
 * and was simply never joined onto it: the panel's submissions, who had
 * submitted nothing, and why the case had been extended. This suite drives
 * the route, because the rule that matters is a visibility rule and the
 * only place to assert it is where a front end meets it.
 *
 * Two halves. That the blocks are there and correct, and — the half worth
 * the file — that composing them onto the case did not become a way round
 * the release rules the inputs route enforces.
 */
final class TrialCaseDecisionContextTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $case_id;
    private int $player_id;

    private int $manager   = 0;
    private int $panellist = 0;
    private int $reader    = 0;
    private int $outsider  = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();

        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'first_name' => 'Maxim', 'last_name' => 'Terpstra', 'status' => 'trial',
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_tracks", [ 'club_id' => $this->club, 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id'    => $this->club,
            'player_id'  => $this->player_id,
            'track_id'   => $track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-11-02',
            'status'     => 'extended',
            'uuid'       => wp_generate_uuid4(),
        ] );
        $this->case_id = (int) $wpdb->insert_id;

        $this->manager   = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Marco de Wit' ] );
        $this->panellist = $this->capUser( 'Lex Vermeulen', [ 'tt_submit_trial_input' ] );
        $this->reader    = $this->capUser( 'Sanne Visser', [ 'tt_submit_trial_input', 'tt_view_trial_synthesis' ] );
        $this->outsider  = $this->capUser( 'Buiten Staander', [ 'tt_submit_trial_input' ] );

        $staff = new TrialCaseStaffRepository();
        $staff->assign( $this->case_id, $this->panellist, 'Scout' );
        $staff->assign( $this->case_id, $this->reader, 'Assistant coach' );
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @var callable|null */
    private $cap_filter = null;

    /** @var array<int,list<string>> */
    private array $granted = [];

    /**
     * A user holding exactly the named trial caps.
     *
     * `add_cap()` is not enough: `AuthorizationModule::filterUserHasCap`
     * makes `LegacyCapMapper` authoritative for every `tt_*` cap, so a raw
     * grant on a persona-less user is recomputed against the matrix and
     * overridden. The filter below runs at 999, after the bridge.
     *
     * @param list<string> $caps
     */
    private function capUser( string $display_name, array $caps ): int {
        $uid = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => $display_name ] );
        $this->granted[ $uid ] = $caps;

        if ( $this->cap_filter === null ) {
            $granted          = &$this->granted;
            $this->cap_filter = static function ( $allcaps, $caps_needed, $args, $user ) use ( &$granted ) {
                $uid = is_object( $user ) ? (int) $user->ID : 0;
                if ( ! isset( $granted[ $uid ] ) ) return $allcaps;
                unset( $allcaps['tt_manage_trials'], $allcaps['tt_view_trial_synthesis'], $allcaps['tt_submit_trial_input'] );
                foreach ( $granted[ $uid ] as $cap ) {
                    $allcaps[ $cap ] = true;
                }
                return $allcaps;
            };
            add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );
        }

        return $uid;
    }

    // ── the three blocks ───────────────────────────────────────────────

    public function test_the_manager_reads_the_panel_the_gap_and_the_extension(): void {
        $this->writeInput( $this->panellist, 6.0, 'Leest het spel, komt kracht tekort.', true );
        ( new TrialExtensionsRepository() )->record(
            $this->case_id, '2026-10-19', '2026-11-02', 'Twee weken extra om hem in de spits te zien.', $this->manager
        );

        [ $data, $status ] = $this->get( $this->case_id, $this->manager );

        $this->assertSame( 200, $status );

        $inputs = $data['data']['panel_inputs'];
        $this->assertCount( 1, $inputs );
        $this->assertSame( $this->panellist, (int) $inputs[0]['user_id'] );
        $this->assertSame( 'Lex Vermeulen', $inputs[0]['author_name'] );
        $this->assertEquals( 6.0, $inputs[0]['overall_rating'] );
        $this->assertNotNull( $inputs[0]['submitted_at'] );

        $awaiting = $data['data']['panel_awaiting'];
        $this->assertCount( 1, $awaiting, 'The panellist who has submitted nothing is named.' );
        $this->assertSame( $this->reader, (int) $awaiting[0]['user_id'] );
        $this->assertSame( 'Sanne Visser', $awaiting[0]['author_name'] );

        $extensions = $data['data']['extensions'];
        $this->assertCount( 1, $extensions );
        $this->assertSame( 'Twee weken extra om hem in de spits te zien.', $extensions[0]['justification'] );
        $this->assertSame( '2026-11-02', $extensions[0]['new_end_date'] );
        $this->assertSame( 'Marco de Wit', $extensions[0]['extended_by_name'] );
    }

    public function test_the_full_input_body_stays_on_the_inputs_route(): void {
        $this->writeInput( $this->panellist, 6.0, 'Leest het spel, komt kracht tekort.', true );

        [ $data ] = $this->get( $this->case_id, $this->manager );

        $this->assertArrayNotHasKey( 'free_text_notes', $data['data']['panel_inputs'][0] );
    }

    public function test_a_draft_is_not_on_the_case_at_all(): void {
        $this->writeInput( $this->panellist, 8.0, 'Nog niet ingeleverd.', false );

        [ $data ] = $this->get( $this->case_id, $this->manager );

        $this->assertSame( [], $data['data']['panel_inputs'], 'A draft is its author\'s until they hand it in.' );
        $awaiting = array_map( 'intval', array_column( $data['data']['panel_awaiting'], 'user_id' ) );
        $this->assertContains( $this->panellist, $awaiting, 'Starting is not submitting.' );
    }

    // ── the release rules are not routed around ────────────────────────

    public function test_before_release_a_panellist_sees_only_their_own_input(): void {
        $this->writeInput( $this->panellist, 6.0, 'Leest het spel.', true );
        $this->writeInput( $this->reader, 7.0, 'Snel, maar slordig.', true );

        [ $data, $status ] = $this->get( $this->case_id, $this->reader );

        $this->assertSame( 200, $status );
        $authors = array_map( 'intval', array_column( $data['data']['panel_inputs'], 'user_id' ) );
        $this->assertSame( [ $this->reader ], $authors, 'The case record is not a way round the release rules.' );
    }

    public function test_after_release_a_panellist_sees_the_others_too(): void {
        $this->writeInput( $this->panellist, 6.0, 'Leest het spel.', true );
        $this->writeInput( $this->reader, 7.0, 'Snel, maar slordig.', true );
        ( new TrialStaffInputsRepository() )->release( $this->case_id, $this->manager );
        ( new TrialCasesRepository() )->releaseInputs( $this->case_id, $this->manager );

        [ $data ] = $this->get( $this->case_id, $this->reader );

        $authors = array_map( 'intval', array_column( $data['data']['panel_inputs'], 'user_id' ) );
        $this->assertContains( $this->panellist, $authors );
    }

    public function test_who_is_still_missing_is_counted_across_the_whole_panel(): void {
        // The reader may not read Lex's input yet, but must still be told
        // the panel is not waiting on him — otherwise the block reports
        // everyone but the reader as missing.
        $this->writeInput( $this->panellist, 6.0, 'Leest het spel.', true );

        [ $data ] = $this->get( $this->case_id, $this->reader );

        $awaiting = array_map( 'intval', array_column( $data['data']['panel_awaiting'], 'user_id' ) );
        $this->assertSame( [ $this->reader ], $awaiting );
    }

    // ── no widening ────────────────────────────────────────────────────

    /**
     * The scout from #3566: on the panel, entitled to the case through a
     * player-scoped matrix row, and holding no synthesis capability. They
     * are the one caller who reaches `get_case()` without passing
     * `canViewSynthesis()`, so they are the test that the three blocks did
     * not quietly widen the route.
     */
    public function test_a_caller_without_the_synthesis_gets_exactly_what_they_got_before(): void {
        $scout = self::factory()->user->create( [ 'role' => 'tt_scout', 'display_name' => 'Ingrid Scout' ] );
        ( new TrialCaseStaffRepository() )->assign( $this->case_id, $scout, 'Scout' );

        $matrix = new MatrixRepository();
        $matrix->setRow( 'scout', 'trial_cases', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, '' );
        $matrix->setRow( 'scout', 'trial_inputs', MatrixGate::CHANGE, MatrixGate::SCOPE_PLAYER, '' );
        MatrixRepository::clearCache();

        $this->writeInput( $this->panellist, 6.0, 'Leest het spel.', true );
        ( new TrialExtensionsRepository() )->record(
            $this->case_id, '2026-10-19', '2026-11-02', 'Twee weken extra.', $this->manager
        );

        [ $data, $status ] = $this->get( $this->case_id, $scout );

        $matrix->removeRow( 'scout', 'trial_cases', MatrixGate::READ, MatrixGate::SCOPE_PLAYER );
        $matrix->removeRow( 'scout', 'trial_inputs', MatrixGate::CHANGE, MatrixGate::SCOPE_PLAYER );
        MatrixRepository::clearCache();

        $this->assertSame( 200, $status, 'A scout on the panel still opens the case.' );
        $this->assertArrayHasKey( 'case', $data['data'] );
        $this->assertArrayNotHasKey( 'panel_inputs', $data['data'] );
        $this->assertArrayNotHasKey( 'panel_awaiting', $data['data'] );
        $this->assertArrayNotHasKey( 'extensions', $data['data'] );
        $this->assertArrayNotHasKey( 'decision_due', $data['data'] );
        $this->assertArrayNotHasKey( 'decision_notes', $data['data']['case'] );
    }

    public function test_a_stranger_is_still_refused(): void {
        [ , $status ] = $this->get( $this->case_id, $this->outsider );

        $this->assertSame( 403, $status );
    }

    // ── the deadline ───────────────────────────────────────────────────

    public function test_an_undecided_case_ending_tomorrow_says_so(): void {
        global $wpdb;
        $wpdb->update(
            "{$this->p}tt_trial_cases",
            [ 'end_date' => gmdate( 'Y-m-d', strtotime( (string) current_time( 'Y-m-d' ) . ' +1 day' ) ) ],
            [ 'id' => $this->case_id ]
        );

        [ $data ] = $this->get( $this->case_id, $this->manager );

        $this->assertSame( 1, $data['data']['decision_due']['days_remaining'] );
        $this->assertTrue( $data['data']['decision_due']['due_soon'] );
    }

    public function test_a_decided_case_is_never_due(): void {
        global $wpdb;
        $wpdb->update(
            "{$this->p}tt_trial_cases",
            [
                'end_date' => gmdate( 'Y-m-d', strtotime( (string) current_time( 'Y-m-d' ) . ' +1 day' ) ),
                'decision' => 'admit',
            ],
            [ 'id' => $this->case_id ]
        );

        [ $data ] = $this->get( $this->case_id, $this->manager );

        $this->assertFalse( $data['data']['decision_due']['due_soon'] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function writeInput( int $user_id, ?float $rating, string $notes, bool $submit ): void {
        $repo = new TrialStaffInputsRepository();
        $repo->upsertDraft( $this->case_id, $user_id, [
            'overall_rating'  => $rating,
            'free_text_notes' => $notes,
        ] );
        if ( $submit ) $repo->submit( $this->case_id, $user_id );
    }

    /**
     * @return array{0:array<string,mixed>,1:int}
     */
    private function get( int $case_id, int $user_id ): array {
        wp_set_current_user( $user_id );
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases/' . $case_id );
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
