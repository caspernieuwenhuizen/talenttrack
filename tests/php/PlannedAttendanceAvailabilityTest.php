<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\PlayerAvailability;

/**
 * #4005 — the planned roster says who cannot be planned for.
 *
 * Nothing on the planning path read the injury record, so a player with a
 * broken ankle still came back as `plan_status: expected` and the coach had
 * to remember it. The flag is derived per request and sits BESIDE
 * `plan_status`, which keeps its three values: a coach who deliberately
 * expects an injured player (light session, rehab minutes, travelling with
 * the squad) must not have that choice overwritten.
 *
 * The privacy assertion is the load-bearing one. These are minors, and the
 * assistant coach who asked for this cannot open an injury at all — so the
 * payload carries the state and nothing else.
 */
final class PlannedAttendanceAvailabilityTest extends WP_UnitTestCase {

    private int $club     = 0;
    private int $team     = 0;
    private int $activity = 0;

    /** @var array<string, int> label => player id */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO7-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Training',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        // One player per case the derivation has to get right.
        $this->player( 'fit' );
        $this->player( 'open' );          // open injury, no expected return
        $this->player( 'open_future' );   // open injury, expected return ahead
        $this->player( 'returned' );      // actual_return recorded
        $this->player( 'stale' );         // expected return already passed
        $this->player( 'archived' );      // archived injury record
        $this->player( 'expected_anyway' ); // open injury, coach expects them

        $this->injury( 'open', null, null );
        $this->injury( 'open_future', gmdate( 'Y-m-d', strtotime( '+20 days' ) ), null );
        $this->injury( 'returned', gmdate( 'Y-m-d', strtotime( '-3 days' ) ), gmdate( 'Y-m-d', strtotime( '-1 days' ) ) );
        $this->injury( 'stale', gmdate( 'Y-m-d', strtotime( '-40 days' ) ), null );
        $this->injury( 'archived', null, null, true );
        $this->injury( 'expected_anyway', null, null );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_an_open_injury_flags_the_player_unavailable(): void {
        $rows = $this->rows();

        $this->assertSame( PlayerAvailability::UNAVAILABLE, $rows[ $this->players['open'] ]['availability'] );
        $this->assertSame( PlayerAvailability::UNAVAILABLE, $rows[ $this->players['open_future'] ]['availability'] );
    }

    public function test_a_closed_stale_or_archived_injury_does_not_flag(): void {
        $rows = $this->rows();

        foreach ( [ 'fit', 'returned', 'stale', 'archived' ] as $case ) {
            $this->assertSame(
                PlayerAvailability::AVAILABLE,
                $rows[ $this->players[ $case ] ]['availability'],
                $case . ' must not read as unavailable'
            );
        }
    }

    /** The coach's plan is theirs; the flag reports, it does not decide. */
    public function test_the_flag_never_overwrites_the_plan_status(): void {
        $rows = $this->rows();
        $row  = $rows[ $this->players['expected_anyway'] ];

        $this->assertSame( 'expected', $row['plan_status'], 'the coach explicitly expects this player' );
        $this->assertSame( PlayerAvailability::UNAVAILABLE, $row['availability'] );
    }

    public function test_the_payload_carries_no_medical_detail(): void {
        foreach ( $this->rows() as $row ) {
            $this->assertSame(
                [ 'player_id', 'is_guest', 'name', 'plan_status', 'notes', 'availability' ],
                array_keys( $row ),
                'no injury type, body part, dates or injury id may appear here'
            );
            $this->assertContains(
                $row['availability'],
                [ PlayerAvailability::AVAILABLE, PlayerAvailability::UNAVAILABLE ]
            );
        }

        // And nothing anywhere in the response mentions the injury record.
        $json = (string) wp_json_encode( $this->payload() );
        foreach ( [ 'ankle', 'injury_id', 'expected_return', 'actual_return', 'body_part' ] as $leak ) {
            $this->assertStringNotContainsString( $leak, $json );
        }
    }

    /** The service answers on its own, so the view and the route agree. */
    public function test_the_service_is_the_single_derivation(): void {
        $this->assertTrue( PlayerAvailability::isUnavailable( $this->players['open'] ) );
        $this->assertFalse( PlayerAvailability::isUnavailable( $this->players['stale'] ) );
        $this->assertSame( [], PlayerAvailability::unavailableSet( [] ) );
    }

    // ---- fixtures --------------------------------------------------

    private function player( string $key ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Speler',
            'last_name'  => $key,
            'status'     => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;
        $this->players[ $key ] = $player_id;

        // Every player is in the plan, as Expected.
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $this->activity,
            'player_id'   => $player_id,
            'is_guest'    => 0,
            'status'      => 'Present',
            'record_type' => 'expected',
        ] );
    }

    private function injury( string $key, ?string $expected_return, ?string $actual_return, bool $archived = false ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", [
            'club_id'         => $this->club,
            'player_id'       => $this->players[ $key ],
            'started_on'      => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
            'expected_return' => $expected_return,
            'actual_return'   => $actual_return,
            'notes'           => 'ankle',
            'archived_at'     => $archived ? current_time( 'mysql' ) : null,
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

    /** @return array<int, array<string, mixed>> keyed by player id */
    private function rows(): array {
        $out = [];
        foreach ( (array) ( $this->payload()['roster'] ?? [] ) as $row ) {
            $row = (array) $row;
            $out[ (int) $row['player_id'] ] = $row;
        }
        return $out;
    }
}
