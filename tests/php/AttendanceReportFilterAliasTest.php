<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3780 — the three attendance report routes honour the nested
 * `filter[...]` form, and refuse a filter they cannot resolve.
 *
 * They read only the plain `team_id`. WP REST drops a query parameter no
 * route declared, without a word, so `?filter[team_id]=52` — the form the
 * rest of the list API uses — was discarded and the caller got every team
 * in the academy back. Each row carries a `team_name`, so the answer looked
 * deliberate: a full, plausible at-risk list, just not the one asked for,
 * and a player from another age group could end up named in a team's
 * absence conversation.
 *
 * The invariant every assertion here is built on is that the two spellings
 * are the same question: whatever the plain parameter answers, the nested
 * one answers identically — including when the answer is a refusal.
 */
final class AttendanceReportFilterAliasTest extends WP_UnitTestCase {

    private const ROUTES = [
        '/talenttrack/v1/reports/attendance',
        '/talenttrack/v1/reports/attendance-at-risk',
        '/talenttrack/v1/reports/attendance-leaderboard',
    ];

    private const WINDOW = [ 'from' => '2020-01-01', 'to' => '2020-12-31' ];

    private string $p = '';
    private int $club = 0;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $player_a = 0;
    private int $player_b = 0;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        global $wpdb, $wp_rest_server;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->team_a   = $this->insertTeam( 'Filter U11' );
        $this->team_b   = $this->insertTeam( 'Filter U13' );
        $this->player_a = $this->insertPlayer( $this->team_a, 'Ayla', 'Alias' );
        $this->player_b = $this->insertPlayer( $this->team_b, 'Bram', 'Bijnaam' );

