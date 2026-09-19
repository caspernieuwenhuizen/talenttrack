<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3578 — `GET evaluations/recent` answered 403 to every account.
 *
 * The permission callback asked the matrix for `my_evaluations` at `self`
 * scope and passed no target, and MatrixGate refuses a non-global scope
 * without one. So the head coach the feed was built for was refused, and
 * so was the Head of Development on the #1942 audit path — who holds no
 * `my_evaluations` row at all, so fixing the target alone would not have
 * reached them either.
 */
final class EvaluationsRecentGateTest extends WP_UnitTestCase {

    private int $coach   = 0;
    private int $other   = 0;
    private int $hod     = 0;
    private int $eval_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        // A tt_coach with no team assignment resolves to the head_coach persona.
        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->other = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->hod   = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'first_name' => 'Bas', 'last_name' => 'Willems', 'status' => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_evaluations', [
            'club_id'   => 1,
            'player_id' => (int) $wpdb->insert_id,
            'coach_id'  => $this->coach,
            'eval_date' => gmdate( 'Y-m-d' ),
        ] );
        $this->eval_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_head_coach_reads_their_own_feed(): void {
        wp_set_current_user( $this->coach );
        $res = $this->recent();

        $this->assertSame( 200, $res->get_status() );
        $this->assertContains( $this->eval_id, $this->ids( $res ) );
    }

    public function test_the_head_of_development_audits_a_coach(): void {
        wp_set_current_user( $this->hod );
        $res = $this->recent( [ 'coach_id' => $this->coach ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertContains( $this->eval_id, $this->ids( $res ) );
    }

    public function test_a_coach_cannot_read_another_coachs_feed(): void {
        wp_set_current_user( $this->other );
        $res = $this->recent( [ 'coach_id' => $this->coach ] );

        $this->assertSame( 403, $res->get_status() );
        $this->assertSame( 'forbidden_coach_override', $res->get_data()['code'] ?? null );
    }

    public function test_an_account_with_neither_grant_is_refused(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->assertSame( 403, $this->recent()->get_status() );
    }

    public function test_a_logged_out_request_is_refused(): void {
        wp_set_current_user( 0 );
        $this->assertContains( $this->recent()->get_status(), [ 401, 403 ] );
    }

    /** @param array<string,mixed> $query */
    private function recent( array $query = [] ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/evaluations/recent' );
        foreach ( $query as $k => $v ) $req->set_param( $k, $v );
        return rest_do_request( $req );
    }

    /** @return list<int> */
    private function ids( \WP_REST_Response $res ): array {
        $rows = $res->get_data()['rows'] ?? [];
        return array_map( static fn( $row ): int => (int) ( $row['id'] ?? 0 ), is_array( $rows ) ? $rows : [] );
    }
}
