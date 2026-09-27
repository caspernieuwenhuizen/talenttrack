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

    /**
     * #4100 — an injury that started after the match does not reach back to
     * it. The player injured today was fit for last week's match.
     */
    public function test_an_injury_only_counts_from_the_day_it_started(): void {
        $this->player( 'injured_later' );
        $this->injuryDated( 'injured_later', '2026-09-20', null, null );
        $pid = $this->players['injured_later'];

        $this->assertFalse( PlayerAvailability::isUnavailable( $pid, '2026-09-13' ), 'fit for the match before the injury' );
        $this->assertTrue( PlayerAvailability::isUnavailable( $pid, '2026-09-27' ), 'out for the match after it' );
        $this->assertTrue( PlayerAvailability::isUnavailable( $pid, '2026-09-20' ), 'out on the day it happened' );
    }

    /**
     * #4100 — a player who has since returned still missed the match the
     * injury kept them out of; the return day itself is fit.
     */
    public function test_a_returned_injury_still_counts_for_the_days_it_covered(): void {
        $this->player( 'recovered' );
        $this->injuryDated( 'recovered', '2026-09-01', null, '2026-09-15' );
        $pid = $this->players['recovered'];

        $this->assertTrue( PlayerAvailability::isUnavailable( $pid, '2026-09-10' ), 'out during the injury' );
        $this->assertFalse( PlayerAvailability::isUnavailable( $pid, '2026-09-20' ), 'back after the return' );
        $this->assertFalse( PlayerAvailability::isUnavailable( $pid, '2026-09-15' ), 'back on the return day' );
    }

    /** #4005 unchanged: an un-closed record stops counting after its expected return. */
    public function test_a_stale_injury_does_not_count_after_its_expected_return(): void {
        $this->player( 'forgotten' );
        $this->injuryDated( 'forgotten', '2026-08-01', '2026-08-20', null );
        $pid = $this->players['forgotten'];

        $this->assertFalse( PlayerAvailability::isUnavailable( $pid, '2026-09-13' ), 'stale after the expected return' );
        $this->assertTrue( PlayerAvailability::isUnavailable( $pid, '2026-08-10' ), 'still out before it' );
    }

    /**
     * #4100 — the route asks about the activity's own date, the same day the
     * activity detail page asks about, so both give one answer per activity.
     */
    public function test_the_route_answers_for_the_activitys_date(): void {
        $this->player( 'injured_later' );
        $this->player( 'recovered' );
        $this->injuryDated( 'injured_later', '2026-09-20', null, null );
        $this->injuryDated( 'recovered', '2026-09-01', null, '2026-09-15' );

        $cases = [
            '2026-09-10' => [ 'injured_later' => PlayerAvailability::AVAILABLE,   'recovered' => PlayerAvailability::UNAVAILABLE ],
            '2026-09-13' => [ 'injured_later' => PlayerAvailability::AVAILABLE,   'recovered' => PlayerAvailability::AVAILABLE ],
            '2026-09-27' => [ 'injured_later' => PlayerAvailability::UNAVAILABLE, 'recovered' => PlayerAvailability::AVAILABLE ],
        ];

        foreach ( $cases as $date => $expected ) {
            $activity = $this->activityOn( $date, [ 'injured_later', 'recovered' ] );
            $rows     = $this->rows( $activity );
            $service  = PlayerAvailability::unavailableSet(
                [ $this->players['injured_later'], $this->players['recovered'] ],
                $date
            );

            foreach ( $expected as $key => $flag ) {
                $pid = $this->players[ $key ];
                $this->assertSame( $flag, $rows[ $pid ]['availability'], $key . ' on ' . $date );
                $this->assertSame(
                    PlayerAvailability::flagFor( $pid, $service ),
                    $rows[ $pid ]['availability'],
                    'the route and the service agree for ' . $key . ' on ' . $date
                );
            }
        }
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

    private function injuryDated( string $key, string $started_on, ?string $expected_return, ?string $actual_return ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", [
            'club_id'         => $this->club,
            'player_id'       => $this->players[ $key ],
            'started_on'      => $started_on,
            'expected_return' => $expected_return,
            'actual_return'   => $actual_return,
            'notes'           => 'ankle',
        ] );
    }

    /**
     * A match on `$date` with the named players in its plan, as Expected.
     *
     * @param list<string> $keys
     */
    private function activityOn( string $date, array $keys ): int {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Match ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'match',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        $activity = (int) $wpdb->insert_id;

        foreach ( $keys as $key ) {
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id'     => $this->club,
                'activity_id' => $activity,
                'player_id'   => $this->players[ $key ],
                'is_guest'    => 0,
                'status'      => 'Present',
                'record_type' => 'expected',
            ] );
        }

        return $activity;
    }

    /** @return array<string, mixed> */
    private function payload( int $activity = 0 ): array {
        $req = new WP_REST_Request(
            'GET',
            '/talenttrack/v1/activities/' . ( $activity > 0 ? $activity : $this->activity ) . '/planned-attendance'
        );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );

        return (array) ( $res->get_data()['data'] ?? [] );
    }

    /** @return array<int, array<string, mixed>> keyed by player id */
    private function rows( int $activity = 0 ): array {
        $out = [];
        foreach ( (array) ( $this->payload( $activity )['roster'] ?? [] ) as $row ) {
            $row = (array) $row;
            $out[ (int) $row['player_id'] ] = $row;
        }
        return $out;
    }
}
