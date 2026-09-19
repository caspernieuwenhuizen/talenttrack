<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * #3712 — `GET /audit-log` resolves the actor to a name.
 *
 * The server-rendered audit screen joins `wp_users` and prints the actor's
 * display name; the REST controller returned a bare numeric `user_id` and
 * there is no route in this namespace that turns an arbitrary WP user id
 * into a person. The safeguarding question the audit log exists to answer —
 * who looked at or exported this child's data — was therefore unanswerable
 * over the API.
 *
 * Asserts at the REST boundary:
 *   - a real actor's entry carries `user_name` = their display name;
 *   - a system-written entry (`user_id` 0) carries `user_name` null;
 *   - an entry whose account has since been deleted carries `user_name`
 *     null and keeps its `user_id` (attribution is not erased);
 *   - the `user_id` / `action` / `entity` / date filters and the
 *     `X-WP-Total` header still behave, now that every WHERE column is
 *     qualified against the joined table;
 *   - another club's rows stay invisible.
 */
final class AuditLogRestUserNameTest extends WP_UnitTestCase {

    private string $p = '';

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();
        $this->p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$this->p}tt_audit_log" );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/talenttrack/v1/audit-log', $routes );
    }

    public function test_unauthenticated_request_is_denied(): void {
        wp_set_current_user( 0 );

        $res    = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/audit-log' ) );
        $status = $res->get_status();

        $this->assertNotSame( 200, $status, 'must not answer 200 to an anonymous caller' );
        $this->assertLessThan( 500, $status, 'denial is a 4xx, not a crash' );
    }

    public function test_real_actor_is_resolved_to_a_display_name(): void {
        $actor  = self::factory()->user->create( [
            'role'         => 'administrator',
            'display_name' => 'Ingrid de Vries',
        ] );
        $reader = $this->reader();
        $this->resetLog();

        $this->insertEntry( $actor, 'export.generated', 'player_evaluation', 11928 );

        $rows = $this->readAs( $reader, [ 'user_id' => $actor ] );

        $this->assertCount( 1, $rows );
        $this->assertSame( $actor, $rows[0]['user_id'] );
        $this->assertSame( 'Ingrid de Vries', $rows[0]['user_name'] );
        $this->assertSame( 'export.generated', $rows[0]['action'] );
    }

    public function test_system_entry_has_a_null_user_name(): void {
        $reader = $this->reader();
        $this->resetLog();

        $this->insertEntry( 0, 'team.purged', 'team', 7 );

        $rows = $this->readAs( $reader, [ 'action' => 'team.purged' ] );

        $this->assertCount( 1, $rows );
        $this->assertSame( 0, $rows[0]['user_id'] );
        $this->assertArrayHasKey( 'user_name', $rows[0] );
        $this->assertNull( $rows[0]['user_name'], 'a system-written entry has no actor to name' );
    }

    public function test_absent_account_keeps_its_user_id_and_reports_a_null_name(): void {
        $reader = $this->reader();
        $this->resetLog();

        // A user id with no row in `wp_users` — what an entry written by a
        // since-deleted account looks like.
        $gone = 987654;
        $this->assertFalse( get_userdata( $gone ), 'fixture id must not exist' );
        $this->insertEntry( $gone, 'player.injuries_viewed', 'player', 42 );

        $rows = $this->readAs( $reader, [ 'action' => 'player.injuries_viewed' ] );

        $this->assertCount( 1, $rows, 'the LEFT JOIN must not drop the row with the account' );
        $this->assertSame( $gone, $rows[0]['user_id'], 'attribution survives the account' );
        $this->assertNull( $rows[0]['user_name'] );
    }

    public function test_filters_and_total_header_still_behave_under_the_join(): void {
        $a      = self::factory()->user->create( [ 'display_name' => 'Actor A' ] );
        $b      = self::factory()->user->create( [ 'display_name' => 'Actor B' ] );
        $reader = $this->reader();
        $this->resetLog();

        $this->insertEntry( $a, 'player.updated', 'player', 5, '2026-01-10 09:00:00' );
        $this->insertEntry( $a, 'player.updated', 'player', 6, '2026-02-10 09:00:00' );
        $this->insertEntry( $b, 'team.updated',   'team',   9, '2026-03-10 09:00:00' );

        $byUser = $this->request( $reader, [ 'user_id' => $a ] );
        $this->assertSame( '2', $byUser->get_headers()['X-WP-Total'] );
        $this->assertCount( 2, $this->rowsOf( $byUser ) );

        $byAction = $this->rowsOf( $this->request( $reader, [ 'action' => 'team.updated' ] ) );
        $this->assertCount( 1, $byAction );
        $this->assertSame( 'Actor B', $byAction[0]['user_name'] );

        $byEntity = $this->rowsOf( $this->request( $reader, [ 'entity_type' => 'player', 'entity_id' => 6 ] ) );
        $this->assertCount( 1, $byEntity );

        $byDate = $this->rowsOf( $this->request( $reader, [
            'date_from' => '2026-02-01 00:00:00',
            'date_to'   => '2026-02-28 23:59:59',
        ] ) );
        $this->assertCount( 1, $byDate );
        $this->assertSame( 6, $byDate[0]['entity_id'] );

        $paged = $this->request( $reader, [ 'per_page' => 2 ] );
        $this->assertSame( '3', $paged->get_headers()['X-WP-Total'] );
        $this->assertSame( '2', $paged->get_headers()['X-WP-TotalPages'] );
        $this->assertCount( 2, $this->rowsOf( $paged ) );
    }

    /**
     * #3712 adjacent — the `action` arg sanitized with `sanitize_key`, which
     * strips the dot. Every audit action is "{entity}.{verb}", so the filter
     * matched nothing at all: `player.updated` reached the query as
     * `playerupdated`.
     */
    public function test_action_filter_matches_a_dotted_action(): void {
        $actor  = self::factory()->user->create( [ 'display_name' => 'Filter Actor' ] );
        $reader = $this->reader();
        $this->resetLog();

        $this->insertEntry( $actor, 'player.updated', 'player', 3 );
        $this->insertEntry( $actor, 'team.updated',   'team',   4 );

        $res  = $this->request( $reader, [ 'action' => 'player.updated' ] );
        $rows = $this->rowsOf( $res );

        $this->assertCount( 1, $rows, 'the dot must survive sanitization' );
        $this->assertSame( 'player.updated', $rows[0]['action'] );
        $this->assertSame( '1', $res->get_headers()['X-WP-Total'], 'the count query filters the same way' );
    }

    public function test_another_clubs_entries_stay_invisible(): void {
        $actor  = self::factory()->user->create( [ 'display_name' => 'Other Club' ] );
        $reader = $this->reader();
        $this->resetLog();

        $this->insertEntry( $actor, 'player.updated', 'player', 1, null, CurrentClub::id() + 1 );

        $res = $this->request( $reader, [] );

        $this->assertSame( [], $this->rowsOf( $res ), 'the club filter is still applied under the alias' );
        $this->assertSame( '0', $res->get_headers()['X-WP-Total'], 'the count query is club-scoped too' );
    }

    // ---------------------------------------------------------------- helpers

    private function reader(): int {
        return self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    private function resetLog(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$this->p}tt_audit_log" );
    }

    private function insertEntry(
        int $user_id,
        string $action,
        string $entity_type,
        int $entity_id,
        ?string $created_at = null,
        ?int $club_id = null
    ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_audit_log", [
            'club_id'     => $club_id ?? CurrentClub::id(),
            'user_id'     => $user_id,
            'action'      => $action,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'payload'     => '',
            'ip_address'  => '127.0.0.1',
            'created_at'  => $created_at ?? current_time( 'mysql' ),
        ] );
    }

    /**
     * @param  array<string,mixed> $params
     */
    private function request( int $as_user, array $params ): \WP_REST_Response {
        wp_set_current_user( $as_user );
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/audit-log' );
        foreach ( $params as $key => $value ) {
            $req->set_param( $key, $value );
        }
        return rest_do_request( $req );
    }

    /**
     * @param  array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function readAs( int $as_user, array $params ): array {
        return $this->rowsOf( $this->request( $as_user, $params ) );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rowsOf( \WP_REST_Response $res ): array {
        $this->assertSame( 200, $res->get_status() );
        $body = $res->get_data();
        $this->assertIsArray( $body );
        $this->assertTrue( $body['success'] );
        $this->assertIsArray( $body['data'] );
        return array_values( $body['data'] );
    }
}
