<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Evaluations\EvaluationDateRule;
use TT\Modules\Import\Excel\ExcelImporter;
use TT\Modules\Wizards\Evaluation\EvaluationInserter;
use TT\Modules\Wizards\Evaluation\HybridDeepRateStep;

/**
 * #3583 — an evaluation records something that happened.
 *
 * A head coach saved evaluations dated three days ahead; until he re-dated
 * them they counted in the player's rating trend and in evaluation coverage
 * as if the session had been held. Nothing checked the date — not even its
 * format — on any of the seven paths that write one.
 */
final class EvaluationDateRuleTest extends WP_UnitTestCase {

    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        do_action( 'rest_api_init' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Bas', 'last_name' => 'Willems', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        update_option( 'timezone_string', '' );
        update_option( 'gmt_offset', 0 );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the rule ──────────────────────────────────────────────────────

    public function test_the_rule(): void {
        $today    = current_time( 'Y-m-d' );
        $tomorrow = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
        $earlier  = gmdate( 'Y-m-d', strtotime( $today . ' -3 days' ) );

        $this->assertNull( EvaluationDateRule::check( $today ) );
        $this->assertSame( EvaluationDateRule::FUTURE, EvaluationDateRule::check( $tomorrow )->get_error_code() );
        $this->assertSame( EvaluationDateRule::INVALID, EvaluationDateRule::check( '2026-13-45' )->get_error_code() );
        $this->assertSame( EvaluationDateRule::INVALID, EvaluationDateRule::check( '14-09-2026' )->get_error_code() );

        // About an activity: it must have happened, and the evaluation may
        // not be dated before it.
        $this->assertSame( EvaluationDateRule::FUTURE, EvaluationDateRule::check( $today, $tomorrow )->get_error_code() );
        $this->assertSame( EvaluationDateRule::BEFORE_ACTIVITY, EvaluationDateRule::check( $earlier, $today )->get_error_code() );
        $this->assertNull( EvaluationDateRule::check( $today, $earlier ) );
    }

    // ── REST ──────────────────────────────────────────────────────────

    public function test_rest_create(): void {
        $today    = current_time( 'Y-m-d' );
        $tomorrow = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );

        $this->assertSame( 200, $this->send( 'POST', 'evaluations', [ 'player_id' => $this->player, 'eval_date' => $today ] )[1] );

        [ $data, $status ] = $this->send( 'POST', 'evaluations', [ 'player_id' => $this->player, 'eval_date' => $tomorrow ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'future_date', $data['errors'][0]['code'] ?? null );

        [ $data, $status ] = $this->send( 'POST', 'evaluations', [ 'player_id' => $this->player, 'eval_date' => '2026-13-45' ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'invalid_date', $data['errors'][0]['code'] ?? null );
    }

    public function test_rest_update(): void {
        $today    = current_time( 'Y-m-d' );
        $tomorrow = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
        [ $data ] = $this->send( 'POST', 'evaluations', [ 'player_id' => $this->player, 'eval_date' => $today ] );
        $id       = (int) $data['data']['id'];

        $this->assertSame( 200, $this->send( 'PUT', 'evaluations/' . $id, [ 'eval_date' => $today ] )[1] );

        [ $data, $status ] = $this->send( 'PUT', 'evaluations/' . $id, [ 'eval_date' => $tomorrow ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'future_date', $data['errors'][0]['code'] ?? null );

        [ , $status ] = $this->send( 'PUT', 'evaluations/' . $id, [ 'eval_date' => '2026-13-45' ] );
        $this->assertSame( 400, $status );

        $this->assertSame( 200, $this->send( 'PUT', 'evaluations/' . $id, [ 'notes' => 'A note, no date.' ] )[1], 'a save that does not touch the date is not held up by it' );
    }

    // ── the other writers ─────────────────────────────────────────────

    public function test_rating_a_future_activity_is_refused(): void {
        $future = $this->activity( gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +2 days' ) ) );

        $result = EvaluationInserter::upsertForActivity( $this->player, $future, [ 1 => 7 ] );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( EvaluationDateRule::FUTURE, $result->get_error_code() );
    }

    public function test_the_inserter_refuses_a_future_date(): void {
        $result = EvaluationInserter::insert( [
            'player_id' => $this->player,
            'eval_date' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +1 day' ) ),
        ] );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( EvaluationDateRule::FUTURE, $result->get_error_code() );
    }

    /**
     * West of UTC in the evening, UTC is already tomorrow. The wizard used
     * `gmdate()` for its default and the rule would have refused it; the
     * default is the site's own today.
     */
    public function test_the_wizard_default_is_the_sites_today(): void {
        update_option( 'timezone_string', 'Pacific/Pago_Pago' ); // UTC-11

        $clean = ( new HybridDeepRateStep() )->validate( [], [] );

        $this->assertIsArray( $clean );
        $this->assertSame( current_time( 'Y-m-d' ), $clean['eval_date'] );
    }

    public function test_the_excel_import_reports_a_future_row_and_keeps_the_rest(): void {
        $tomorrow = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +1 day' ) );
        $rows     = [ 'evaluations' => [
            [ 'auto_key' => 'e1', 'player_key' => 'p1', 'eval_date' => '2026-03-01' ],
            [ 'auto_key' => 'e2', 'player_key' => 'p1', 'eval_date' => $tomorrow ],
        ] ];
        $warnings = [];

        $method = new ReflectionMethod( ExcelImporter::class, 'dropFutureEvaluations' );
        $method->setAccessible( true );
        $method->invokeArgs( new ExcelImporter( static fn() => null ), [ &$rows, &$warnings ] );

        $this->assertSame( [ 'e1' ], array_column( $rows['evaluations'], 'auto_key' ) );
        $this->assertCount( 1, $warnings );
        $this->assertStringContainsString( $tomorrow, $warnings[0] );
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function activity( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => 0, 'title' => 'Training',
            'session_date' => $date, 'activity_type_key' => 'training', 'activity_status_key' => 'planned',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        $response = rest_get_server()->dispatch( $request );
        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }
}
