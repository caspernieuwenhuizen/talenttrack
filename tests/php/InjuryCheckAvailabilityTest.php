<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Journey\InjuryStatus;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Activities\Services\PlayerAvailability;
use TT\Modules\Journey\JourneyModule;

/**
 * #4246 / #4269 — an injury record that is overdue (expected return passed,
 * no return recorded) or incomplete (no expected return for the club's number
 * of days) leaves the player available in planning, with a separate neutral
 * `availability_check` marker. `availability` keeps its two values.
 */
final class InjuryCheckAvailabilityTest extends WP_UnitTestCase {

    private const MATCH_DATE = '2026-10-15';

    private int $club     = 0;
    private int $team     = 0;
    private int $activity = 0;

    /** @var array<string, int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        JourneyModule::ensureCapabilities();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO9-1', 'football_form' => '6v6' ] );
        $this->team     = (int) $wpdb->insert_id;
        $this->activity = $this->activityOn( self::MATCH_DATE );

        foreach ( [ 'fit', 'overdue', 'incomplete', 'fresh', 'closed' ] as $key ) {
            $this->player( $key, $this->activity );
        }
        $this->injury( 'overdue', '2026-09-01', '2026-10-01', null );
        $this->injury( 'incomplete', '2026-09-01', null, null );
        $this->injury( 'fresh', '2026-10-10', null, null );
        $this->injury( 'closed', '2026-09-01', null, '2026-09-20' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_planned_attendance_marks_overdue_and_incomplete_as_available_with_a_check(): void {
        $rows = $this->plannedRows( $this->activity );

        $expect = [
            'fit'        => [ PlayerAvailability::AVAILABLE, false ],
            'overdue'    => [ PlayerAvailability::AVAILABLE, true ],
            'incomplete' => [ PlayerAvailability::AVAILABLE, true ],
            'fresh'      => [ PlayerAvailability::UNAVAILABLE, false ],
            'closed'     => [ PlayerAvailability::AVAILABLE, false ],
        ];
        foreach ( $expect as $key => [ $availability, $check ] ) {
            $row = $rows[ $this->players[ $key ] ];
            $this->assertSame( $availability, $row['availability'], $key );
            $this->assertSame( $check, $row['availability_check'], $key );
        }
    }

    public function test_an_incomplete_record_keeps_the_player_out_until_the_club_threshold(): void {
        // Five days after the start: not yet incomplete, so still out.
        $early = $this->activityOn( '2026-09-06' );
        foreach ( [ 'incomplete' ] as $key ) {
            $this->plan( $key, $early );
        }
        $row = $this->plannedRows( $early )[ $this->players['incomplete'] ];
        $this->assertSame( PlayerAvailability::UNAVAILABLE, $row['availability'] );
        $this->assertFalse( $row['availability_check'] );

        // The club's number of days comes from tt_config.
        QueryHelpers::set_config( InjuryStatus::INCOMPLETE_AFTER_KEY, '3' );
        try {
            $this->assertSame( 3, InjuryStatus::incompleteAfterDays() );
            $row = $this->plannedRows( $early )[ $this->players['incomplete'] ];
            $this->assertSame( PlayerAvailability::AVAILABLE, $row['availability'], 'incomplete after 3 days at this club' );
            $this->assertTrue( $row['availability_check'] );
        } finally {
            // The config service caches per process; leave the default behind.
            QueryHelpers::set_config( InjuryStatus::INCOMPLETE_AFTER_KEY, (string) InjuryStatus::INCOMPLETE_AFTER_DEFAULT );
        }
    }

    public function test_match_prep_carries_the_same_marker(): void {
        $this->savePrep();
        $prep = $this->prep();

        $check       = array_map( 'intval', array_keys( (array) $prep['availability_check'] ) );
        $unavailable = array_map( 'intval', array_keys( (array) $prep['unavailable'] ) );
        sort( $check );
        $expected = [ $this->players['overdue'], $this->players['incomplete'] ];
        sort( $expected );

        $this->assertSame( $expected, $check );
        $this->assertSame( [ $this->players['fresh'] ], $unavailable );
        $this->assertSame( 'Present', $prep['availability'][ $this->players['overdue'] ]['status'], 'the coach\'s choice is untouched' );

        $json = (string) wp_json_encode( $prep );
        foreach ( [ 'ankle', 'expected_return', 'started_on' ] as $leak ) {
            $this->assertStringNotContainsString( $leak, $json );
        }
    }

    public function test_a_backdated_touchline_record_is_listed_incomplete_and_flagged_in_planning(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team,
            'first_name' => 'Speler', 'last_name' => 'touchline', 'status' => 'active',
        ] );
        $pid   = (int) $wpdb->insert_id;
        $today = AttendanceDateRule::today();

        $post = new WP_REST_Request( 'POST', "/talenttrack/v1/players/{$pid}/injuries" );
        $post->set_header( 'Content-Type', 'application/json' );
        $post->set_body( (string) wp_json_encode( [
            'started_on' => gmdate( 'Y-m-d', (int) strtotime( $today . ' -20 days' ) ),
            'notes'      => 'The physio will set a return date.',
        ] ) );
        $this->assertContains( rest_do_request( $post )->get_status(), [ 200, 201 ] );

        $res = rest_do_request( new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$pid}/injuries" ) );
        $this->assertSame( 200, $res->get_status() );
        $items = (array) ( $res->get_data()['data']['injuries'] ?? [] );
        $this->assertCount( 1, $items );
        $this->assertSame( InjuryStatus::INCOMPLETE, (string) ( (array) $items[0] )['status'] );

        $activity = $this->activityOn( gmdate( 'Y-m-d', (int) strtotime( $today . ' +1 day' ) ) );
        $this->plan( 'touchline', $activity, $pid );
        $row = $this->plannedRows( $activity )[ $pid ];
        $this->assertSame( PlayerAvailability::AVAILABLE, $row['availability'] );
        $this->assertTrue( $row['availability_check'] );
    }

    public function test_the_overview_and_the_player_tab_flag_an_incomplete_record(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team,
            'first_name' => 'Speler', 'last_name' => 'stale', 'status' => 'active',
        ] );
        $pid = (int) $wpdb->insert_id;
        $this->players['stale'] = $pid;
        $this->injury( 'stale', gmdate( 'Y-m-d', (int) strtotime( AttendanceDateRule::today() . ' -30 days' ) ), null, null );

        ob_start();
        \TT\Modules\Journey\Frontend\FrontendInjuriesView::render( get_current_user_id(), true );
        $overview = (string) ob_get_clean();
        $this->assertStringContainsString( 'Incomplete', $overview );

        $_GET['tab'] = 'injuries';
        ob_start();
        \TT\Shared\Frontend\FrontendPlayerDetailView::render( $pid, get_current_user_id(), true );
        $tab = (string) ob_get_clean();
        unset( $_GET['tab'] );
        $this->assertStringContainsString( 'Incomplete', $tab );
    }

    // ---- fixtures --------------------------------------------------

    private function activityOn( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Match ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'match',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function player( string $key, int $activity ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Speler',
            'last_name'  => $key,
            'status'     => 'active',
        ] );
        $this->players[ $key ] = (int) $wpdb->insert_id;
        $this->plan( $key, $activity );
    }

    private function plan( string $key, int $activity, int $pid = 0 ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity,
            'player_id'   => $pid > 0 ? $pid : $this->players[ $key ],
            'is_guest'    => 0,
            'status'      => 'Present',
            'record_type' => 'expected',
        ] );
    }

    private function injury( string $key, string $started, ?string $expected, ?string $actual ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", [
            'club_id'         => $this->club,
            'player_id'       => $this->players[ $key ],
            'started_on'      => $started,
            'expected_return' => $expected,
            'actual_return'   => $actual,
            'notes'           => 'ankle',
        ] );
    }

    /** @return array<int, array<string, mixed>> */
    private function plannedRows( int $activity ): array {
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $activity . '/planned-attendance' ) );
        $this->assertSame( 200, $res->get_status() );
        $out = [];
        foreach ( (array) ( $res->get_data()['data']['roster'] ?? [] ) as $row ) {
            $row = (array) $row;
            $out[ (int) $row['player_id'] ] = $row;
        }
        return $out;
    }

    private function savePrep(): void {
        $availability = [];
        foreach ( $this->players as $pid ) {
            $availability[ $pid ] = [ 'status' => 'Present', 'reason' => '' ];
        }
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/match-prep/' . $this->activity );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [ 'availability' => $availability ] ) );
        $this->assertSame( 200, rest_do_request( $request )->get_status() );
    }

    /** @return array<string, mixed> */
    private function prep(): array {
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/match-prep/' . $this->activity ) );
        $this->assertSame( 200, $response->get_status() );
        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return (array) ( $data['data'] ?? [] );
    }
}
