<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchExecution\Repositories\TrackedEventsRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * Match-execution rebuild — tracked development-action events (Part A2).
 *
 * A tracked event is one coach tap of the +/- counter on a prep-flagged
 * player (e.g. "runs in behind"). These are per-player development metrics,
 * distinct from goals — they never touch the score. Append-only with a
 * soft-delete undo, idempotent on the client event_uuid. The endpoints only
 * accept a player the match plan flagged as tracked, validate the minute
 * range, and refuse once the match is finalized.
 */
final class MatchExecutionTrackedEventsRestTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8381;
    private const HALF_LENGTH = 35; // minute range 0..45 (half + 10 stoppage)

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

    /**
     * Prepped activity, first-half XI = players 1..5. Player 3 is tracked via
     * an attention_text label; player 5 via the is_specific_goal flag. Players
     * 1/2/4 are NOT tracked. Execution seeded in FIRST_HALF (live capture).
     */
    private function seedMatch(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => 1,
            'title'             => 'Tracked match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );

        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, self::HALF_LENGTH );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5 ] );
        $prep_repo->replacePlayerGoals( $prep_id, [
            3 => [ 'attention_text' => 'Runs in behind' ],
            5 => [ 'is_specific_goal' => true ],
        ] );

        $exec_repo     = new MatchExecutionRepository();
        $this->exec_id = $exec_repo->ensureForActivity( self::ACTIVITY_ID, $prep_id );
        $exec_repo->update( $this->exec_id, [ 'state' => MatchExecutionState::FIRST_HALF ] );
    }

    private function postTracked( array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/tracked-event' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    private function deleteTracked( string $uuid ): \WP_REST_Response {
        $req = new WP_REST_Request( 'DELETE', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/tracked-event/' . $uuid );
        return rest_do_request( $req );
    }

    private function errCode( \WP_REST_Response $res ): string {
        return (string) ( $res->get_data()['errors'][0]['code'] ?? '' );
    }

    public function test_tracked_routes_are_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(
            '/talenttrack/v1/match-execution/(?P<activity_id>\d+)/tracked-event',
            $routes
        );
        $this->assertArrayHasKey(
            '/talenttrack/v1/match-execution/(?P<activity_id>\d+)/tracked-event/(?P<event_uuid>[a-f0-9-]+)',
            $routes
        );
    }

    public function test_logging_a_tracked_action_counts_and_defaults_the_label(): void {
        $uuid = wp_generate_uuid4();
        $res  = $this->postTracked( [
            'event_uuid' => $uuid,
            'player_id'  => 3,
            'half'       => 1,
            'minute'     => 20,
            // action_label omitted → resolves from the prep attention_text.
        ] );
        $this->assertSame( 200, $res->get_status() );

        $tracked = new TrackedEventsRepository();
        $this->assertSame( 1, $tracked->countsByPlayer( $this->exec_id )[3] ?? 0 );

        $events = $tracked->listTrackedEvents( $this->exec_id );
        $this->assertCount( 1, $events );
        $this->assertSame( 'Runs in behind', (string) $events[0]->action_label, 'label denormalized from prep' );
    }

    public function test_untracked_player_is_rejected(): void {
        $res = $this->postTracked( [
            'event_uuid' => wp_generate_uuid4(),
            'player_id'  => 1, // not flagged in the plan
            'half'       => 1,
            'minute'     => 10,
        ] );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'player_not_tracked', $this->errCode( $res ) );
    }

    public function test_out_of_range_minute_is_rejected(): void {
        $res = $this->postTracked( [
            'event_uuid' => wp_generate_uuid4(),
            'player_id'  => 3,
            'half'       => 1,
            'minute'     => 60, // > 35 + 10 stoppage
        ] );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'minute_out_of_range', $this->errCode( $res ) );
    }

    public function test_missing_fields_are_rejected(): void {
        $res = $this->postTracked( [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 1,
            'minute'     => 10,
            // no player_id
        ] );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'bad_input', $this->errCode( $res ) );
    }

    public function test_replay_of_same_uuid_is_idempotent(): void {
        $uuid = wp_generate_uuid4();
        $body = [ 'event_uuid' => $uuid, 'player_id' => 3, 'half' => 1, 'minute' => 20 ];
        $this->assertSame( 200, $this->postTracked( $body )->get_status() );
        $this->assertSame( 200, $this->postTracked( $body )->get_status(), 'replay accepted' );

        $this->assertSame( 1, ( new TrackedEventsRepository() )->countsByPlayer( $this->exec_id )[3] ?? 0,
            'replay did not double-count' );
    }

    public function test_undo_soft_deletes_and_drops_the_count(): void {
        $uuid = wp_generate_uuid4();
        $this->postTracked( [ 'event_uuid' => $uuid, 'player_id' => 3, 'half' => 1, 'minute' => 20 ] );

        $res = $this->deleteTracked( $uuid );
        $this->assertSame( 200, $res->get_status() );

        $tracked = new TrackedEventsRepository();
        $this->assertArrayNotHasKey( 3, $tracked->countsByPlayer( $this->exec_id ), 'count drops to zero' );
        $this->assertTrue( $tracked->trackedEventExists( $uuid ), 'row soft-deleted, not gone' );
    }

    public function test_undo_unknown_uuid_returns_404(): void {
        $res = $this->deleteTracked( wp_generate_uuid4() );
        $this->assertSame( 404, $res->get_status() );
    }

    public function test_writes_refused_once_finalized(): void {
        $uuid = wp_generate_uuid4();
        $this->postTracked( [ 'event_uuid' => $uuid, 'player_id' => 3, 'half' => 1, 'minute' => 20 ] );
        ( new MatchExecutionRepository() )->update( $this->exec_id, [ 'state' => MatchExecutionState::FINALIZED ] );

        $post = $this->postTracked( [ 'event_uuid' => wp_generate_uuid4(), 'player_id' => 3, 'half' => 1, 'minute' => 25 ] );
        $this->assertSame( 409, $post->get_status(), 'no new taps once finalized' );

        $del = $this->deleteTracked( $uuid );
        $this->assertSame( 409, $del->get_status(), 'no undo once finalized' );
    }
}
