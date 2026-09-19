<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;

/**
 * #3673 — `GET trial-cases/{id}/inputs`.
 *
 * The panel's input could be written over REST and read only by rendering
 * the Staff inputs tab, so the head of development could call
 * `POST trial-cases/{id}/decision` — whether the academy wants a child —
 * without any way to check what the panel had said. The feature existed in
 * the view layer alone, which is the smell test in CLAUDE.md § 4.
 *
 * The visibility rule is the interesting half, and it is the repository's:
 * a manager reads everything, an assigned panel member reads their own
 * input plus the others once released. This suite drives the route so that
 * the rule is asserted where a non-WordPress front end would meet it.
 */
final class TrialInputsReadRouteTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $case_id;

    private int $manager  = 0;
    private int $coach_a  = 0;
    private int $coach_b  = 0;
    private int $outsider = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'first_name' => 'Panel', 'last_name' => 'Subject', 'status' => 'trial',
        ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_tracks", [ 'club_id' => $this->club, 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id'    => $this->club,
            'player_id'  => $player,
            'track_id'   => $track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'status'     => 'open',
            'uuid'       => wp_generate_uuid4(),
        ] );
        $this->case_id = (int) $wpdb->insert_id;

        $this->manager  = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Marco de Vries' ] );
        $this->coach_a  = $this->inputOnlyUser( 'Sanne Bakker' );
        $this->coach_b  = $this->inputOnlyUser( 'Joris Mulder' );
        $this->outsider = $this->inputOnlyUser( 'Buiten Staander' );

        $staff = new TrialCaseStaffRepository();
        $staff->assign( $this->case_id, $this->coach_a, 'Assistant coach' );
        $staff->assign( $this->case_id, $this->coach_b, 'Assistant coach' );
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

    /** @var array<int,bool> */
    private array $granted = [];

    /**
     * A panel member holding `tt_submit_trial_input` and nothing else.
     *
     * `add_cap()` is not enough: `AuthorizationModule::filterUserHasCap`
     * makes `LegacyCapMapper` authoritative for every `tt_*` cap, so a raw
     * grant on a persona-less user is recomputed against the matrix and
     * overridden. The filter below runs at 999, after the bridge.
     */
    private function inputOnlyUser( string $display_name ): int {
        $uid = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => $display_name ] );
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

        return $uid;
    }

    // ── the route ──────────────────────────────────────────────────────

    public function test_the_route_answers_a_get(): void {
        $handlers = rest_get_server()->get_routes()['/talenttrack/v1/trial-cases/(?P<id>\d+)/inputs'] ?? [];
        $methods  = [];
        foreach ( $handlers as $handler ) {
            foreach ( (array) ( $handler['methods'] ?? [] ) as $method => $enabled ) {
                if ( $enabled ) $methods[] = (string) $method;
            }
        }
        $this->assertContains( 'GET', $methods );
        $this->assertContains( 'POST', $methods, 'The write half is untouched.' );
    }

    public function test_an_unknown_case_is_a_404(): void {
        [ $data, $status ] = $this->get( 999999, $this->manager );
        $this->assertSame( 404, $status );
        $this->assertSame( 'not_found', $data['errors'][0]['code'] ?? null );
    }

    // ── the manager ────────────────────────────────────────────────────

    public function test_the_manager_reads_every_submitted_input_and_the_counts(): void {
        $this->writeInput( $this->coach_a, 7.5, 'Sterk in de duels, leest de tweede bal goed.', true );
        $this->writeInput( $this->coach_b, 6.0, 'Technisch netjes, mist tempo in de omschakeling.', true );

        [ $data, $status ] = $this->get( $this->case_id, $this->manager );

        $this->assertSame( 200, $status );
        $inputs = $data['data']['inputs'];
        $this->assertCount( 2, $inputs, 'Both submitted inputs come back before any release.' );

        $by_author = [];
        foreach ( $inputs as $input ) {
            $by_author[ (int) $input['user_id'] ] = $input;
        }
        $this->assertSame( 'Sanne Bakker', $by_author[ $this->coach_a ]['author_name'] );
        $this->assertSame( 'Sterk in de duels, leest de tweede bal goed.', $by_author[ $this->coach_a ]['free_text_notes'] );
        $this->assertEquals( 7.5, $by_author[ $this->coach_a ]['overall_rating'] );
        $this->assertNotNull( $by_author[ $this->coach_a ]['submitted_at'] );
        $this->assertNull( $by_author[ $this->coach_a ]['released_at'], 'Nothing has been released yet.' );
        $this->assertSame( 'Joris Mulder', $by_author[ $this->coach_b ]['author_name'] );

        $this->assertSame( 2, $data['data']['assigned_count'] );
        $this->assertSame( 2, $data['data']['submitted_count'] );
        $this->assertNull( $data['data']['inputs_released_at'] );
    }

    public function test_the_release_timestamp_rides_along_for_the_manager(): void {
        $this->writeInput( $this->coach_a, 7.0, 'Handig in de kleine ruimte.', true );
        $this->release();

        [ $data ] = $this->get( $this->case_id, $this->manager );

        $this->assertNotNull( $data['data']['inputs_released_at'] );
        $this->assertNotNull( $data['data']['inputs'][0]['released_at'] );
    }

    public function test_a_draft_by_someone_else_comes_back_without_its_content(): void {
        $this->writeInput( $this->coach_a, 8.0, 'Nog niet ingeleverd, eerste indruk.', false );

        [ $data ] = $this->get( $this->case_id, $this->manager );

        $this->assertCount( 1, $data['data']['inputs'] );
        $draft = $data['data']['inputs'][0];
        $this->assertNull( $draft['submitted_at'] );
        $this->assertTrue( $draft['content_withheld'] );
        $this->assertSame( '', $draft['free_text_notes'], 'A draft is its author\'s until they hand it in.' );
        $this->assertNull( $draft['overall_rating'] );
        $this->assertSame( 'Sanne Bakker', $draft['author_name'], 'Who has started is not withheld.' );
        $this->assertSame( 0, $data['data']['submitted_count'] );
    }

    // ── the panel member ───────────────────────────────────────────────

    public function test_before_release_a_panel_member_reads_only_their_own_input(): void {
        $this->writeInput( $this->coach_a, 7.5, 'Sterk in de duels.', true );
        $this->writeInput( $this->coach_b, 6.0, 'Mist tempo.', true );

        [ $data, $status ] = $this->get( $this->case_id, $this->coach_a );

        $this->assertSame( 200, $status );
        $this->assertCount( 1, $data['data']['inputs'] );
        $this->assertSame( $this->coach_a, (int) $data['data']['inputs'][0]['user_id'] );
        $this->assertSame( 'Sterk in de duels.', $data['data']['inputs'][0]['free_text_notes'] );
        $this->assertArrayNotHasKey( 'submitted_count', $data['data'], 'The panel count is the manager\'s view.' );
        $this->assertArrayNotHasKey( 'assigned_count', $data['data'] );
    }

    public function test_after_release_a_panel_member_reads_the_others_too(): void {
        $this->writeInput( $this->coach_a, 7.5, 'Sterk in de duels.', true );
        $this->writeInput( $this->coach_b, 6.0, 'Mist tempo.', true );
        $this->release();

        [ $data ] = $this->get( $this->case_id, $this->coach_a );

        $this->assertCount( 2, $data['data']['inputs'] );
        $notes = array_column( $data['data']['inputs'], 'free_text_notes' );
        $this->assertContains( 'Mist tempo.', $notes );
    }

    public function test_an_unsubmitted_draft_by_another_is_never_released(): void {
        $this->writeInput( $this->coach_a, 7.5, 'Sterk in de duels.', true );
        $this->release();
        // Written after the release, so it carries no released_at either.
        $this->writeInput( $this->coach_b, null, 'Nog aan het schrijven.', false );

        [ $data ] = $this->get( $this->case_id, $this->coach_a );

        $authors = array_map( 'intval', array_column( $data['data']['inputs'], 'user_id' ) );
        $this->assertNotContains( $this->coach_b, $authors, 'A draft is not a submitted input.' );
    }

    public function test_a_submitter_who_is_not_on_the_panel_is_refused(): void {
        $this->writeInput( $this->coach_a, 7.5, 'Sterk in de duels.', true );

        [ $data, $status ] = $this->get( $this->case_id, $this->outsider );

        $this->assertSame( 403, $status );
        $this->assertSame( 'forbidden', $data['errors'][0]['code'] ?? null );
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

    /** The same pair of writes `POST inputs/release` performs. */
    private function release(): void {
        ( new TrialStaffInputsRepository() )->release( $this->case_id, $this->manager );
        ( new TrialCasesRepository() )->releaseInputs( $this->case_id, $this->manager );
    }

    /**
     * @return array{0:array<string,mixed>,1:int}
     */
    private function get( int $case_id, int $user_id ): array {
        wp_set_current_user( $user_id );
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases/' . $case_id . '/inputs' );
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