        // Four missed trainings each — past the default flag threshold of
        // three, so both players surface on the at-risk list and the filter
        // has something to cut away.
        foreach ( [ '2020-02-01', '2020-02-08', '2020-02-15', '2020-02-22' ] as $date ) {
            $this->insertAttendance( $this->insertActivity( $this->team_a, $date, 'training' ), $this->player_a, 'absent' );
            $this->insertAttendance( $this->insertActivity( $this->team_b, $date, 'training' ), $this->player_b, 'absent' );
        }
        // One game, team A only, so the activity-type filter has a distinct
        // population to answer with.
        $this->insertAttendance( $this->insertActivity( $this->team_a, '2020-03-07', 'game' ), $this->player_a, 'present' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_an_unfiltered_call_spans_the_academy(): void {
        foreach ( self::ROUTES as $route ) {
            $ids = $this->playerIds( $route, [] );
            $this->assertContains( $this->player_a, $ids, "{$route} left team A out of the unfiltered answer" );
            $this->assertContains( $this->player_b, $ids, "{$route} left team B out of the unfiltered answer" );
        }
    }

    public function test_a_nested_team_filter_answers_the_same_as_the_plain_one(): void {
        foreach ( self::ROUTES as $route ) {
            $plain  = $this->payload( $route, [ 'team_id' => $this->team_a ] );
            $nested = $this->payload( $route, [ 'filter' => [ 'team_id' => $this->team_a ] ] );

            $this->assertSame( $plain, $nested, "{$route} still answers filter[team_id] with the whole academy" );
            $this->assertSame(
                [ $this->player_a ],
                $this->playerIds( $route, [ 'filter' => [ 'team_id' => $this->team_a ] ] ),
                "{$route} answered for a team nobody asked about"
            );
        }
    }

    public function test_the_nested_value_wins_when_both_are_sent(): void {
        foreach ( self::ROUTES as $route ) {
            $both = $this->playerIds( $route, [
                'team_id' => $this->team_a,
                'filter'  => [ 'team_id' => $this->team_b ],
            ] );
            $this->assertSame( [ $this->player_b ], $both, "{$route} does not let the nested value win" );
        }
    }

    public function test_nested_dates_narrow_the_window_and_are_echoed(): void {
        // February only: the game in March drops out, so team A's row loses
        // its one present mark.
        foreach ( [ 'from' => 'to', 'date_from' => 'date_to' ] as $from_key => $to_key ) {
            $payload = $this->payload( '/talenttrack/v1/reports/attendance', [
                'filter' => [ $from_key => '2020-02-01', $to_key => '2020-02-29' ],
            ] );
            $this->assertSame( '2020-02-01', $payload['from'] ?? null, "filter[{$from_key}] was dropped" );
            $this->assertSame( '2020-02-29', $payload['to'] ?? null, "filter[{$to_key}] was dropped" );

            $rows = $this->rowFor( $payload, $this->player_a );
            $this->assertSame( 4, (int) ( $rows['total'] ?? 0 ), 'the March game is outside the narrowed window' );
        }
    }

    public function test_a_nested_activity_type_narrows_the_same_way_the_plain_one_does(): void {
        $plain  = $this->payload( '/talenttrack/v1/reports/attendance', [ 'activity_type_key' => 'game' ] );
        $nested = $this->payload( '/talenttrack/v1/reports/attendance', [ 'filter' => [ 'activity_type_key' => 'game' ] ] );

        $this->assertSame( $plain, $nested, 'filter[activity_type_key] was dropped' );
        $row = $this->rowFor( $nested, $this->player_a );
        $this->assertSame( 1, (int) ( $row['total'] ?? 0 ), 'only the game counts' );
        $this->assertSame( 1, (int) ( $row['present'] ?? 0 ) );
    }

    public function test_an_unresolvable_filter_is_refused_not_widened(): void {
        foreach ( self::ROUTES as $route ) {
            foreach ( [
                [ 'filter' => [ 'team_id' => 'U11' ] ],
                [ 'filter' => [ 'team_id' => 0 ] ],
                [ 'team_id' => 'U11' ],
            ] as $query ) {
                $response = $this->request( $route, $query );
                $this->assertSame( 400, $response->get_status(), "{$route} answered an unresolvable filter with data" );
            }
        }
    }

    /**
     * The refusal names which spelling was at fault, so a caller sending
     * both can tell which one to correct.
     */
    public function test_the_refusal_names_the_parameter(): void {
        $data = (array) $this->request( '/talenttrack/v1/reports/attendance', [ 'filter' => [ 'team_id' => 'U11' ] ] )->get_data();
        $error = (array) ( ( (array) ( $data['errors'] ?? [] ) )[0] ?? [] );

        $this->assertSame( 'bad_filter', $error['code'] ?? '' );
        $this->assertSame( 'filter[team_id]', ( (array) ( $error['details'] ?? [] ) )['parameter'] ?? '' );
    }

    /**
     * The point of the fix: a filter cannot be used to read past the
     * caller's own teams, and it cannot be dropped into an answer about
     * them either. The two spellings agree, whatever the answer is.
     */
    public function test_a_team_outside_the_callers_scope_is_refused_through_either_spelling(): void {
        $this->makeTeamScopedReader( $this->team_a );

        foreach ( self::ROUTES as $route ) {
            $plain  = $this->request( $route, [ 'team_id' => $this->team_b ] );
            $nested = $this->request( $route, [ 'filter' => [ 'team_id' => $this->team_b ] ] );

            $this->assertSame( $plain->get_status(), $nested->get_status(), "{$route} answers the two spellings differently" );
            $this->assertSame( 403, $nested->get_status(), "{$route} answered for a team the caller may not read" );
        }
    }

    /**
     * The other half, and the one that makes the refusal above mean what
     * its name says. A scope test that only ever asserts a refusal cannot
     * tell "narrowed correctly" from "refused everything" — which is
     * exactly what this fixture used to be doing (#3913).
     */
    public function test_the_same_caller_reads_their_own_team_through_either_spelling(): void {
        $this->makeTeamScopedReader( $this->team_a );

        foreach ( self::ROUTES as $route ) {
            foreach ( [
                'plain'  => [ 'team_id' => $this->team_a ],
                'nested' => [ 'filter' => [ 'team_id' => $this->team_a ] ],
            ] as $spelling => $query ) {
                $this->assertSame(
                    200,
                    $this->request( $route, $query )->get_status(),
                    "{$route} refused the {$spelling} spelling for the caller's own team"
                );
                $this->assertSame(
                    [ $this->player_a ],
                    $this->playerIds( $route, $query ),
                    "{$route} answered the {$spelling} spelling with somebody outside the caller's team"
                );
            }
        }
    }

    /**
     * And without a filter at all the answer is still the caller's own
     * team, not the academy — the scope narrows the query, it does not
     * merely validate the parameter.
     */
    public function test_an_unfiltered_call_by_a_scoped_caller_stays_inside_their_team(): void {
        $this->makeTeamScopedReader( $this->team_a );

        foreach ( self::ROUTES as $route ) {
            $this->assertSame(
                [ $this->player_a ],
                $this->playerIds( $route, [] ),
                "{$route} widened an unfiltered call past the caller's team"
            );
        }
    }

    public function test_every_attendance_route_declares_the_filter_argument(): void {
        foreach ( self::ROUTES as $route ) {
            $args = [];
            foreach ( rest_get_server()->get_routes()[ $route ] ?? [] as $handler ) {
                if ( ! empty( $handler['methods']['GET'] ) ) $args = (array) $handler['args'];
            }
            foreach ( [ 'team_id', 'from', 'to', 'activity_type_key', 'filter' ] as $name ) {
                $this->assertArrayHasKey( $name, $args, "{$name} is not declared on GET {$route}" );
                $this->assertNotSame(
                    '',
                    (string) ( ( (array) $args[ $name ] )['description'] ?? '' ),
                    "{$name} has no description on GET {$route}"
                );
            }
        }
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @param array<string,mixed> $query */
    private function request( string $route, array $query ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', $route );
        foreach ( $query + self::WINDOW as $key => $value ) {
            $req->set_param( $key, $value );
        }
        return rest_do_request( $req );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function payload( string $route, array $query ): array {
        $response = $this->request( $route, $query );
        $this->assertSame( 200, $response->get_status(), "{$route} refused a request it should answer" );
        $data = (array) $response->get_data();
        $this->assertIsArray( $data['data'] ?? null, "{$route} does not use the standard envelope" );
        return (array) $data['data'];
    }

    /**
     * The three routes name their rows differently; the leaderboard's
     * `bottom` is the same worst-first list the other two answer with.
     *
     * @param array<string,mixed> $query
     * @return list<int>
     */
    private function playerIds( string $route, array $query ): array {
        $payload = $this->payload( $route, $query );
        $rows    = (array) ( $payload['players'] ?? $payload['bottom'] ?? [] );
        $ids     = array_map( static fn( $row ): int => (int) ( ( (array) $row )['player_id'] ?? 0 ), $rows );
        sort( $ids );
        return array_values( $ids );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function rowFor( array $payload, int $player_id ): array {
        foreach ( (array) ( $payload['players'] ?? [] ) as $row ) {
            if ( (int) ( ( (array) $row )['player_id'] ?? 0 ) === $player_id ) return (array) $row;
        }
        $this->fail( 'the player is missing from the payload' );
    }

    /**
     * A reader the matrix genuinely grants team-scoped analytics to: the
     * `team_manager` persona, which the seed gives `analytics [r, team]`,
     * narrowed to one squad by the `tt_people` row plus the active team
     * grant `QueryHelpers::get_teams_for_coach()` reads.
     *
     * It has to be a real persona. A `subscriber` with `tt_view_analytics`
     * bolted on via `add_cap()` is refused by the `user_has_cap` bridge —
     * live in this suite, since `.wp-env.json` activates the plugin and
     * `Activator::activate()` seeds `tt_authorization_active = 1` — which
     * overwrites the directly-added cap with the matrix's answer for a
     * persona that does not resolve. The request never reaches the scope
     * check, so a 403 proves nothing about scoping.
     *
     * The WordPress role comes from migration 0030 rather than
     * `RolesService`, so it is created when absent: a user created against
     * a role that does not exist holds no role at all.
     */
    private function makeTeamScopedReader( int $team_id ): void {
        global $wpdb;

        if ( get_role( 'tt_team_manager' ) === null ) {
            add_role( 'tt_team_manager', 'Team Manager', [ 'read' => true ] );
        }
        $user_id = self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Scope',
            'last_name'  => 'Reader',
            'role_type'  => 'team_manager',
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        wp_set_current_user( $user_id );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first,
            'last_name'  => $last,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** #2521 — only an activity the coach marked completed counts. */
    private function insertActivity( int $team_id, string $date, string $type ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => ucfirst( $type ) . ' ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => $type,
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'is_guest'    => 0,
            'record_type' => 'actual',
        ] );
    }
}
