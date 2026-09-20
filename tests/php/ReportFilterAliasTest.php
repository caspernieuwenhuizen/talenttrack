<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\REST\ReportsRestController;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\PotentialOverviewQuery;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3790 — the minutes audit, the potential overview and the coach
 * evaluation quality report honour the nested `filter[...]` spelling, and
 * refuse a team filter they cannot resolve.
 *
 * #3780 fixed the three attendance readers; these three were left out of
 * scope and still read their filters only under the plain names, so a
 * caller sending `?filter[team_id]=52` — the form the rest of the list API
 * uses — got back every team they may read. The rows carry real team and
 * player names, so the answer looks deliberate rather than unfiltered: on a
 * minutes or potential report that is another age group's players in front
 * of a coach making selection decisions.
 *
 * The invariant every assertion is built on is the one #3780 states: the
 * two spellings are the same question, so whatever the plain parameter
 * answers the nested one answers identically — including when the answer is
 * a refusal, and including when it is a refusal on grounds of scope.
 *
 * The minutes audit is exercised through `rest_do_request()`, because its
 * fix also had to remove the route-level `required` flag that would
 * otherwise refuse a nested-only request before the callback ran. The other
 * two are exercised through their callbacks, the way `PotentialOverviewTest`
 * already does: their permission callbacks depend on persona matrix rows and
 * feature toggles that have nothing to do with this fix, and the routes'
 * declarations are asserted separately below.
 */
final class ReportFilterAliasTest extends WP_UnitTestCase {

    private const MINUTES_ROUTE = '/talenttrack/v1/reports/minutes-audit';
    private const WINDOW        = [ 'from' => '2020-01-01', 'to' => '2021-12-31' ];

    private string $p = '';
    private int $club = 0;
    private int $admin_id = 0;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $player_a = 0;
    private int $player_b = 0;
    private int $coach_a = 0;
    private int $coach_b = 0;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        global $wpdb, $wp_rest_server;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->team_a   = $this->insertTeam( 'Alias U15', 'U15' );
        $this->team_b   = $this->insertTeam( 'Alias U17', 'U17' );
        $this->player_a = $this->insertPlayer( $this->team_a, 'Ayla', 'Alias' );
        $this->player_b = $this->insertPlayer( $this->team_b, 'Bram', 'Bijnaam' );

        // One game each, so the two teams' matrices are visibly different
        // answers rather than two empty ones.
        $this->insertMinutes( $this->insertGame( $this->team_a, '2020-03-07' ), $this->player_a, 60 );
        $this->insertMinutes( $this->insertGame( $this->team_b, '2020-03-07' ), $this->player_b, 45 );

        // One evaluation each, a season apart, so both the team filter and
        // the date filter have something to cut away.
        $this->coach_a = (int) self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Coach Alpha' ] );
        $this->coach_b = (int) self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Coach Beta' ] );
        $this->insertEvaluation( $this->coach_a, $this->player_a, '2020-02-01' );
        $this->insertEvaluation( $this->coach_b, $this->player_b, '2021-02-01' );

