<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #3851 — every refusal the match-execution screen can hit carries a
 * sentence a coach can act on, in the envelope the screen reads.
 *
 * The screen used to throw away the body and show "HTTP 400", which reads
 * as "the app is broken" rather than "swap those two dropdowns". It now
 * reads `errors[0].message`, so these routes have to keep putting one
 * there: a code with an empty message would put the coach back in front of
 * a bare status.
 */
final class MatchExecutionRefusalMessageTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8282;
    private const HALF_LENGTH = 35;

    private int $exec_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->seedMatch();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private function seedMatch(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => 1,
            'title'             => 'Test match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );

        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, self::HALF_LENGTH );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5 ] );

        $exec_repo     = new MatchExecutionRepository();
        $this->exec_id = $exec_repo->ensureForActivity( self::ACTIVITY_ID, $prep_id );
        $exec_repo->update( $this->exec_id, [ 'state' => MatchExecutionState::SECOND_HALF ] );
    }

    private function post( string $action, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/' . $action );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    /**
     * The shape the screen reads: `errors[0]` with a code and a sentence
     * that is not the code repeated back.
     */
    private function assertRefusalIsReadable( \WP_REST_Response $res, string $expected_code ): void {
        $data  = $res->get_data();
        $first = $data['errors'][0] ?? null;

        $this->assertIsArray( $first, 'a refusal answers in the errors envelope' );
        $this->assertSame( $expected_code, $first['code'] ?? '' );

        $message = (string) ( $first['message'] ?? '' );
        $this->assertNotSame( '', $message, 'a refusal the coach can act on needs a sentence' );
        $this->assertNotSame( $expected_code, $message, 'the code is not a sentence' );
        $this->assertStringNotContainsString( 'HTTP', $message );
    }

    public function test_a_player_not_on_the_pitch_says_so(): void {
        $res = $this->post( 'substitution', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 2,
            'minute'     => 20,
            'player_off' => 99,
            'player_on'  => 15,
        ] );

        $this->assertSame( 400, $res->get_status() );
        $this->assertRefusalIsReadable( $res, 'player_off_not_on_pitch' );
    }

    public function test_a_player_already_on_says_so(): void {
        $res = $this->post( 'substitution', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 2,
            'minute'     => 20,
            'player_off' => 5,
            'player_on'  => 3,
        ] );

        $this->assertSame( 400, $res->get_status() );
        $this->assertRefusalIsReadable( $res, 'player_on_already_on' );
    }

    public function test_a_minute_outside_the_half_says_which_minutes_are_accepted(): void {
        $res = $this->post( 'substitution', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 2,
            'minute'     => 200,
            'player_off' => 5,
            'player_on'  => 15,
        ] );

        $this->assertSame( 400, $res->get_status() );
        $this->assertRefusalIsReadable( $res, 'minute_out_of_range' );
        $this->assertStringContainsString( '45', (string) $res->get_data()['errors'][0]['message'], 'the sentence names the limit' );
    }

    public function test_a_late_goal_out_of_range_says_so_too(): void {
        $res = $this->post( 'goal-event', [
            'event_uuid' => wp_generate_uuid4(),
            'player_id'  => 3,
            'half'       => 2,
            'minute'     => 999,
        ] );

        $this->assertSame( 400, $res->get_status() );
        $this->assertRefusalIsReadable( $res, 'minute_out_of_range' );
    }

    public function test_a_write_against_a_finalized_match_says_it_is_finalized(): void {
        ( new MatchExecutionRepository() )->update( $this->exec_id, [ 'state' => MatchExecutionState::FINALIZED ] );

        $res = $this->post( 'substitution', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 2,
            'minute'     => 20,
            'player_off' => 5,
            'player_on'  => 15,
        ] );

        $this->assertSame( 409, $res->get_status() );
        $this->assertRefusalIsReadable( $res, 'finalized' );
    }

    public function test_a_malformed_payload_still_says_what_is_wrong(): void {
        $res = $this->post( 'substitution', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 2,
            'minute'     => 20,
            'player_off' => 0,
            'player_on'  => 0,
        ] );

        $this->assertSame( 400, $res->get_status() );
        $this->assertRefusalIsReadable( $res, 'bad_input' );
    }
}
