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
 * #2857 — the scoreline is derived from the goal log on BOTH sides.
 *
 * #2275 derived only the away score, while the home score was a free number
 * a stepper wrote through `POST /score`. The two had nothing holding them
 * together: the scoreboard could read 3–1 over a goal list holding one goal,
 * and a hand-set away score was silently overwritten the next time any
 * opponent goal was touched.
 *
 * These cases pin the property that replaces all of that — the stored score
 * always equals the count of non-reversed goal events per team — and pin the
 * removal of the endpoint that could break it.
 */
final class MatchExecutionDerivedScoreRestTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8284;
    private const HALF_LENGTH = 35;
    private const SQUAD_A = 1;
    private const SQUAD_B = 2;

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
            'title'             => 'Derived score match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );

        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, self::HALF_LENGTH );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5 ] );

        $exec_repo     = new MatchExecutionRepository();
        $this->exec_id = $exec_repo->ensureForActivity( self::ACTIVITY_ID, $prep_id );
        $exec_repo->update( $this->exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );
    }

    private function post( string $action, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/' . $action );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    private function delete( string $path ): \WP_REST_Response {
        $req = new WP_REST_Request( 'DELETE', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/' . $path );
        return rest_do_request( $req );
    }

    /** @return array{home:int, away:int} */
    private function storedScore(): array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT home_score, away_score FROM {$wpdb->prefix}tt_match_execution WHERE id = %d",
            $this->exec_id
        ) );
        return [ 'home' => (int) $row->home_score, 'away' => (int) $row->away_score ];
    }

    private function logGoal( string $team, array $extra = [] ): string {
        $uuid = wp_generate_uuid4();
        $res  = $this->post( 'goal-event', array_merge( [
            'event_uuid' => $uuid,
            'team'       => $team,
            'half'       => 1,
            'minute'     => 20,
            'player_id'  => $team === 'home' ? self::SQUAD_A : 0,
        ], $extra ) );
        $this->assertSame( 200, $res->get_status() );
        return $uuid;
    }

    public function test_home_score_derives_from_our_goals(): void {
        $this->logGoal( 'home' );
        $this->assertSame( 1, $this->storedScore()['home'] );

        $this->logGoal( 'home', [ 'player_id' => self::SQUAD_B ] );
        $this->assertSame( 2, $this->storedScore()['home'] );
    }

    public function test_both_sides_derive_independently(): void {
        $this->logGoal( 'home' );
        $this->logGoal( 'home', [ 'player_id' => self::SQUAD_B ] );
        $this->logGoal( 'away' );

        $this->assertSame( [ 'home' => 2, 'away' => 1 ], $this->storedScore() );
    }

    public function test_undoing_a_goal_takes_the_score_with_it(): void {
        $uuid = $this->logGoal( 'home' );
        $this->logGoal( 'home', [ 'player_id' => self::SQUAD_B ] );
        $this->assertSame( 2, $this->storedScore()['home'] );

        $this->assertSame( 200, $this->delete( 'goal-event/' . $uuid )->get_status() );
        $this->assertSame( 1, $this->storedScore()['home'], 'the score follows the goal down' );
    }

    /**
     * An unattributed goal still moves the score — the scoreline is a count of
     * goals, not of goals we could name a scorer for.
     */
    public function test_unattributed_and_own_goals_still_count(): void {
        $this->logGoal( 'home', [ 'player_id' => 0 ] );
        $this->logGoal( 'home', [ 'player_id' => 0, 'is_own_goal' => true ] );

        $this->assertSame( 2, $this->storedScore()['home'] );
    }

    /**
     * The endpoint that let a coach write a scoreline with no event behind it
     * is gone. Its absence is the guarantee that the score cannot drift from
     * the goal log, so it is worth a test of its own rather than only being
     * "not called any more".
     */
    public function test_score_endpoint_is_gone(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayNotHasKey(
            '/talenttrack/v1/match-execution/(?P<activity_id>\d+)/score',
            $routes,
            'POST /score must not exist — it is how the score and the goal log came apart'
        );

        $res = $this->post( 'score', [ 'home' => 9, 'away' => 9 ] );
        $this->assertSame( 404, $res->get_status() );
        $this->assertSame( [ 'home' => 0, 'away' => 0 ], $this->storedScore() );
    }

    /**
     * The old away stepper wrote a value that the next opponent-goal write
     * silently overwrote. With no way to hand-set a score, a stale stored
     * value cannot survive the next goal either.
     */
    public function test_a_stale_stored_score_is_corrected_by_the_next_goal(): void {
        ( new MatchExecutionRepository() )->update( $this->exec_id, [
            'home_score' => 7,
            'away_score' => 7,
        ] );

        $this->logGoal( 'home' );

        $this->assertSame( [ 'home' => 1, 'away' => 0 ], $this->storedScore() );
    }
}
