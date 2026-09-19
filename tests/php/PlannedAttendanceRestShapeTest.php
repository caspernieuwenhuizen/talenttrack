<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3652 — the planned roster must not present the plan's storage value as
 * an attendance status.
 *
 * The plan is stored on `tt_attendance` as `record_type = 'expected'` rows
 * whose `status` column encodes the plan key (`plannedStatusMap()`:
 * expected → Present, not_coming → Absent, maybe → Excused). The route
 * passed that column through as `status`, the field every other attendance
 * route uses for the recorded mark, so an activity nobody had registered
 * came back with every planned player "Present". Only `plan_status` says
 * what the plan means, and it is the only status the route now returns.
 */
final class PlannedAttendanceRestShapeTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;
    private int $activity = 0;

    /** @var array<string, int> plan key => player id */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO11-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Training',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+4 days' ) ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        // One planned row per plan key, so every branch of the mapping is
        // exercised — not just the "expected" case the bug was reported on.
        $this->plan( 'expected',   'Aaronson', 'Present' );
        $this->plan( 'not_coming', 'Berg',     'Absent'  );
        $this->plan( 'maybe',      'Cruijff',  'Excused' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_no_row_carries_a_status_field(): void {
        foreach ( $this->roster() as $row ) {
            $this->assertArrayNotHasKey(
                'status',
                $row,
                'A planned row must not present the stored encoding as an attendance status.'
            );
        }
    }

    public function test_plan_status_carries_the_plan_meaning(): void {
        $by_player = [];
        foreach ( $this->roster() as $row ) {
            $by_player[ (int) $row['player_id'] ] = (string) $row['plan_status'];
        }

        foreach ( $this->players as $key => $player_id ) {
            $this->assertSame( $key, $by_player[ $player_id ] ?? '' );
        }
    }

    /** The rest of the row shape is unchanged — this is a removal, not a rewrite. */
    public function test_the_other_row_keys_are_unchanged(): void {
        $roster = $this->roster();

        $this->assertCount( 3, $roster );
        foreach ( $roster as $row ) {
            $this->assertSame(
                [ 'player_id', 'is_guest', 'name', 'plan_status', 'notes' ],
                array_keys( $row )
            );
            $this->assertFalse( $row['is_guest'] );
            $this->assertNotSame( '', (string) $row['name'] );
        }

        $notes = array_column( $roster, 'notes', 'plan_status' );
        $this->assertSame( 'texted, injured', $notes['not_coming'] ?? '' );
    }

    public function test_the_envelope_keys_are_unchanged(): void {
        $data = $this->payload();

        $this->assertSame( $this->activity, (int) $data['activity_id'] );
        $this->assertSame( 3, (int) $data['count'] );
    }

    /** An activity with no plan answers with an empty roster, not an error. */
    public function test_an_activity_with_no_plan_answers_empty(): void {
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}tt_attendance", [ 'activity_id' => $this->activity ] );

        $data = $this->payload();

        $this->assertSame( 0, (int) $data['count'] );
        $this->assertSame( [], array_values( (array) $data['roster'] ) );
    }

    private function plan( string $plan_key, string $last_name, string $stored ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Speler',
            'last_name'  => $last_name,
            'status'     => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;
        $this->players[ $plan_key ] = $player_id;

        $wpdb->insert( "{$p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $this->activity,
            'player_id'   => $player_id,
            'is_guest'    => 0,
            'status'      => $stored,
            'notes'       => $plan_key === 'not_coming' ? 'texted, injured' : '',
            'record_type' => 'expected',
        ] );
    }

    /** @return array<string, mixed> */
    private function payload(): array {
        $req = new WP_REST_Request(
            'GET',
            '/talenttrack/v1/activities/' . $this->activity . '/planned-attendance'
        );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );

        return (array) ( $res->get_data()['data'] ?? [] );
    }

    /** @return list<array<string, mixed>> */
    private function roster(): array {
        return array_map(
            static fn( $row ): array => (array) $row,
            array_values( (array) ( $this->payload()['roster'] ?? [] ) )
        );
    }
}
