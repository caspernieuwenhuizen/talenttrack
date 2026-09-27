<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Services\ActivityHeaderActions;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #4061 — recording a match afterwards: a past match that never ran live
 * goes from `not_started` straight to the post-match review, without the
 * clock, so its substitutions, goals and minutes can still be recorded.
 *
 * The rule is one function shared by the REST route, the activity header
 * and the execution view; these tests pin the function and the route's
 * refusals, which are where the obvious implementation goes wrong: match
 * day belongs to the normal Start, a started match is not re-recordable,
 * and without a line-up there is nothing to build the minutes from.
 */
final class MatchRecordAfterwardsTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 40610;
    private const HALF_LENGTH = 35;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private static function day( int $offset ): string {
        return gmdate( 'Y-m-d', (int) strtotime( current_time( 'Y-m-d' ) . ' ' . sprintf( '%+d', $offset ) . ' day' ) );
    }

    private function seedMatch( string $date, bool $with_prep = true, bool $with_lineup = true ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => 1,
            'title'             => 'Afterwards match',
            'session_date'      => $date,
            'activity_type_key' => 'game',
        ] );

        if ( ! $with_prep ) return 0;
        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, self::HALF_LENGTH );
        if ( $with_lineup ) {
            $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5 ] );
        }
        return $prep_id;
    }

    private function recordAfterwards(): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/record-afterwards' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( '{}' );
        return rest_do_request( $req );
    }

    private static function errorCode( \WP_REST_Response $res ): string {
        $data = $res->get_data();
        return is_array( $data ) ? (string) ( $data['errors'][0]['code'] ?? $data['code'] ?? '' ) : '';
    }

    // ----- the shared rule -----

    public function test_a_past_match_that_never_started_can_be_recorded_afterwards(): void {
        $yesterday = self::day( -1 );

        $this->assertTrue( MatchExecutionState::canRecordAfterwards( MatchExecutionState::NOT_STARTED, $yesterday ) );
        $this->assertTrue( MatchExecutionState::canRecordAfterwards( '', $yesterday ), 'no execution row yet' );
        $this->assertTrue( MatchExecutionState::canRecordAfterwards( '', self::day( -400 ) ), 'any past date' );
        $this->assertTrue( MatchExecutionState::canRecordAfterwards( '', $yesterday . ' 14:30:00' ), 'a datetime column reads by its date' );
    }

    public function test_match_day_and_the_future_are_not_afterwards(): void {
        $this->assertFalse( MatchExecutionState::canRecordAfterwards( '', current_time( 'Y-m-d' ) ) );
        $this->assertFalse( MatchExecutionState::canRecordAfterwards( '', self::day( 1 ) ) );
        $this->assertFalse( MatchExecutionState::canRecordAfterwards( '', '' ) );
    }

    public function test_a_started_or_recorded_match_is_not_recorded_again(): void {
        $yesterday = self::day( -1 );
        foreach ( [ MatchExecutionState::FIRST_HALF, MatchExecutionState::HALF_TIME, MatchExecutionState::SECOND_HALF,
                    MatchExecutionState::PENDING_REVIEW, MatchExecutionState::FINALIZED ] as $state ) {
            $this->assertFalse( MatchExecutionState::canRecordAfterwards( $state, $yesterday ), $state );
        }
    }

    public function test_the_header_offers_it_on_a_planned_and_a_completed_match(): void {
        $this->assertSame( 'Record match afterwards', ActivityHeaderActions::matchExecutionLabel( true, MatchExecutionState::NOT_STARTED, false, true ) );
        $this->assertSame( 'Record match afterwards', ActivityHeaderActions::matchExecutionLabel( false, MatchExecutionState::NOT_STARTED, false, true ) );
        $this->assertNull( ActivityHeaderActions::matchExecutionLabel( false, MatchExecutionState::NOT_STARTED, false, false ) );
        $this->assertSame( 'View match', ActivityHeaderActions::matchExecutionLabel( false, MatchExecutionState::FINALIZED, false, false ) );
        $this->assertSame( 'Start match', ActivityHeaderActions::matchExecutionLabel( true, MatchExecutionState::NOT_STARTED, true, false ), 'match day is unchanged' );
    }

    // ----- the route -----

    public function test_the_route_opens_the_review_without_the_clock(): void {
        $this->seedMatch( self::day( -1 ) );

        $res = $this->recordAfterwards();
        $this->assertSame( 200, $res->get_status() );

        $exec = ( new MatchExecutionRepository() )->findByActivity( self::ACTIVITY_ID );
        $this->assertNotNull( $exec );
        $row = (array) $exec;
        $this->assertSame( MatchExecutionState::PENDING_REVIEW, (string) $row['state'] );
        $this->assertEmpty( $row['first_half_started_at'] ?? null, 'no clock was run' );
        $this->assertEmpty( $row['second_half_started_at'] ?? null );
    }

    public function test_the_review_then_takes_a_substitution_and_a_goal(): void {
        $this->seedMatch( self::day( -1 ) );
        $this->assertSame( 200, $this->recordAfterwards()->get_status() );

        $sub = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/substitution' );
        $sub->set_header( 'Content-Type', 'application/json' );
        $sub->set_body( (string) wp_json_encode( [
            'event_uuid' => wp_generate_uuid4(), 'half' => 2, 'minute' => 25, 'player_off' => 5, 'player_on' => 15,
        ] ) );
        $this->assertSame( 200, rest_do_request( $sub )->get_status() );

        $goal = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/goal-event' );
        $goal->set_header( 'Content-Type', 'application/json' );
        $goal->set_body( (string) wp_json_encode( [
            'event_uuid' => wp_generate_uuid4(), 'team' => 'home', 'half' => 1, 'minute' => 12, 'player_id' => 1,
        ] ) );
        $this->assertSame( 200, rest_do_request( $goal )->get_status() );
    }

    public function test_the_route_is_audit_logged(): void {
        $this->seedMatch( self::day( -1 ) );
        $this->assertSame( 200, $this->recordAfterwards()->get_status() );

        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = %s",
            'match_execution.recorded_afterwards'
        ) );
        $this->assertSame( 1, $count );
    }

    public function test_the_route_refuses_match_day(): void {
        $this->seedMatch( current_time( 'Y-m-d' ) );

        $res = $this->recordAfterwards();
        $this->assertSame( 409, $res->get_status() );
        $this->assertSame( 'not_past', self::errorCode( $res ) );
    }

    public function test_the_route_refuses_a_future_match(): void {
        $this->seedMatch( self::day( 3 ) );

        $res = $this->recordAfterwards();
        $this->assertSame( 409, $res->get_status() );
        $this->assertSame( 'not_past', self::errorCode( $res ) );
    }

    public function test_the_route_refuses_a_match_already_started(): void {
        $prep_id = $this->seedMatch( self::day( -1 ) );
        $repo    = new MatchExecutionRepository();
        $exec_id = $repo->ensureForActivity( self::ACTIVITY_ID, $prep_id );
        $repo->update( $exec_id, [ 'state' => MatchExecutionState::FINALIZED ] );

        $res = $this->recordAfterwards();
        $this->assertSame( 409, $res->get_status() );
        $this->assertSame( 'bad_state', self::errorCode( $res ) );
    }

    public function test_the_route_accepts_a_not_started_execution_row(): void {
        $prep_id = $this->seedMatch( self::day( -2 ) );
        ( new MatchExecutionRepository() )->ensureForActivity( self::ACTIVITY_ID, $prep_id );

        $this->assertSame( 200, $this->recordAfterwards()->get_status() );
    }

    public function test_the_route_refuses_without_a_lineup(): void {
        $this->seedMatch( self::day( -1 ), true, false );

        $res = $this->recordAfterwards();
        $this->assertSame( 409, $res->get_status() );
        $this->assertSame( 'no_lineup', self::errorCode( $res ) );
        $this->assertNull( ( new MatchExecutionRepository() )->findByActivity( self::ACTIVITY_ID ), 'a refusal writes nothing' );
    }

    public function test_the_route_refuses_without_a_prep(): void {
        $this->seedMatch( self::day( -1 ), false );

        $res = $this->recordAfterwards();
        $this->assertSame( 409, $res->get_status() );
        $this->assertSame( 'no_lineup', self::errorCode( $res ) );
    }

    public function test_a_user_who_cannot_edit_activities_gets_403(): void {
        $this->seedMatch( self::day( -1 ) );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $this->assertSame( 403, $this->recordAfterwards()->get_status() );
    }
}
