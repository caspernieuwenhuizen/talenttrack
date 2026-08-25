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
 * #2856 — goal attribution. A goal now carries an optional assist, may name
 * no scorer at all, and may be marked as an own goal. Covers the POST and
 * PATCH payloads, the squad and self-assist guards, and the partial-PATCH
 * rule that keeps the pre-existing minute-only correction working without
 * wiping the attribution beside it.
 */
final class MatchExecutionGoalAttributionRestTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8283;
    private const HALF_LENGTH = 35;

    /** In the seeded lineup, so a valid scorer / assist. */
    private const SQUAD_A = 1;
    private const SQUAD_B = 2;

    /** Not in the seeded lineup or availability. */
    private const OUTSIDER = 991;

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
            'title'             => 'Attribution match',
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

    private function goalRow( string $uuid ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_match_execution_goal_events WHERE event_uuid = %s",
            $uuid
        ) );
    }

    private function logGoal( array $extra = [] ): string {
        $uuid = wp_generate_uuid4();
        $res  = $this->post( 'goal-event', array_merge( [
            'event_uuid' => $uuid,
            'half'       => 1,
            'minute'     => 20,
            'team'       => 'home',
            'player_id'  => self::SQUAD_A,
        ], $extra ) );
        $this->assertSame( 200, $res->get_status() );
        return $uuid;
    }

    // ---------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------

    public function test_migration_added_the_attribution_columns(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'tt_match_execution_goal_events';
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
        $this->assertContains( 'assist_player_id', $columns );
        $this->assertContains( 'is_own_goal', $columns );
    }

    public function test_goal_stores_an_assist(): void {
        $uuid = $this->logGoal( [ 'assist_player_id' => self::SQUAD_B ] );
        $row  = $this->goalRow( $uuid );

        $this->assertNotNull( $row );
        $this->assertSame( self::SQUAD_A, (int) $row->player_id );
        $this->assertSame( self::SQUAD_B, (int) $row->assist_player_id );
        $this->assertSame( 0, (int) $row->is_own_goal );
    }

    public function test_goal_without_an_assist_stores_null_not_zero(): void {
        $uuid = $this->logGoal();
        $row  = $this->goalRow( $uuid );

        $this->assertNotNull( $row );
        $this->assertNull( $row->assist_player_id, 'an absent assist must be NULL, not player 0' );
    }

    public function test_own_goal_is_flagged(): void {
        $uuid = $this->logGoal( [ 'player_id' => 0, 'is_own_goal' => true ] );
        $row  = $this->goalRow( $uuid );

        $this->assertNotNull( $row );
        $this->assertSame( 1, (int) $row->is_own_goal );
        $this->assertSame( 0, (int) $row->player_id );
    }

    public function test_pre_existing_goals_read_back_unattributed(): void {
        // A goal written through the repository the way #2275 wrote them,
        // with no attribution arguments at all.
        $uuid = wp_generate_uuid4();
        ( new MatchExecutionRepository() )->logGoalEvent(
            $this->exec_id, $uuid, self::SQUAD_A, 2, 11, 'home'
        );
        $row = $this->goalRow( $uuid );

        $this->assertNotNull( $row );
        $this->assertNull( $row->assist_player_id );
        $this->assertSame( 0, (int) $row->is_own_goal );
    }

    // ---------------------------------------------------------------
    // Guards
    // ---------------------------------------------------------------

    public function test_self_assist_is_refused(): void {
        $res = $this->post( 'goal-event', [
            'event_uuid'       => wp_generate_uuid4(),
            'half'             => 1,
            'minute'           => 20,
            'team'             => 'home',
            'player_id'        => self::SQUAD_A,
            'assist_player_id' => self::SQUAD_A,
        ] );
        $this->assertSame( 400, $res->get_status(), 'a player cannot assist their own goal' );
    }

    public function test_scorer_outside_the_squad_is_refused(): void {
        $res = $this->post( 'goal-event', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 1,
            'minute'     => 20,
            'team'       => 'home',
            'player_id'  => self::OUTSIDER,
        ] );
        $this->assertSame( 400, $res->get_status() );
    }

    public function test_assist_outside_the_squad_is_refused(): void {
        $res = $this->post( 'goal-event', [
            'event_uuid'       => wp_generate_uuid4(),
            'half'             => 1,
            'minute'           => 20,
            'team'             => 'home',
            'player_id'        => self::SQUAD_A,
            'assist_player_id' => self::OUTSIDER,
        ] );
        $this->assertSame( 400, $res->get_status() );
    }

    // ---------------------------------------------------------------
    // PATCH
    // ---------------------------------------------------------------

    public function test_patch_attributes_a_goal_that_had_no_scorer(): void {
        $uuid = $this->logGoal( [ 'player_id' => 0 ] );

        $res = $this->patch( 'goal-event/' . $uuid, [
            'player_id'        => self::SQUAD_A,
            'assist_player_id' => self::SQUAD_B,
        ] );
        $this->assertSame( 200, $res->get_status() );

        $row = $this->goalRow( $uuid );
        $this->assertSame( self::SQUAD_A, (int) $row->player_id );
        $this->assertSame( self::SQUAD_B, (int) $row->assist_player_id );
    }

    public function test_minute_only_patch_leaves_the_attribution_alone(): void {
        $uuid = $this->logGoal( [ 'assist_player_id' => self::SQUAD_B ] );

        $res = $this->patch( 'goal-event/' . $uuid, [ 'half' => 2, 'minute' => 7 ] );
        $this->assertSame( 200, $res->get_status() );

        $row = $this->goalRow( $uuid );
        $this->assertSame( 2, (int) $row->half );
        $this->assertSame( 7, (int) $row->minute_in_half );
        $this->assertSame( self::SQUAD_A, (int) $row->player_id, 'the scorer survives a minute correction' );
        $this->assertSame( self::SQUAD_B, (int) $row->assist_player_id, 'the assist survives a minute correction' );
    }

    public function test_attribution_only_patch_leaves_the_minute_alone(): void {
        $uuid = $this->logGoal( [ 'minute' => 31 ] );

        $res = $this->patch( 'goal-event/' . $uuid, [ 'player_id' => self::SQUAD_B ] );
        $this->assertSame( 200, $res->get_status() );

        $row = $this->goalRow( $uuid );
        $this->assertSame( 31, (int) $row->minute_in_half, 'an attribution edit must not reset the minute' );
        $this->assertSame( self::SQUAD_B, (int) $row->player_id );
    }

    public function test_patch_can_clear_an_assist(): void {
        $uuid = $this->logGoal( [ 'assist_player_id' => self::SQUAD_B ] );

        $res = $this->patch( 'goal-event/' . $uuid, [ 'assist_player_id' => 0 ] );
        $this->assertSame( 200, $res->get_status() );

        $row = $this->goalRow( $uuid );
        $this->assertNull( $row->assist_player_id, '"I was wrong about the assist" must be expressible' );
    }

    public function test_patch_refuses_a_self_assist(): void {
        $uuid = $this->logGoal();

        $res = $this->patch( 'goal-event/' . $uuid, [ 'assist_player_id' => self::SQUAD_A ] );
        $this->assertSame( 400, $res->get_status() );
    }

    public function test_empty_patch_is_refused(): void {
        $uuid = $this->logGoal();

        $res = $this->patch( 'goal-event/' . $uuid, [] );
        $this->assertSame( 400, $res->get_status() );
    }

    public function test_attribution_patch_refused_once_finalized(): void {
        $uuid = $this->logGoal();
        ( new MatchExecutionRepository() )->update( $this->exec_id, [ 'state' => MatchExecutionState::FINALIZED ] );

        $res = $this->patch( 'goal-event/' . $uuid, [ 'player_id' => self::SQUAD_B ] );
        $this->assertNotSame( 200, $res->get_status(), 'a finalized match refuses attribution edits' );
    }
}
