<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Search\RecentItems;

/**
 * #4671 — `GET /me/recent` and `POST /me/recent`: only what the caller may
 * open is stored, every read re-checks, and a revoked or archived record
 * drops out with its row.
 */
final class RecentItemsTest extends WP_UnitTestCase {

    use SearchFixtures;

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
    }

    public function tear_down(): void {
        $this->searchTearDown();
        parent::tear_down();
    }

    public function test_a_record_the_caller_may_not_open_is_not_found_and_not_stored(): void {
        $coach = $this->personas['coach'];
        $res   = $this->post( $coach, [ 'type' => 'player', 'id' => $this->players['far'] ] );
        $this->assertSame( 404, $res->get_status() );
        $this->assertSame( 'not_found', (string) ( $res->get_data()['errors'][0]['code'] ?? '' ) );
        $missing = $this->post( $coach, [ 'type' => 'player', 'id' => 99999999 ] );
        $this->assertSame( 404, $missing->get_status(), 'the same answer as a missing record' );
        $this->assertSame( 0, $this->rowsFor( $coach ) );
    }

    public function test_the_body_is_checked(): void {
        $coach = $this->personas['coach'];
        $this->assertSame( 'invalid_field', (string) ( $this->post( $coach, [ 'type' => 'view', 'id' => 1 ] )->get_data()['errors'][0]['code'] ?? '' ) );
        $this->assertSame( 'unknown_field', (string) ( $this->post( $coach, [ 'type' => 'player', 'id' => $this->players['jose'], 'extra' => 1 ] )->get_data()['errors'][0]['code'] ?? '' ) );
    }

    public function test_a_revoked_or_archived_record_drops_out_and_its_row_goes(): void {
        $coach = $this->personas['coach'];
        $this->assertSame( 201, $this->post( $coach, [ 'type' => 'player', 'id' => $this->players['jose'] ] )->get_status() );
        $this->assertSame( 201, $this->post( $coach, [ 'type' => 'team', 'id' => $this->team_a ] )->get_status() );
        $this->assertSame( 201, $this->post( $coach, [ 'type' => 'activity', 'id' => $this->activities['a'] ] )->get_status() );

        $items = $this->recent( $coach );
        $this->assertCount( 3, $items );

        // The player moves to team B: the coach no longer reads them.
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'team_id' => $this->team_b ], [ 'id' => $this->players['jose'] ] );
        // The activity is archived.
        $wpdb->update( "{$wpdb->prefix}tt_activities", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $this->activities['a'] ] );

        $after = $this->recent( $coach );
        $this->assertSame( [ [ 'team', $this->team_a ] ], array_map( static fn( array $i ): array => [ $i['type'], (int) $i['id'] ], $after ) );
        $this->assertSame( 1, $this->rowsFor( $coach ), 'the dropped rows are deleted' );
    }

    public function test_only_the_last_twenty_are_kept(): void {
        $admin = $this->personas['admin'];
        $ids   = [];
        for ( $i = 0; $i < 23; $i++ ) $ids[] = $this->player( $this->team_a, 'Recent', 'Number' . $i, 40 + $i );
        foreach ( $ids as $n => $id ) {
            ( new RecentItems() )->record( $admin, 'player', $id );
            global $wpdb;
            // Distinct, increasing times so the order is decided.
            $wpdb->update( "{$wpdb->prefix}tt_user_recent", [ 'opened_at' => gmdate( 'Y-m-d H:i:s', 1700000000 + $n ) ], [ 'wp_user_id' => $admin, 'entity_id' => $id ] );
        }
        ( new RecentItems() )->record( $admin, 'player', $ids[22] );
        $this->assertSame( RecentItems::KEEP, $this->rowsFor( $admin ) );
        $this->assertSame( $ids[22], (int) $this->recent( $admin )[0]['id'], 'newest first' );
    }

    public function test_a_deleted_account_takes_its_rows_with_it(): void {
        $coach = $this->personas['coach'];
        $this->post( $coach, [ 'type' => 'player', 'id' => $this->players['jose'] ] );
        $this->assertSame( 1, $this->rowsFor( $coach ) );
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $coach );
        $this->assertSame( 0, $this->rowsFor( $coach ) );
    }

    /** @param array<string,mixed> $body */
    private function post( int $user_id, array $body ): \WP_REST_Response {
        wp_set_current_user( $user_id );
        $this->resetSearchCaches();
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/me/recent' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        return rest_do_request( $request );
    }

    /** @return list<array<string,mixed>> */
    private function recent( int $user_id ): array {
        wp_set_current_user( $user_id );
        $this->resetSearchCaches();
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/me/recent' ) );
        $this->assertSame( 200, $res->get_status() );
        return (array) $res->get_data()['data']['items'];
    }

    private function rowsFor( int $user_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_user_recent WHERE wp_user_id = %d", $user_id ) );
    }
}
