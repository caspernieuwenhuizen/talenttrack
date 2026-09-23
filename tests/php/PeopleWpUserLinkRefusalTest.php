<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\REST\PeopleRestController;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4019 — one WordPress account links to one active person (#1104), and
 * the refusal has to say so.
 *
 * `PeopleRepository::create()` / `update()` answer `false` both for a
 * failed write and for that rule, and the REST layer turned every `false`
 * into `500 db_error` with an empty `details`. `$wpdb->last_error` is
 * blank at that point — no query failed — so the admin linking a coach's
 * login was told the database had broken, with nothing to act on. In demo
 * mode it is worse: `list()` hides the non-demo person who holds the
 * account, so searching for them finds nothing either. Hence the id in
 * `details`.
 *
 * The 500 is still the answer for a real write failure, which the last
 * test proves by making one.
 */
final class PeopleWpUserLinkRefusalTest extends WP_UnitTestCase {

    private int $club     = 0;
    private int $holder   = 0;
    private int $accountId = 0;

    public function set_up(): void {
        parent::set_up();
        $this->club      = (int) CurrentClub::id();
        $this->accountId = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => $this->club,
            'first_name' => 'Casper',
            'last_name'  => 'Nieuwenhuizen',
            'role_type'  => 'staff',
            'wp_user_id' => $this->accountId,
            'status'     => 'active',
        ] );
        $this->holder = (int) $wpdb->insert_id;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_create_on_a_linked_account_answers_409_and_names_the_holder(): void {
        $before = $this->peopleCount();

        $res = PeopleRestController::create_person( $this->req( [
            'first_name' => 'Casper',
            'last_name'  => 'Nieuwenhuizen',
            'role_type'  => 'staff',
            'wp_user_id' => $this->accountId,
            'status'     => 'active',
        ] ) );

        $this->assertSame( 409, $res->get_status() );

        $err = $this->firstError( $res );
        $this->assertSame( 'wp_user_already_linked', (string) $err['code'] );
        $this->assertNotSame( '', (string) $err['message'] );

        $details = (array) $err['details'];
        $this->assertSame( $this->holder, (int) $details['person_id'], 'the refusal names the person holding the account' );
        $this->assertSame( $this->accountId, (int) $details['wp_user_id'] );

        $this->assertSame( $before, $this->peopleCount(), 'a refused create inserts nothing' );
    }

    public function test_update_that_would_repoint_onto_a_linked_account_answers_409(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => $this->club,
            'first_name' => 'Ingrid',
            'last_name'  => 'Bakker',
            'role_type'  => 'staff',
            'status'     => 'active',
        ] );
        $other = (int) $wpdb->insert_id;

        $res = PeopleRestController::update_person( $this->req( [
            'id'         => $other,
            'wp_user_id' => $this->accountId,
        ] ) );

        $this->assertSame( 409, $res->get_status() );
        $err = $this->firstError( $res );
        $this->assertSame( 'wp_user_already_linked', (string) $err['code'] );
        $this->assertSame( $this->holder, (int) ( (array) $err['details'] )['person_id'] );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_people WHERE id = %d",
            $other
        ) );
        $this->assertSame( 0, (int) ( $row->wp_user_id ?? 0 ), 'the refused update wrote nothing' );
    }

    public function test_a_free_account_still_creates_the_person(): void {
        $free = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $res = PeopleRestController::create_person( $this->req( [
            'first_name' => 'Gijs',
            'last_name'  => 'Willems',
            'role_type'  => 'staff',
            'wp_user_id' => $free,
            'status'     => 'active',
        ] ) );

        $this->assertSame( 200, $res->get_status() );
        $data = (array) ( (array) $res->get_data() )['data'];
        $this->assertGreaterThan( 0, (int) $data['id'] );
    }

    /** An inactive person does not resolve, so it does not hold the account. */
    public function test_an_inactive_holder_does_not_refuse(): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_people', [ 'status' => 'inactive' ], [ 'id' => $this->holder ] );

        $res = PeopleRestController::create_person( $this->req( [
            'first_name' => 'Casper',
            'last_name'  => 'Nieuwenhuizen',
            'role_type'  => 'staff',
            'wp_user_id' => $this->accountId,
            'status'     => 'active',
        ] ) );

        $this->assertSame( 200, $res->get_status() );
    }

    /** The rule-refusal mapping must not swallow a genuine write failure. */
    public function test_a_real_insert_failure_is_still_a_500_with_the_db_error(): void {
        global $wpdb;
        $p      = $wpdb->prefix;
        $reject = static function ( $query ) use ( $p ) {
            if ( strpos( (string) $query, "INSERT INTO `{$p}tt_people`" ) === 0 ) {
                return "INSERT INTO {$p}tt_no_such_table_4019 (id) VALUES (0)";
            }
            return $query;
        };

        $free       = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $suppressed = $wpdb->suppress_errors( true );
        add_filter( 'query', $reject );
        try {
            $res = PeopleRestController::create_person( $this->req( [
                'first_name' => 'Kees',
                'last_name'  => 'Hoekstra',
                'role_type'  => 'staff',
                'wp_user_id' => $free,
                'status'     => 'active',
            ] ) );
        } finally {
            remove_filter( 'query', $reject );
            $wpdb->suppress_errors( $suppressed );
        }

        $this->assertSame( 500, $res->get_status() );
        $err = $this->firstError( $res );
        $this->assertSame( 'db_error', (string) $err['code'] );
        $this->assertNotSame( '', (string) ( (array) $err['details'] )['db_error'], 'the 500 carries the DB error it used to hide' );
    }

    /** A clean write records no refusal, so the mapping never fires. */
    public function test_a_successful_create_records_no_refusal(): void {
        $repo = new PeopleRepository();
        $id   = $repo->create( [
            'first_name' => 'Thijs',
            'last_name'  => 'Wassink',
            'role_type'  => 'staff',
            'status'     => 'active',
        ] );

        $this->assertNotFalse( $id );
        $this->assertSame( '', $repo->lastRefusal() );
        $this->assertSame( 0, $repo->lastRefusalPersonId() );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function req( array $params ): \WP_REST_Request {
        $r = new \WP_REST_Request();
        foreach ( $params as $k => $v ) $r->set_param( $k, $v );
        return $r;
    }

    /** @return array<string, mixed> */
    private function firstError( \WP_REST_Response $res ): array {
        $body = (array) $res->get_data();
        $this->assertFalse( (bool) ( $body['success'] ?? true ) );
        return (array) ( $body['errors'][0] ?? [] );
    }

    private function peopleCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_people WHERE club_id = %d",
            $this->club
        ) );
    }
}
