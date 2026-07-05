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
 * #2275 — timed opponent goals. The opponent's goals become individual timed
 * events (team = 'away', no scorer) alongside ours, and the stored away_score
 * derives from their count. Covers: away goal POST (no player), the away score
 * syncing from goal counts, add/remove keeping it in step, the goal-minute
 * PATCH, and the home-goal-needs-a-scorer + finalized guards.
 */
final class MatchExecutionOpponentGoalRestTest extends WP_UnitTestCase {

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
        $exec_repo->update( $this->exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );
    }

    private function post( string $action, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/' . $action );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    private function patch( string $path, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PATCH', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/' . $path );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    private function delete( string $path ): \WP_REST_Response {
        $req = new WP_REST_Request( 'DELETE', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/' . $path );
        return rest_do_request( $req );
    }

    private function awayScore(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT away_score FROM {$wpdb->prefix}tt_match_execution WHERE id = %d",
            $this->exec_id
        ) );
    }

    public function test_away_goal_logs_without_a_scorer_and_bumps_away_score(): void {
        $res = $this->post( 'goal-event', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 1,
            'minute'     => 20,
            'team'       => 'away',
        ] );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 'away', $res->get_data()['data']['team'] ?? ( $res->get_data()['team'] ?? '' ) );
        $this->assertSame( 1, $this->awayScore(), 'away score derives from the opponent goal count' );
    }

    public function test_two_away_goals_then_delete_keeps_away_score_in_step(): void {
        $u1 = wp_generate_uuid4();
        $u2 = wp_generate_uuid4();
        $this->post( 'goal-event', [ 'event_uuid' => $u1, 'half' => 1, 'minute' => 10, 'team' => 'away' ] );
        $this->post( 'goal-event', [ 'event_uuid' => $u2, 'half' => 2, 'minute' => 15, 'team' => 'away' ] );
        $this->assertSame( 2, $this->awayScore() );

        $this->delete( 'goal-event/' . $u1 );
        $this->assertSame( 1, $this->awayScore(), 'removing an opponent goal drops the away score' );
    }

    public function test_home_goal_still_requires_a_scorer(): void {
        $res = $this->post( 'goal-event', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 1,
            'minute'     => 20,
            'team'       => 'home',
        ] );
        $this->assertSame( 400, $res->get_status(), 'a home goal without a scorer is rejected' );
    }

    public function test_goal_minute_patch_is_registered_and_edits_the_minute(): void {
        $routes = rest_get_server()->get_routes();
        $key    = '/talenttrack/v1/match-execution/(?P<activity_id>\d+)/goal-event/(?P<event_uuid>[a-f0-9-]+)';
        $this->assertArrayHasKey( $key, $routes );
        $methods = [];
        foreach ( $routes[ $key ] as $endpoint ) {
            $methods = array_merge( $methods, array_keys( (array) $endpoint['methods'] ) );
        }
        $this->assertContains( 'PATCH', $methods );

        $uuid = wp_generate_uuid4();
        $this->post( 'goal-event', [ 'event_uuid' => $uuid, 'half' => 1, 'minute' => 12, 'team' => 'away' ] );
        $res = $this->patch( 'goal-event/' . $uuid, [ 'half' => 2, 'minute' => 30 ] );
        $this->assertSame( 200, $res->get_status() );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT half, minute_in_half FROM {$wpdb->prefix}tt_match_execution_goal_events WHERE event_uuid = %s",
            $uuid
        ) );
        $this->assertSame( 2, (int) $row->half );
        $this->assertSame( 30, (int) $row->minute_in_half );
    }

    public function test_away_goal_rejected_once_finalized(): void {
        ( new MatchExecutionRepository() )->update( $this->exec_id, [ 'state' => MatchExecutionState::FINALIZED ] );
        $res = $this->post( 'goal-event', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 1,
            'minute'     => 20,
            'team'       => 'away',
        ] );
        $this->assertSame( 409, $res->get_status() );
    }
}
