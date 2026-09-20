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
 * #3849 — the substitution endpoint judges a swap at the point in the
 * match it belongs to.
 *
 * It used to take the first-half XI and apply every substitution to it,
 * whichever half they were logged in. On a match with a second-half
 * line-up that blocked the correction a coach most often needs: the
 * player who actually came off was "not on the pitch" because he only
 * appears in the half-2 XI, and the player who came on was "already on"
 * because he started the match. Both directions of the same swap refused,
 * so the sub log could never be completed afterwards.
 */
final class MatchExecutionLateSubHalfTwoRestTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8181;
    private const HALF_LENGTH = 35;

    private int $exec_id = 0;
    private int $prep_id = 0;

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

    /** An activity + prep with a first-half XI of 1..5, and a finished match. */
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

        $prep_repo     = new MatchPrepRepository();
        $this->prep_id = $prep_repo->ensureForActivity( self::ACTIVITY_ID, self::HALF_LENGTH );
        $prep_repo->replaceLineupForHalf( $this->prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5 ] );

        $exec_repo     = new MatchExecutionRepository();
        $this->exec_id = $exec_repo->ensureForActivity( self::ACTIVITY_ID, $this->prep_id );
        $exec_repo->update( $this->exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );
    }

    /** Players 6 and 7 take the places of 4 and 5 for the second half. */
    private function seedHalfTwoLineup(): void {
        ( new MatchPrepRepository() )->replaceLineupForHalf(
            $this->prep_id,
            2,
            [ 1 => 1, 2 => 2, 3 => 3, 4 => 6, 5 => 7 ]
        );
    }

    private function post( string $action, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/' . $action );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    private function sub( int $half, int $minute, int $off, int $on ): \WP_REST_Response {
        return $this->post( 'substitution', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => $half,
            'minute'     => $minute,
            'player_off' => $off,
            'player_on'  => $on,
        ] );
    }

    /** The repro as filed: taking off a player who only plays the second half. */
    public function test_a_half_two_player_can_be_taken_off_in_the_second_half(): void {
        $this->seedHalfTwoLineup();

        $res = $this->sub( 2, 20, 6, 9 );

        $this->assertSame( 200, $res->get_status(), '6 is on the pitch for the second half' );
    }

    /** And the other direction of the same swap, which was also refused. */
    public function test_a_player_left_out_of_the_half_two_line_up_can_come_back_on(): void {
        $this->seedHalfTwoLineup();

        $res = $this->sub( 2, 20, 6, 4 );

        $this->assertSame( 200, $res->get_status(), '4 started the match but not the second half' );
    }

    /** The guard still guards: neither XI, never subbed on, still refused. */
    public function test_a_player_in_neither_line_up_is_still_refused(): void {
        $this->seedHalfTwoLineup();

        $res = $this->sub( 2, 20, 99, 9 );

        $this->assertSame( 400, $res->get_status() );
        $data = $res->get_data();
        $this->assertSame( 'player_off_not_on_pitch', $data['errors'][0]['code'] ?? '' );
    }

    /** A half-2 player is not on the pitch during the first half. */
    public function test_a_half_two_player_cannot_be_taken_off_in_the_first_half(): void {
        $this->seedHalfTwoLineup();

        $res = $this->sub( 1, 20, 6, 9 );

        $this->assertSame( 400, $res->get_status() );
    }

    /**
     * No half-2 line-up, but subs already logged in the second half: a
     * forgotten first-half swap is judged on the first half, where the
     * player was still on the pitch.
     */
    public function test_a_forgotten_first_half_sub_is_judged_on_the_first_half(): void {
        $this->assertSame( 200, $this->sub( 2, 10, 5, 15 )->get_status() );

        $res = $this->sub( 1, 20, 5, 16 );

        $this->assertSame( 200, $res->get_status(), '5 was on the pitch in the 20th minute of the first half' );
    }

    /** The minute matters within the half, not only the half. */
    public function test_a_first_half_sub_before_an_earlier_one_is_judged_at_its_own_minute(): void {
        $this->assertSame( 200, $this->sub( 1, 10, 5, 15 )->get_status() );

        // 15 only came on in the 10th minute, so he cannot come off in the 5th.
        $this->assertSame( 400, $this->sub( 1, 5, 15, 16 )->get_status() );
        // 5 can: he was still on then.
        $this->assertSame( 200, $this->sub( 1, 5, 5, 16 )->get_status() );
    }
}
