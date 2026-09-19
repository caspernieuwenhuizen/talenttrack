<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #3570 — `PUT activities/{id}` writes only what it is sent.
 *
 * The update rebuilt the whole row through `extract()`, which defaults
 * every missing key. A coach sending only the two match fields they meant
 * to change got a 200 and an activity with no title, no team and no date,
 * retyped as a training — which also nulled the match length it had just
 * been sent. The update now takes the stored value for every key the
 * request leaves out, and still derives the type-dependent columns from
 * the effective type.
 */
final class ActivityPartialUpdateTest extends WP_UnitTestCase {

    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb;
        $wpdb->hide_errors();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Hedel O11-1' ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_partial_put_changes_only_the_field_it_sends(): void {
        $id     = $this->createGame();
        $before = $this->row( $id );

        $this->assertSame( 200, $this->put( $id, [ 'home_away' => 'away' ] )->get_status() );

        $after = $this->row( $id );
        $this->assertSame( 'away', $after['home_away'] );
        foreach ( [
            'title', 'session_date', 'start_time', 'end_time', 'team_id', 'location',
            'activity_type_key', 'activity_status_key', 'match_length_minutes', 'opponent',
        ] as $column ) {
            $this->assertEquals( $before[ $column ], $after[ $column ], "{$column} changed although the request did not send it" );
        }
        $this->assertSame( $after['start_time'], $after['kickoff_time'], 'kickoff still mirrors the start time' );
    }

    public function test_changing_the_type_still_clears_the_match_only_columns(): void {
        $id = $this->createGame();

        $this->assertSame( 200, $this->put( $id, [ 'activity_type_key' => 'training' ] )->get_status() );

        $after = $this->row( $id );
        $this->assertSame( 'training', $after['activity_type_key'] );
        $this->assertNull( $after['match_length_minutes'] );
        $this->assertNull( $after['opponent'] );
        $this->assertNull( $after['home_away'] );
        $this->assertNull( $after['kickoff_time'] );
        $this->assertSame( 'Wedstrijd 105.2', $after['title'] );
        $this->assertSame( (string) $this->team_id, (string) $after['team_id'] );
        $this->assertSame( '2026-09-19', $after['session_date'] );
    }

    public function test_an_explicit_empty_still_clears(): void {
        $id = $this->createGame();

        $this->assertSame( 200, $this->put( $id, [ 'notes' => '' ] )->get_status() );

        $this->assertSame( '', (string) $this->row( $id )['notes'] );
        $this->assertSame( 'Wedstrijd 105.2', $this->row( $id )['title'] );
    }

    public function test_an_unknown_activity_is_a_404(): void {
        $this->assertSame( 404, $this->put( 987654, [ 'home_away' => 'home' ] )->get_status() );
    }

    private function createGame(): int {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/activities' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( [
            'title'                => 'Wedstrijd 105.2',
            'session_date'         => '2026-09-19',
            'start_time'           => '10:00',
            'end_time'             => '11:15',
            'team_id'              => $this->team_id,
            'location'             => 'Thuisveld',
            'notes'                => 'Verzamelen bij de kantine.',
            'activity_type_key'    => 'game',
            'match_length_minutes' => 50,
            'opponent'             => 'VV Rijnstreek',
            'home_away'            => 'home',
        ] ) );
        $this->assertSame( 200, rest_do_request( $req )->get_status() );

        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_activities WHERE title = %s ORDER BY id DESC LIMIT 1",
            'Wedstrijd 105.2'
        ) );
    }

    /** @return array<string,mixed> */
    private function row( int $id ): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    /** @param array<string,mixed> $body */
    private function put( int $id, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $id );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( $body ) );
        return rest_do_request( $req );
    }
}