        $this->admin_id = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin_id );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /* ---- route declarations -------------------------------------------- */

    /**
     * Both spellings are documented. Route discovery is how a non-WordPress
     * consumer learns what a report takes (CLAUDE.md §4); a filter that only
     * works if you already know it exists is not a contract.
     */
    public function test_the_three_routes_declare_both_spellings(): void {
        $expected = [
            '/talenttrack/v1/reports/minutes-audit'            => [ 'team_id', 'from', 'to', 'type', 'filter' ],
            '/talenttrack/v1/reports/potential-overview'       => [ 'team_id', 'age_group', 'bands', 'filter' ],
            '/talenttrack/v1/reports/coach-evaluation-quality' => [ 'team_id', 'date_from', 'date_to', 'filter' ],
        ];

        foreach ( $expected as $route => $names ) {
            $args = [];
            foreach ( rest_get_server()->get_routes()[ $route ] ?? [] as $handler ) {
                if ( ! empty( $handler['methods']['GET'] ) ) $args = (array) $handler['args'];
            }
            foreach ( $names as $name ) {
                $this->assertArrayHasKey( $name, $args, "{$name} is not declared on GET {$route}" );
                $this->assertNotSame(
                    '',
                    (string) ( ( (array) $args[ $name ] )['description'] ?? '' ),
                    "{$name} has no description on GET {$route}"
                );
            }
        }
    }

    /* ---- minutes audit -------------------------------------------------- */

    public function test_the_minutes_audit_answers_both_spellings_the_same(): void {
        $plain  = $this->minutesPayload( [ 'team_id' => $this->team_a ] );
        $nested = $this->minutesPayload( [ 'filter' => [ 'team_id' => $this->team_a ] ] );

        $this->assertSame( $plain, $nested, 'filter[team_id] is still dropped on the minutes audit' );
        $this->assertSame( [ $this->player_a ], $this->playerIds( $nested ), 'the audit covered a team nobody asked about' );
    }

    public function test_the_minutes_audit_lets_the_nested_team_win(): void {
        $both = $this->minutesPayload( [
            'team_id' => $this->team_a,
            'filter'  => [ 'team_id' => $this->team_b ],
        ] );
        $this->assertSame( [ $this->player_b ], $this->playerIds( $both ) );
    }

    public function test_the_minutes_audit_takes_its_window_under_either_spelling(): void {
        foreach ( [ 'from' => 'to', 'date_from' => 'date_to' ] as $from_key => $to_key ) {
            $payload = $this->minutesPayload( [
                'team_id' => $this->team_a,
                'filter'  => [ $from_key => '2019-01-01', $to_key => '2019-12-31' ],
            ] );
            $this->assertSame( [], $payload['games'] ?? null, "filter[{$from_key}] was dropped" );
        }
    }

    /**
     * A team nobody can resolve is refused rather than answered. Dropping it
     * is what widened the read; answering an empty matrix would claim the
     * team played nothing.
     */
    public function test_an_unresolvable_team_is_refused_on_the_minutes_audit(): void {
        foreach ( [
            [ 'filter' => [ 'team_id' => 'U15' ] ],
            [ 'filter' => [ 'team_id' => 0 ] ],
            [ 'filter' => [ 'team_id' => [ 1, 2 ] ] ],
            [ 'team_id' => 'U15' ],
            [],
        ] as $query ) {
            $response = $this->minutesRequest( $query );
            $this->assertSame( 400, $response->get_status(), 'an unresolvable team was answered with data' );
        }
    }

    public function test_the_minutes_audit_refusal_names_the_spelling_at_fault(): void {
        $this->assertSame(
            'filter[team_id]',
            $this->refusalParameter( $this->minutesRequest( [ 'filter' => [ 'team_id' => 'U15' ] ] ) )
        );
        $this->assertSame(
            'team_id',
            $this->refusalParameter( $this->minutesRequest( [ 'team_id' => 'U15' ] ) )
        );
    }

    /**
     * The point of the fix: the alias is not a way past the caller's own
     * teams. Both spellings answer a team outside scope the same way, and
     * neither hands back the caller's own team instead.
     */
    public function test_a_team_outside_scope_answers_the_same_through_either_spelling(): void {
        $this->makeTeamScopedReader( $this->team_a );

        $plain  = $this->minutesRequest( [ 'team_id' => $this->team_b ] );
        $nested = $this->minutesRequest( [ 'filter' => [ 'team_id' => $this->team_b ] ] );

        $this->assertSame( $plain->get_status(), $nested->get_status(), 'the two spellings are answered differently' );
        $this->assertSame( $plain->get_data(), $nested->get_data() );
        $this->assertSame(
            [],
            $this->playerIds( $this->payloadOf( $nested ) ),
            'a team the caller may not read returned players'
        );
    }

    /* ---- potential overview --------------------------------------------- */

    public function test_the_potential_overview_answers_both_spellings_the_same(): void {
        $plain  = $this->potentialPayload( [ 'team_id' => $this->team_a ] );
        $nested = $this->potentialPayload( [ 'filter' => [ 'team_id' => $this->team_a ] ] );

        $this->assertSame( $plain, $nested, 'filter[team_id] is still dropped on the potential overview' );
        $this->assertSame( [ $this->player_a ], array_column( (array) $nested['rows'], 'player_id' ) );
    }

    public function test_the_potential_overview_lets_the_nested_team_win(): void {
        $both = $this->potentialPayload( [
            'team_id' => $this->team_a,
            'filter'  => [ 'team_id' => $this->team_b ],
        ] );
        $this->assertSame( [ $this->player_b ], array_column( (array) $both['rows'], 'player_id' ) );
    }

    public function test_the_potential_overview_takes_an_age_group_under_either_spelling(): void {
        $plain  = $this->potentialPayload( [ 'scope' => PotentialOverviewQuery::SCOPE_AGE_GROUP, 'age_group' => 'U17' ] );
        $nested = $this->potentialPayload( [
            'scope'  => PotentialOverviewQuery::SCOPE_AGE_GROUP,
            'filter' => [ 'age_group' => 'U17' ],
        ] );

        $this->assertSame( $plain, $nested, 'filter[age_group] was dropped' );
        $this->assertSame( [ $this->player_b ], array_column( (array) $nested['rows'], 'player_id' ) );
    }

    public function test_an_unresolvable_team_is_refused_on_the_potential_overview(): void {
        foreach ( [
            [ 'filter' => [ 'team_id' => 'U15' ] ],
            [ 'team_id' => 'U15' ],
        ] as $query ) {
            $response = ReportsRestController::potentialOverview( $this->requestFor( '/talenttrack/v1/reports/potential-overview', $query ) );
            $this->assertSame( 400, $response->get_status() );
        }
        $this->assertSame(
            'filter[team_id]',
            $this->refusalParameter( ReportsRestController::potentialOverview(
                $this->requestFor( '/talenttrack/v1/reports/potential-overview', [ 'filter' => [ 'team_id' => 'U15' ] ] )
            ) )
        );
    }

    /* ---- coach evaluation quality --------------------------------------- */

    public function test_the_coach_quality_report_answers_both_spellings_the_same(): void {
        $plain  = $this->coachQualityPayload( [ 'team_id' => $this->team_a ] );
        $nested = $this->coachQualityPayload( [ 'filter' => [ 'team_id' => $this->team_a ] ] );

        $this->assertSame( $plain, $nested, 'filter[team_id] is still dropped on the coach quality report' );
        $this->assertSame( [ $this->coach_a ], array_column( (array) $nested['rows'], 'coach_id' ) );
    }

    public function test_the_coach_quality_report_lets_the_nested_team_win(): void {
        $both = $this->coachQualityPayload( [
            'team_id' => $this->team_a,
            'filter'  => [ 'team_id' => $this->team_b ],
        ] );
        $this->assertSame( [ $this->coach_b ], array_column( (array) $both['rows'], 'coach_id' ) );
    }

    public function test_the_coach_quality_report_takes_its_dates_under_either_spelling(): void {
        foreach ( [ 'date_from', 'from' ] as $key ) {
            $payload = $this->coachQualityPayload( [ 'filter' => [ $key => '2021-01-01' ] ] );
            $this->assertSame(
                [ $this->coach_b ],
                array_column( (array) $payload['rows'], 'coach_id' ),
                "filter[{$key}] was dropped"
            );
        }
    }

    public function test_an_unresolvable_team_is_refused_on_the_coach_quality_report(): void {
        foreach ( [
            [ 'filter' => [ 'team_id' => 'U15' ] ],
            [ 'team_id' => 'U15' ],
        ] as $query ) {
            $response = ReportsRestController::coachEvalQuality( $this->requestFor( '/talenttrack/v1/reports/coach-evaluation-quality', $query ) );
            $this->assertSame( 400, $response->get_status() );
        }
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @param array<string,mixed> $query */
    private function requestFor( string $route, array $query ): WP_REST_Request {
        $req = new WP_REST_Request( 'GET', $route );
        foreach ( $query as $key => $value ) {
            $req->set_param( $key, $value );
        }
        return $req;
    }

    /** @param array<string,mixed> $query */
    private function minutesRequest( array $query ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', self::MINUTES_ROUTE );
        foreach ( $query + self::WINDOW as $key => $value ) {
            $req->set_param( $key, $value );
        }
        return rest_do_request( $req );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function minutesPayload( array $query ): array {
        $response = $this->minutesRequest( $query );
        $this->assertSame( 200, $response->get_status(), 'the minutes audit refused a request it should answer' );
        return $this->payloadOf( $response );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function potentialPayload( array $query ): array {
        $response = ReportsRestController::potentialOverview( $this->requestFor(
            '/talenttrack/v1/reports/potential-overview',
            $query + [ 'scope' => PotentialOverviewQuery::SCOPE_TEAM ]
        ) );
        $this->assertSame( 200, $response->get_status(), 'the potential overview refused a request it should answer' );
        return $this->payloadOf( $response );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function coachQualityPayload( array $query ): array {
        // #3809 — the report now resolves an unsupplied bound to the season
        // window rather than reading all of history, so the fixture's two
        // evaluations need the window spelled out. A nested spelling in
        // `$query` still wins over the plain default added here, which is
        // the property these tests are about.
        $response = ReportsRestController::coachEvalQuality(
            $this->requestFor( '/talenttrack/v1/reports/coach-evaluation-quality', $query + self::WINDOW )
        );
        $this->assertSame( 200, $response->get_status(), 'the coach quality report refused a request it should answer' );
        return $this->payloadOf( $response );
    }

    /** @return array<string,mixed> */
    private function payloadOf( \WP_REST_Response $response ): array {
        $data = (array) $response->get_data();
        $this->assertIsArray( $data['data'] ?? null, 'the route does not use the standard envelope' );
        return (array) $data['data'];
    }

    private function refusalParameter( \WP_REST_Response $response ): string {
        $data  = (array) $response->get_data();
        $error = (array) ( ( (array) ( $data['errors'] ?? [] ) )[0] ?? [] );
        $this->assertSame( 'bad_filter', $error['code'] ?? '' );
        return (string) ( ( (array) ( $error['details'] ?? [] ) )['parameter'] ?? '' );
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private function playerIds( array $payload ): array {
        $ids = array_map(
            static fn( $row ): int => (int) ( ( (array) $row )['id'] ?? ( (array) $row )['player_id'] ?? 0 ),
            (array) ( $payload['players'] ?? [] )
        );
        sort( $ids );
        return array_values( $ids );
    }

    /**
     * A reader who holds the analytics capability but no academy-wide
     * scope: a `tt_people` row plus an active team grant is what
     * `QueryHelpers::get_teams_for_coach()` reads. The role id is
     * deliberately one no persona owns, so the matrix grants no global read
     * and the caller stays narrowed to the one team.
     */
    private function makeTeamScopedReader( int $team_id ): void {
        global $wpdb;
        $user_id = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $user    = new \WP_User( $user_id );
        $user->add_cap( 'tt_view_analytics' );

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Scope',
            'last_name'  => 'Reader',
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 999999,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        wp_set_current_user( $user_id );
    }

    private function insertTeam( string $name, string $age_group ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id'   => $this->club,
            'name'      => $name,
            'age_group' => $age_group,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'first_name'    => $first,
            'last_name'     => $last,
            'status'        => 'active',
            'date_of_birth' => gmdate( 'Y-m-d', strtotime( '-15 years -30 days' ) ),
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertGame( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Game ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'game',
            'game_subtype_key'    => 'League',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMinutes( int $activity_id, int $player_id, int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => $minutes,
        ] );
    }

    private function insertEvaluation( int $coach_id, int $player_id, string $date ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'   => $this->club,
            'player_id' => $player_id,
            'coach_id'  => $coach_id,
            'eval_date' => $date,
            'notes'     => '',
        ] );
        $evaluation_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_eval_categories", [
            'club_id'       => $this->club,
            'category_key'  => 'zz_alias_' . wp_rand( 10000, 99999 ),
            'label'         => 'Alias category',
            'parent_id'     => null,
            'display_order' => 900,
            'is_active'     => 1,
        ] );
        $wpdb->insert( "{$this->p}tt_eval_ratings", [
            'club_id'       => $this->club,
            'evaluation_id' => $evaluation_id,
            'category_id'   => (int) $wpdb->insert_id,
            'rating'        => 7.0,
        ] );
    }
}
