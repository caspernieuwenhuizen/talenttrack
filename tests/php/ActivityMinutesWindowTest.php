<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Reports\MinutesGridQuery;
use TT\Modules\Analytics\Reports\ReportFilters;

/**
 * #3748 — the minutes window is stated, and one match's minutes are readable.
 *
 * `GET /activities/minutes-grid` applied the season default window whenever
 * the caller passed nothing (or passed a malformed date) and said nothing
 * about it, so a match outside that window was simply absent from
 * `activities` and `cells` with no way to tell a missing column from a
 * missing register. There was also no way to ask for one activity at all:
 * `activity_id` was accepted by the request and dropped by the route.
 *
 * Two halves, tested as two things: the query now carries the window it used,
 * and a new per-activity route answers "the minutes for this match" without
 * the caller having to know its date and guess a window around it.
 */
final class ActivityMinutesWindowTest extends WP_UnitTestCase {

    private const TEAM_ID = 4771;

    /** @var string */
    private $p;

    /** @var int */
    private $match_id = 0;

    /** @var list<int> */
    private $player_ids = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();
        // The TT roles install on activation, which the wp-env bootstrap does
        // not fire; without them `tt_coach` holds no `tt_view_activities` and
        // the scope refusal below would pass on the permission callback
        // instead of on the team check it is there to prove.
        ( new RolesService() )->installRoles();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => 1, 'id' => self::TEAM_ID, 'name' => 'U11' ] );

        // Dated in the past so the season default window contains it — the
        // "match outside the window" case is covered by its own test below.
        $this->match_id = $this->seedMatch( '2026-03-14' );
        foreach ( [ 7, 9, 11 ] as $shirt ) {
            $player_id = $this->seedPlayer( $shirt );
            $this->player_ids[] = $player_id;
            $this->seedMinutes( $this->match_id, $player_id, 60 );
        }
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the window the grid actually used ──────────────────────────────

    public function test_the_query_carries_the_window_it_was_given(): void {
        $matrix = ( new MinutesGridQuery() )->matrix( self::TEAM_ID, '2026-03-01', '2026-03-31' );

        $this->assertSame( [ 'from' => '2026-03-01', 'to' => '2026-03-31' ], $matrix['window'] );
    }

    /**
     * The two early returns are where this is easiest to forget, and they are
     * exactly the responses that need the window most: an empty result is the
     * one a caller cannot interpret without knowing what was searched.
     */
    public function test_an_empty_result_still_states_its_window(): void {
        $nothing = ( new MinutesGridQuery() )->matrix( self::TEAM_ID, '2020-01-01', '2020-01-31' );
        $this->assertSame( [], $nothing['activities'] );
        $this->assertSame( [ 'from' => '2020-01-01', 'to' => '2020-01-31' ], $nothing['window'] );

        $no_team = ( new MinutesGridQuery() )->matrix( 0, '2020-01-01', '2020-01-31' );
        $this->assertSame( [ 'from' => '2020-01-01', 'to' => '2020-01-31' ], $no_team['window'] );
    }

    public function test_the_route_echoes_a_supplied_window(): void {
        $data = $this->get( '/talenttrack/v1/activities/minutes-grid?team_id=' . self::TEAM_ID . '&from=2026-03-01&to=2026-03-31' );

        $this->assertSame( [ 'from' => '2026-03-01', 'to' => '2026-03-31' ], $data['window'] );
    }

    public function test_the_route_states_the_default_window_it_chose(): void {
        $defaults = ReportFilters::seasonDefaultWindow();

        $data = $this->get( '/talenttrack/v1/activities/minutes-grid?team_id=' . self::TEAM_ID );

        $this->assertSame( $defaults['from'], $data['window']['from'] );
        $this->assertSame( $defaults['to'], $data['window']['to'] );
    }

    /** A date that fails the format check falls back, and says which dates won. */
    public function test_a_malformed_date_reports_the_window_that_replaced_it(): void {
        $defaults = ReportFilters::seasonDefaultWindow();

        $data = $this->get( '/talenttrack/v1/activities/minutes-grid?team_id=' . self::TEAM_ID . '&from=last-tuesday' );

        $this->assertSame( $defaults['from'], $data['window']['from'] );
    }

    // ── one match's minutes ────────────────────────────────────────────

    public function test_the_per_activity_route_returns_the_squads_minutes(): void {
        $data = $this->get( '/talenttrack/v1/activities/' . $this->match_id . '/minutes' );

        $this->assertSame( $this->match_id, $data['activity']['activity_id'] );
        $this->assertCount( 3, $data['players'] );
        $this->assertSame( 3, $data['summary']['squad_players'] );
        $this->assertSame( 180, $data['summary']['total_minutes'] );
        foreach ( $data['players'] as $player ) {
            $this->assertSame( 60, $player['minutes'] );
            $this->assertTrue( $player['squad'] );
        }
    }

    /**
     * The whole reason it is derived from `matrix()` rather than queried
     * separately: a coach checking one match must not be told a different
     * number from the one the grid shows for it.
     */
    public function test_it_agrees_with_the_grid_for_the_same_activity(): void {
        $grid = ( new MinutesGridQuery() )->matrix( self::TEAM_ID, '2026-03-01', '2026-03-31' );
        $one  = ( new MinutesGridQuery() )->forActivity( $this->match_id );

        $this->assertNotNull( $one );
        foreach ( $one['players'] as $player ) {
            $this->assertSame(
                $grid['cells'][ $player['player_id'] ][ $this->match_id ]['minutes'],
                $player['minutes'],
                'the per-activity read disagrees with the grid for player ' . $player['player_id']
            );
        }
    }

    /**
     * The case the issue was reported from: a match outside the silent
     * default window. The grid answers with the window that excluded it; the
     * per-activity route answers with the minutes regardless, because it
     * needs no window at all.
     */
    public function test_a_match_outside_the_default_window_is_still_readable_by_id(): void {
        $future = $this->seedMatch( gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) );
        $this->seedMinutes( $future, $this->player_ids[0], 45 );

        $grid = $this->get( '/talenttrack/v1/activities/minutes-grid?team_id=' . self::TEAM_ID );
        $this->assertNotContains(
            $future,
            array_column( $grid['activities'], 'activity_id' ),
            'the default window ends today, which is exactly why it has to be stated'
        );
        $this->assertNotSame( '', (string) $grid['window']['to'] );

        $one = $this->get( '/talenttrack/v1/activities/' . $future . '/minutes' );
        $this->assertSame( 45, $one['summary']['total_minutes'] );
    }

    public function test_an_unknown_activity_is_a_404(): void {
        $this->assertSame( 404, $this->request( '/talenttrack/v1/activities/987654/minutes' )->get_status() );
    }

    public function test_a_training_carries_no_minutes_and_says_so(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => 1,
            'team_id'           => self::TEAM_ID,
            'title'             => 'Dinsdagtraining',
            'session_date'      => '2026-03-17',
            'activity_type_key' => 'training',
            'plan_state'        => 'completed',
        ] );
        $training = (int) $wpdb->insert_id;

        $response = $this->request( '/talenttrack/v1/activities/' . $training . '/minutes' );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'not_a_match_activity', $response->get_data()['errors'][0]['code'] );
    }

    public function test_an_activity_outside_the_callers_teams_is_refused(): void {
        // A coach with no team grants: staff enough to ask the question, in
        // scope for nothing, which is the shape the grid's own guard expects.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_coach' ] ) );

        $this->assertSame( 403, $this->request( '/talenttrack/v1/activities/' . $this->match_id . '/minutes' )->get_status() );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function get( string $route ): array {
        $response = $this->request( $route );
        $this->assertSame( 200, $response->get_status(), 'GET ' . $route );

        return (array) $response->get_data()['data'];
    }

    private function request( string $route ): \WP_REST_Response {
        $path  = $route;
        $query = [];
        if ( strpos( $route, '?' ) !== false ) {
            [ $path, $qs ] = explode( '?', $route, 2 );
            parse_str( $qs, $query );
        }
        $req = new WP_REST_Request( 'GET', $path );
        foreach ( $query as $key => $value ) {
            $req->set_param( (string) $key, $value );
        }
        return rest_do_request( $req );
    }

    private function seedMatch( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => 1,
            'team_id'             => self::TEAM_ID,
            'title'               => 'U11 vs Ajax ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'game',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
            'opponent'            => 'Ajax',
            'home_away'           => 'home',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seedPlayer( int $shirt ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => 1,
            'team_id'       => self::TEAM_ID,
            'first_name'    => 'Speler',
            'last_name'     => 'Nummer ' . $shirt,
            'jersey_number' => $shirt,
            'status'        => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seedMinutes( int $activity_id, int $player_id, int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => 1,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'status'         => 'present',
            'minutes_played' => $minutes,
        ] );
    }
}
