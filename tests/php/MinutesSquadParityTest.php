<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Shared\Frontend\FrontendStandardReportsView;

/**
 * #5019 — one minutes squad on every report.
 *
 * The Minutes share (and its REST route), the monthly report's minutes rows,
 * the Minutes distribution and `MinutesQuery::squadForTeam()` hold the same
 * players, the same minutes per player and the same available minutes: the
 * attendees of the team's played matches, wherever they play now, plus the
 * current roster; archived players and guests on none of them.
 */
final class MinutesSquadParityTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $club  = 1;
    private int $team  = 0;
    private int $other = 0;

    /** @var array<string,int> name => player id */
    private array $ids = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb;
        $wpdb->hide_errors();
        $this->club  = (int) CurrentClub::id();
        $this->team  = $this->team( 'Parity U14' );
        $this->other = $this->team( 'Parity U15' );

        $match      = $this->match( '2020-03-07', 'game' );
        $tournament = $this->match( '2020-03-21', 'tournament' );

        $this->player( 'Ada', $this->team );                       // roster, with minutes
        $this->player( 'Bo', $this->team );                        // roster, on the register at 0
        $this->player( 'Cor', $this->team );                       // roster, on no register
        $this->player( 'Dirk', $this->team );                      // roster, absent
        $this->player( 'Eef', $this->other );                      // attendee who moved team since
        $this->player( 'Fien', $this->team, [ 'status' => 'released' ] ); // released attendee
        $this->player( 'Gijs', $this->team, [ 'archived_at' => '2020-06-01 00:00:00' ] ); // archived attendee
        $this->player( 'Hans', $this->other );                     // only ever a guest

        $this->attend( $match, 'Ada', 60 );
        $this->attend( $match, 'Bo', null );
        $this->attend( $match, 'Dirk', null, 'Absent' );
        $this->attend( $match, 'Eef', 30 );
        $this->attend( $match, 'Fien', 20 );
        $this->attend( $match, 'Gijs', 10 );
        $this->attend( $match, 'Hans', 15, 'Present', 1 );
        $this->attend( $tournament, 'Ada', 40 );
        $this->attend( $tournament, 'Eef', 40 );

        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_GET = [];
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_every_surface_reads_one_squad(): void {
        $squad   = ( new MinutesQuery() )->squadForTeam( $this->team, self::FROM, self::TO );
        $share   = ( new MinutesShareQuery() )->forTeam( $this->team, self::FROM, self::TO );
        $monthly = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'minutes' ], get_current_user_id() )['data']['minutes'];

        $expected = [ 'Ada' => 100, 'Bo' => 0, 'Cor' => 0, 'Dirk' => 0, 'Eef' => 70, 'Fien' => 20 ];
        $want     = [];
        foreach ( $expected as $name => $minutes ) $want[ $this->ids[ $name ] ] = $minutes;
        ksort( $want );

        $this->assertSame( $want, $this->byPlayer( $squad, 'total_minutes' ), 'squadForTeam()' );
        $this->assertSame( $want, $this->byPlayer( $share['players'], 'minutes' ), 'the Minutes share' );
        $this->assertSame( $want, $this->byPlayer( $monthly['rows'], 'total_minutes' ), 'the monthly minutes rows' );

        // One available-minutes figure on every surface.
        $available = array_unique( array_column( $squad, 'available_minutes' ) );
        $this->assertCount( 1, $available );
        $this->assertGreaterThan( 0, $available[0] );
        $this->assertSame( $available[0], $share['available_minutes'] );
        $this->assertSame( [ $available[0] ], array_values( array_unique( array_column( $monthly['rows'], 'available_minutes' ) ) ) );

        // The Minutes distribution lists the same players, and no one else.
        $html = $this->renderDistribution();
        foreach ( array_keys( $expected ) as $name ) $this->assertStringContainsString( $name . ' Squad', $html, $name );
        $this->assertStringNotContainsString( 'Gijs Squad', $html, 'archived' );
        $this->assertStringNotContainsString( 'Hans Squad', $html, 'guest' );
        $this->assertSame( count( $expected ), substr_count( $html, 'tt-rep-bar-details' ) );
    }

    public function test_one_median_on_both_reports(): void {
        $share   = ( new MinutesShareQuery() )->forTeam( $this->team, self::FROM, self::TO );
        $monthly = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'minutes' ], get_current_user_id() )['data']['minutes'];

        $this->assertNotNull( $monthly['median_share_pct'] );
        $this->assertSame( MinutesQuery::medianShare( $share['players'] ), $monthly['median_share_pct'] );
    }

    public function test_a_team_whose_players_moved_on_keeps_its_squad(): void {
        global $wpdb;
        // Everyone on the roster moves to the other team.
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}tt_players SET team_id = %d WHERE team_id = %d", $this->other, $this->team ) );

        $share   = ( new MinutesShareQuery() )->forTeam( $this->team, self::FROM, self::TO );
        $monthly = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'minutes' ], get_current_user_id() )['data']['minutes'];

        // Cor was never on a register: with the roster gone, so is he.
        $want = [];
        foreach ( [ 'Ada' => 100, 'Bo' => 0, 'Dirk' => 0, 'Eef' => 70, 'Fien' => 20 ] as $name => $minutes ) $want[ $this->ids[ $name ] ] = $minutes;
        ksort( $want );

        $this->assertSame( $want, $this->byPlayer( $share['players'], 'minutes' ) );
        $this->assertSame( $want, $this->byPlayer( $monthly['rows'], 'total_minutes' ) );
        $this->assertSame( MinutesQuery::medianShare( $share['players'] ), $monthly['median_share_pct'] );
    }

    public function test_the_rest_route_and_the_rendered_report_agree(): void {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/teams/' . $this->team . '/minutes-share' );
        $request->set_param( 'from', self::FROM );
        $request->set_param( 'to', self::TO );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        $body = (array) $response->get_data();
        $data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;

        $query = ( new MinutesShareQuery() )->forTeam( $this->team, self::FROM, self::TO );
        $this->assertSame( $this->byPlayer( $query['players'], 'share_pct' ), $this->byPlayer( $data['players'], 'share_pct' ) );
        $this->assertSame( $this->byPlayer( $query['players'], 'minutes' ), $this->byPlayer( $data['players'], 'minutes' ) );

        // The rendered report names every player in the route's answer.
        $html = $this->renderShare();
        foreach ( $data['players'] as $row ) $this->assertStringContainsString( (string) $row['name'], $html );
    }

    /**
     * @param iterable<array<string,mixed>> $rows
     * @return array<int,mixed> player id => the field, sorted by id
     */
    private function byPlayer( iterable $rows, string $field ): array {
        $out = [];
        foreach ( $rows as $r ) $out[ (int) $r['player_id'] ] = $r[ $field ];
        ksort( $out );
        return $out;
    }

    private function renderDistribution(): string {
        return $this->render( 'renderTeamMinutesDistribution', 'team-minutes-distribution' );
    }

    private function renderShare(): string {
        return $this->render( 'renderMinutesShare', 'minutes-share' );
    }

    private function render( string $method, string $slug ): string {
        $_GET = [ 'tt_view' => 'standard-report', 'slug' => $slug, 'team_id' => (string) $this->team, 'from' => self::FROM, 'to' => self::TO ];
        $m    = new \ReflectionMethod( FrontendStandardReportsView::class, $method );
        $m->setAccessible( true );
        ob_start();
        $m->invoke( null );
        return (string) ob_get_clean();
    }

    private function team( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name, 'age_group' => 'U14' ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $extra */
    private function player( string $first, int $team, array $extra = [] ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", $extra + [
            'club_id' => $this->club, 'team_id' => $team, 'first_name' => $first, 'last_name' => 'Squad', 'status' => 'active',
        ] );
        $this->ids[ $first ] = (int) $wpdb->insert_id;
    }

    private function match( string $date, string $type ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => $this->club, 'team_id' => $this->team, 'title' => 'Parity ' . $date, 'session_date' => $date,
            'activity_type_key' => $type, 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attend( int $activity, string $name, ?int $minutes, string $status = 'Present', int $guest = 0 ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => $this->club, 'activity_id' => $activity, 'player_id' => $this->ids[ $name ], 'is_guest' => $guest,
            'status' => $status, 'record_type' => 'actual', 'minutes_played' => $minutes,
        ] );
    }
}
