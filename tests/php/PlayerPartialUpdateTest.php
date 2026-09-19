<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3569 — `PUT players/{id}` writes only what it is sent.
 *
 * The update used to rebuild the whole row from the request, so a PUT
 * carrying only a guardian name blanked the player's name, date of birth,
 * positions and jersey, unlinked their own account and reset their status.
 * #2866 had fixed `team_id` alone; this generalises it: an absent key
 * leaves the column as stored, and a key that is sent is honoured whatever
 * its value, because an explicit empty is how a field gets cleared.
 */
final class PlayerPartialUpdateTest extends WP_UnitTestCase {

    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id' => (int) CurrentClub::id(),
            'name'    => 'Hedel O11-1',
        ] );
        $this->team_id = (int) $wpdb->insert_id;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_partial_put_changes_only_the_field_it_sends(): void {
        $player_id = $this->createFullPlayer();
        $before    = (array) QueryHelpers::get_player( $player_id );

        $this->assertSame( 200, $this->put( $player_id, [ 'guardian_name' => 'Linda Willems' ] )->get_status() );

        $after = (array) QueryHelpers::get_player( $player_id );
        $this->assertSame( 'Linda Willems', $after['guardian_name'] );

        foreach ( [
            'first_name', 'last_name', 'date_of_birth', 'nationality', 'height_cm', 'weight_kg',
            'preferred_foot', 'preferred_positions', 'jersey_number', 'date_joined', 'team_id',
            'wp_user_id', 'status', 'media_consent', 'media_consent_at', 'media_consent_by',
        ] as $column ) {
            $this->assertEquals( $before[ $column ], $after[ $column ], "{$column} changed although the request did not send it" );
        }
    }

    public function test_explicit_empties_still_clear_the_field(): void {
        $player_id = $this->createFullPlayer();

        $this->assertSame( 200, $this->put( $player_id, [ 'jersey_number' => '' ] )->get_status() );
        $this->assertNull( QueryHelpers::get_player( $player_id )->jersey_number );

        $this->assertSame( 200, $this->put( $player_id, [ 'preferred_positions' => [] ] )->get_status() );
        $this->assertSame( [], json_decode( (string) QueryHelpers::get_player( $player_id )->preferred_positions, true ) );
    }

    public function test_consent_is_only_touched_when_it_is_sent(): void {
        $player_id = $this->createFullPlayer();
        $given     = QueryHelpers::get_player( $player_id );
        $this->assertSame( 1, (int) $given->media_consent );
        $this->assertNotEmpty( $given->media_consent_at );

        $this->put( $player_id, [ 'nationality' => 'BE' ] );
        $kept = QueryHelpers::get_player( $player_id );
        $this->assertSame( 1, (int) $kept->media_consent );
        $this->assertSame( (string) $given->media_consent_at, (string) $kept->media_consent_at );

        $this->put( $player_id, [ 'media_consent' => 0 ] );
        $withdrawn = QueryHelpers::get_player( $player_id );
        $this->assertSame( 0, (int) $withdrawn->media_consent );
        $this->assertNull( $withdrawn->media_consent_at );
        $this->assertNull( $withdrawn->media_consent_by );
    }

    /**
     * The journey diff hook gets the row after the save. Handed only the
     * sent keys, it read the missing `status` as blank and would have
     * logged a transition from "active" to nothing.
     */
    public function test_a_partial_put_records_no_journey_transition(): void {
        global $wpdb;
        $player_id = $this->createFullPlayer();
        $count     = static function () use ( $wpdb, $player_id ): int {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events WHERE player_id = %d",
                $player_id
            ) );
        };
        $events_before = $count();

        $this->put( $player_id, [ 'guardian_name' => 'Linda Willems' ] );

        $this->assertSame( $events_before, $count() );
    }

    public function test_the_full_form_payload_saves_as_before(): void {
        $player_id = $this->createFullPlayer();

        $res = $this->put( $player_id, [
            'first_name'          => 'Bas',
            'last_name'           => 'Willems',
            'date_of_birth'       => '2015-02-21',
            'jersey_number'       => '9',
            'preferred_positions' => '',
            'media_consent'       => '0',
            'team_id'             => (string) $this->team_id,
        ] );

        $this->assertSame( 200, $res->get_status() );
        $after = QueryHelpers::get_player( $player_id );
        $this->assertSame( 9, (int) $after->jersey_number );
        $this->assertSame( [], json_decode( (string) $after->preferred_positions, true ) );
        $this->assertSame( 0, (int) $after->media_consent );
    }

    private function createFullPlayer(): int {
        global $wpdb;
        $account = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $admin   = get_current_user_id();

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'             => (int) CurrentClub::id(),
            'first_name'          => 'Bas',
            'last_name'           => 'Willems',
            'date_of_birth'       => '2015-02-21',
            'nationality'         => 'NL',
            'height_cm'           => 143,
            'weight_kg'           => 32,
            'preferred_foot'      => 'Right',
            'preferred_positions' => '["CF"]',
            'jersey_number'       => 23,
            'team_id'             => $this->team_id,
            'date_joined'         => '2024-01-03',
            'media_consent'       => 1,
            'media_consent_at'    => '2026-01-10 12:00:00',
            'media_consent_by'    => $admin,
            'guardian_name'       => 'Linda',
            'wp_user_id'          => $account,
            'status'              => 'active',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id );
        return $id;
    }

    /** @param array<string,mixed> $body */
    private function put( int $player_id, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PUT', '/talenttrack/v1/players/' . $player_id );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( $body ) );
        return rest_do_request( $req );
    }
}
