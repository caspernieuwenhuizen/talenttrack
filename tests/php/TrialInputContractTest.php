<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;

/**
 * #3606 / #3612 — `POST trial-cases/{id}/inputs` writes what it was sent,
 * refuses what it was not built for, and says what it stored.
 *
 * It read two keys, dropped everything else behind `saved: true`, and wrote
 * every column on every save: `{"overall_rating": 7}` erased the notes,
 * `{"submit": true}` erased both and then submitted an empty assessment, and
 * every save nulled the category ratings nobody sends.
 */
final class TrialInputContractTest extends WP_UnitTestCase {

    private int $case = 0;
    private int $user = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_players", [ 'club_id' => $club, 'first_name' => 'Proef', 'last_name' => 'Speler', 'status' => 'trial' ] );
        $player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'std-' . uniqid(), 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_trial_cases", [
            'club_id' => $club, 'player_id' => $player, 'track_id' => $track,
            'start_date' => '2026-09-01', 'end_date' => '2026-10-01', 'status' => 'open', 'uuid' => wp_generate_uuid4(),
        ] );
        $this->case = (int) $wpdb->insert_id;

        // A trial manager is an input author on every case.
        $this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_route_declares_its_fields(): void {
        $args = [];
        foreach ( rest_get_server()->get_routes()['/talenttrack/v1/trial-cases/(?P<id>\d+)/inputs'] ?? [] as $handler ) {
            $args += (array) ( $handler['args'] ?? [] );
        }
        foreach ( [ 'overall_rating', 'free_text_notes', 'submit' ] as $field ) {
            $this->assertArrayHasKey( $field, $args, "{$field} is not declared" );
        }
    }

    public function test_unknown_fields_are_refused_and_nothing_is_written(): void {
        [ $data, $status ] = $this->post( [ 'notes' => 'Sterk in de duels.', 'recommendation' => 'admit' ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertEqualsCanonicalizing( [ 'notes', 'recommendation' ], (array) ( $data['errors'][0]['details']['fields'] ?? [] ) );
        // #3689 — the refusal says what the route does take.
        $this->assertEqualsCanonicalizing( [ 'overall_rating', 'free_text_notes', 'submit' ], (array) ( $data['errors'][0]['details']['allowed'] ?? [] ) );
        $this->assertNull( $this->stored() );
    }

    public function test_a_body_with_nothing_to_save_is_refused(): void {
        [ $data, $status ] = $this->post( [] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'no_input_fields', $data['errors'][0]['code'] ?? null );
    }

    public function test_the_response_carries_what_was_stored(): void {
        [ $data, $status ] = $this->post( [ 'free_text_notes' => 'abc' ] );

        $this->assertSame( 200, $status );
        $this->assertSame( 'abc', $data['data']['input']['free_text_notes'] );
        $this->assertGreaterThan( 0, $data['data']['input']['id'] );
    }

    public function test_a_rating_only_save_leaves_the_notes_alone(): void {
        $this->post( [ 'free_text_notes' => 'Scant de ruimte voor de aanname.', 'overall_rating' => 7 ] );
        [ $data ] = $this->post( [ 'overall_rating' => 8 ] );

        $this->assertSame( 'Scant de ruimte voor de aanname.', $data['data']['input']['free_text_notes'] );
        $this->assertEquals( 8, $data['data']['input']['overall_rating'] );
    }

    public function test_a_bare_submit_submits_what_is_there(): void {
        $this->post( [ 'free_text_notes' => 'Goed in 1-tegen-1.', 'overall_rating' => 7 ] );
        [ $data, $status ] = $this->post( [ 'submit' => true ] );

        $this->assertSame( 200, $status );
        $this->assertTrue( $data['data']['submitted'] );
        $this->assertSame( 'Goed in 1-tegen-1.', $data['data']['input']['free_text_notes'] );
        $this->assertEquals( 7, $data['data']['input']['overall_rating'] );
        $this->assertNotNull( $data['data']['input']['submitted_at'] );
    }

    public function test_an_empty_input_cannot_be_submitted(): void {
        [ $data, $status ] = $this->post( [ 'submit' => true ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'empty_input', $data['errors'][0]['code'] ?? null );

        $this->post( [ 'free_text_notes' => '' ] );
        [ , $status ] = $this->post( [ 'submit' => true ] );
        $this->assertSame( 400, $status, 'an input with no rating and blank notes is still empty' );
        $this->assertNull( ( (array) $this->stored() )['submitted_at'] ?? null );
    }

    public function test_a_rating_off_the_scale_is_refused(): void {
        [ $data, $status ] = $this->post( [ 'overall_rating' => 42 ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'bad_rating', $data['errors'][0]['code'] ?? null );
    }

    public function test_category_ratings_survive_a_save(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_trial_case_staff_inputs", [
            'club_id' => (int) CurrentClub::id(), 'case_id' => $this->case, 'user_id' => $this->user,
            'category_ratings_json' => '{"technical":7.5}', 'free_text_notes' => 'Eerste indruk.',
        ] );

        $this->post( [ 'free_text_notes' => 'Tweede indruk.' ] );
        $this->assertSame( '{"technical":7.5}', (string) ( (array) $this->stored() )['category_ratings_json'] );

        // The web form sends both fields and never the category ratings.
        ( new TrialStaffInputsRepository() )->upsertDraft( $this->case, $this->user, [ 'overall_rating' => 7.0, 'free_text_notes' => 'Derde.' ] );
        $this->assertSame( '{"technical":7.5}', (string) ( (array) $this->stored() )['category_ratings_json'] );
    }

    private function stored(): ?object {
        return ( new TrialStaffInputsRepository() )->findForCaseUser( $this->case, $this->user );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function post( array $body ): array {
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases/' . $this->case . '/inputs' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( (object) $body ) );
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
