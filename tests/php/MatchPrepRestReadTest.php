<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3587 — match preparation over REST: the squad can be set, read back and
 * checked.
 *
 * `PUT match-prep/{id}` declared no fields, dropped every key it did not
 * know behind the same `{prep_id, activity_id}` an empty body got, and there
 * was no GET, so `{"squad":[…]}` looked saved and nothing was.
 */
final class MatchPrepRestReadTest extends WP_UnitTestCase {

    private int $mine = 0;
    private int $theirs = 0;
    /** @var list<int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'O13-1' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'O13-2' ] );
        $other_team = (int) $wpdb->insert_id;

        for ( $i = 1; $i <= 3; $i++ ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => 1, 'team_id' => $team, 'first_name' => 'Speler', 'last_name' => (string) $i, 'status' => 'active',
            ] );
            $this->players[] = (int) $wpdb->insert_id;
        }

        $this->mine   = $this->match( $team );
        $this->theirs = $this->match( $other_team );

        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Prep', 'last_name' => 'Coach',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => (int) $wpdb->insert_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
        wp_set_current_user( $coach );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_route_declares_its_fields_and_a_read(): void {
        $routes  = rest_get_server()->get_routes();
        $methods = [];
        $args    = [];
        foreach ( $routes['/talenttrack/v1/match-prep/(?P<activity_id>\d+)'] ?? [] as $handler ) {
            $methods += $handler['methods'];
            if ( ! empty( $handler['methods']['PUT'] ) ) $args = $handler['args'];
        }

        $this->assertArrayHasKey( 'GET', $methods );
        foreach ( [ 'availability', 'lineup', 'player_goals', 'formation_template_id', 'half_length_minutes', 'goals_general' ] as $field ) {
            $this->assertArrayHasKey( $field, $args, "{$field} is not declared" );
        }
    }

    public function test_the_squad_saved_is_the_squad_read_back(): void {
        [ $a, $b, $c ] = $this->players;

        [ $put, $status ] = $this->send( 'PUT', $this->mine, [
            'availability' => [
                $a => [ 'status' => 'Present', 'reason' => '' ],
                $b => [ 'status' => 'Present', 'reason' => '' ],
                $c => [ 'status' => 'Absent', 'reason' => 'ziek' ],
            ],
            'lineup' => [ '1' => [ '1' => $a ], '2' => [] ],
        ] );
        $this->assertSame( 200, $status );
        $this->assertSame( 'Absent', $put['data']['availability'][ $c ]['status'], 'the PUT answers with what it saved' );

        [ $got, $status ] = $this->send( 'GET', $this->mine );
        $this->assertSame( 200, $status );
        $this->assertSame( $put['data'], $got['data'] );

        $squad = array_keys( array_filter(
            (array) $got['data']['availability'],
            static fn( $row ): bool => ( (array) $row )['status'] === 'Present'
        ) );
        $this->assertEqualsCanonicalizing( [ $a, $b ], array_map( 'intval', $squad ) );
        $this->assertSame( $a, ( (array) $got['data']['lineup']['1'] )[1] );
        $this->assertSame( 'ziek', $got['data']['availability'][ $c ]['reason'] );
    }

    /**
     * The shape the prep screen sends (`buildFullPayload()` plus the drawer's
     * `availability`) passes the strict check.
     */
    public function test_the_prep_screen_payload_is_accepted(): void {
        [ $a, $b ] = $this->players;
        [ , $status ] = $this->send( 'PUT', $this->mine, [
            'formation_template_id' => null,
            'half_length_minutes'   => 30,
            'lineup'                => [ '1' => [ '1' => $a, '2' => $b ], '2' => new \stdClass() ],
            'player_goals'          => [ (string) $a => [ 'attention_text' => 'Scan first', 'is_specific_goal' => true, 'analyst_appointed' => false ] ],
            'goals_general'         => 'Druk zetten',
            'goals_attack'          => '',
            'goals_defend'          => '',
            'goals_attack_setpiece' => '',
            'goals_defend_setpiece' => '',
            'availability'          => [ (string) $a => [ 'status' => 'Present', 'reason' => '' ] ],
        ] );
        $this->assertSame( 200, $status );

        [ $got ] = $this->send( 'GET', $this->mine );
        $this->assertSame( 30, $got['data']['half_length_minutes'] );
        $this->assertSame( 'Druk zetten', $got['data']['goals_general'] );
        $this->assertTrue( $got['data']['player_goals'][ $a ]['is_specific_goal'] );
    }

    public function test_an_unknown_field_is_refused_and_nothing_is_created(): void {
        [ $data, $status ] = $this->send( 'PUT', $this->mine, [ 'squad' => $this->players, 'notes' => 'test' ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertEqualsCanonicalizing( [ 'squad', 'notes' ], (array) ( $data['errors'][0]['details']['fields'] ?? [] ) );
        $this->assertSame( 404, $this->send( 'GET', $this->mine )[1], 'a refused PUT starts no prep' );
    }

    /**
     * #3690 — the refusal says what to send instead, and the list is the
     * route's own declaration, so the two cannot disagree.
     */
    public function test_an_unknown_field_refusal_lists_the_accepted_fields(): void {
        [ $data, $status ] = $this->send( 'PUT', $this->mine, [ 'planned_minutes' => [ [ 'player_id' => $this->players[0], 'minutes' => 25 ] ] ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [ 'planned_minutes' ], (array) ( $data['errors'][0]['details']['fields'] ?? [] ) );

        $declared = [];
        foreach ( rest_get_server()->get_routes()['/talenttrack/v1/match-prep/(?P<activity_id>\d+)'] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['PUT'] ) ) $declared = array_keys( $handler['args'] );
        }
        $expected = array_merge(
            [ 'formation_template_id', 'half_length_minutes', 'lineup', 'availability', 'player_goals' ],
            \TT\Modules\MatchPrep\Services\MatchPrepState::GOAL_FIELDS
        );
        $allowed = (array) ( $data['errors'][0]['details']['allowed'] ?? [] );
        $this->assertEqualsCanonicalizing( $expected, $allowed );
        $this->assertEqualsCanonicalizing( $declared, $allowed, 'allowed is the route declaration' );
        $this->assertSame( 404, $this->send( 'GET', $this->mine )[1], 'a refused PUT starts no prep' );
    }

    public function test_a_coach_of_another_team_cannot_read_the_prep(): void {
        $this->assertSame( 403, $this->send( 'GET', $this->theirs )[1] );
    }

    private function match( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'           => 1,
            'team_id'           => $team_id,
            'title'             => 'Hedel - Den Bosch',
            'session_date'      => '2026-09-26',
            'activity_type_key' => 'match',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, int $activity_id, array $body = [] ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/match-prep/' . $activity_id );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
