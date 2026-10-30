<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4260 — planned attendance carries the match-day squad the coach picked in
 * match prep, as `match_squad` (`start` / `bench` / `out`) beside the plan.
 *
 * The route used to answer from the creation plan alone, so a player the coach
 * had left out of the squad still came back `expected`: staff arranging kit and
 * transport worked from a different squad than the coach's. The field carries
 * the squad position only, never the prep's status or reason.
 */
final class PlannedAttendanceMatchSquadTest extends WP_UnitTestCase {

    private int $club     = 0;
    private int $team     = 0;
    private int $activity = 0;

    /** @var array<string, int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO9-1', 'football_form' => '6v6' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Hedel - Ammerzoden',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
            'activity_type_key'   => 'match',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        ( new RolesService() )->ensureCapabilities();

        foreach ( [ 'starter', 'sub', 'absent' ] as $key ) {
            $this->plannedPlayer( $key );
        }

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_without_a_prep_match_squad_is_null_and_the_plan_is_unchanged(): void {
        foreach ( $this->roster() as $row ) {
            $this->assertArrayHasKey( 'match_squad', $row );
            $this->assertNull( $row['match_squad'] );
            $this->assertSame( 'expected', $row['plan_status'] );
        }
    }

    public function test_the_prep_squad_reaches_planned_attendance(): void {
        $this->savePrep( [
            'availability' => [
                $this->players['starter'] => [ 'status' => 'Present', 'reason' => '' ],
                $this->players['sub']     => [ 'status' => 'Present', 'reason' => '' ],
                $this->players['absent']  => [ 'status' => 'Injured', 'reason' => 'Ankle, physio Thursday' ],
            ],
            'lineup' => [ '1' => [ '1' => $this->players['starter'] ] ],
        ] );

        $by_player = [];
        foreach ( $this->roster() as $row ) {
            $by_player[ (int) $row['player_id'] ] = $row;
        }

        $this->assertSame( 'start', $by_player[ $this->players['starter'] ]['match_squad'] );
        $this->assertSame( 'bench', $by_player[ $this->players['sub'] ]['match_squad'] );
        $this->assertSame( 'out', $by_player[ $this->players['absent'] ]['match_squad'] );

        // Beside the plan, never instead of it.
        foreach ( $by_player as $row ) {
            $this->assertSame( 'expected', $row['plan_status'] );
        }
    }

    public function test_the_response_carries_no_prep_status_or_reason(): void {
        $this->savePrep( [
            'availability' => [
                $this->players['starter'] => [ 'status' => 'Present', 'reason' => '' ],
                $this->players['sub']     => [ 'status' => 'Present', 'reason' => '' ],
                $this->players['absent']  => [ 'status' => 'Injured', 'reason' => 'Ankle, physio Thursday' ],
            ],
        ] );

        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $this->activity . '/planned-attendance' ) );
        $body     = (string) wp_json_encode( $response->get_data() );

        $this->assertStringNotContainsString( 'Injured', $body );
        $this->assertStringNotContainsString( 'Ankle', $body );
    }

    /** @param array<string, mixed> $payload */
    private function savePrep( array $payload ): void {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/match-prep/' . $this->activity );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $payload ) );
        $this->assertSame( 200, rest_do_request( $request )->get_status() );
    }

    private function plannedPlayer( string $key ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Speler',
            'last_name'  => $key,
            'status'     => 'active',
        ] );
        $this->players[ $key ] = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $this->activity,
            'player_id'   => $this->players[ $key ],
            'is_guest'    => 0,
            'status'      => 'Present',
            'notes'       => '',
            'record_type' => 'expected',
        ] );
    }

    /** @return list<array<string, mixed>> */
    private function roster(): array {
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $this->activity . '/planned-attendance' ) );
        $this->assertSame( 200, $response->get_status() );
        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return array_values( array_map(
            static fn( $row ): array => (array) $row,
            (array) ( $data['data']['roster'] ?? [] )
        ) );
    }
}
