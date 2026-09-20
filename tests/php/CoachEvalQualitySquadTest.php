<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\REST\ReportsRestController;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Analytics\Reports\CoachEvalQualityQuery;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3809 — the coach evaluation-quality report answers the head of
 * development's month-end question: who evaluated nobody.
 *
 * It could not. The rows were `tt_evaluations` grouped by `coach_id`, so
 * a coach with nothing in the window produced no group and no row — and
 * that is exactly the coach worth ringing. A new U13 coach simply was
 * not in the report. An inner join to the ratings dropped a second kind
 * of coach: one who wrote evaluations but no rating rows.
 *
 * The report now starts from the coaches who hold a team, through the
 * same team-staff path the evaluation-coverage report uses, and left-
 * joins the evaluations in the window.
 */
final class CoachEvalQualitySquadTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 0;
    private int $quiet_team = 0;
    private int $busy_team = 0;
    private int $quiet_coach = 0;
    private int $busy_coach = 0;
    private int $evaluated_player = 0;
    private string $today = '';
    private string $week_ago = '';

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

        $this->today    = gmdate( 'Y-m-d' );
        $this->week_ago = gmdate( 'Y-m-d', strtotime( '-7 days' ) );

        // The reported case: a coach who holds a squad and evaluated
        // nobody. Three players, no evaluations at all.
        $this->quiet_team  = $this->insertTeam( 'Quiet U13' );
        $this->quiet_coach = (int) self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Quiet Coach' ] );
        $this->assignHeadCoach( $this->quiet_team, $this->quiet_coach, 'Quiet', 'Coach' );
        foreach ( [ 'Aart', 'Bas', 'Cees' ] as $name ) {
            $this->insertPlayer( $this->quiet_team, $name );
        }

        // The second kind of coach the old join dropped: evaluations
        // written, no rating rows attached.
        $this->busy_team  = $this->insertTeam( 'Busy U15' );
        $this->busy_coach = (int) self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Busy Coach' ] );
        $this->assignHeadCoach( $this->busy_team, $this->busy_coach, 'Busy', 'Coach' );
        $this->evaluated_player = $this->insertPlayer( $this->busy_team, 'Daan' );
        $this->insertPlayer( $this->busy_team, 'Evert' );
        $this->insertEvaluation( $this->busy_coach, $this->evaluated_player, $this->week_ago, false );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_coach_with_no_evaluations_in_the_window_still_has_a_row(): void {
        $row = $this->rowFor( $this->quiet_coach );

        $this->assertSame( 0, $row['eval_count'], 'the quiet coach should be reported with zero, not omitted' );
        $this->assertSame( 3, $row['squad_size'], 'the row carries the squad the coach is responsible for' );
        $this->assertSame( 0, $row['players_evaluated'] );
        $this->assertSame( 3, $row['players_never_evaluated'] );
        $this->assertNull( $row['days_since_last_eval'], 'a coach who never evaluated has no days-since' );
    }

    public function test_a_coach_with_evaluations_but_no_ratings_still_has_a_row(): void {
        $row = $this->rowFor( $this->busy_coach );

        $this->assertSame( 1, $row['eval_count'], 'the evaluation count survives the missing rating rows' );
        $this->assertSame( 0, $row['rating_count'] );
        $this->assertNull( $row['mean_rating'], 'no ratings means no mean, not a dropped coach' );
        $this->assertNull( $row['stddev'] );
        $this->assertNull( $row['modal_value'] );
        $this->assertFalse( $row['low_variance'], 'an empty sample is never flagged' );
    }

    public function test_the_squad_columns_count_the_window_and_the_season_separately(): void {
        $row = $this->rowFor( $this->busy_coach );

        $this->assertSame( 2, $row['squad_size'] );
        $this->assertSame( 1, $row['players_evaluated'], 'one of the two was evaluated inside the window' );
        $this->assertSame( 1, $row['players_never_evaluated'], 'the other has nothing this season' );
        $this->assertSame( 7, $row['days_since_last_eval'] );
    }

    /** A player evaluated before the window counts for the season, not the window. */
    public function test_an_evaluation_outside_the_window_is_not_counted_in_it(): void {
        $rows = ( new CoachEvalQualityQuery() )->rows( [
            'from' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
            'to'   => $this->today,
        ] );
        $row = $this->pick( $rows, $this->busy_coach );

        $this->assertSame( 0, $row['eval_count'], 'the week-old evaluation is outside a two-day window' );
        $this->assertSame( 0, $row['players_evaluated'] );
        $this->assertSame( 1, $row['players_never_evaluated'], 'it still counts as evaluated this season' );
        $this->assertSame( 7, $row['days_since_last_eval'], 'days-since is not bounded by the window' );
    }

    public function test_the_report_echoes_the_window_it_applied(): void {
        $report = ( new CoachEvalQualityQuery() )->report( [ 'from' => '2026-10-01', 'to' => '2026-10-31' ] );

        $this->assertSame( '2026-10-01', $report['from'] );
        $this->assertSame( '2026-10-31', $report['to'] );
    }

    public function test_an_unsupplied_window_falls_back_to_the_season_and_is_echoed(): void {
        $report  = ( new CoachEvalQualityQuery() )->report( [] );
        $default = ReportFilters::seasonDefaultWindow();

        $this->assertSame( $default['from'], $report['from'] );
        $this->assertSame( $default['to'], $report['to'] );
    }

    public function test_the_query_takes_the_dates_under_either_spelling(): void {
        $aliased = ( new CoachEvalQualityQuery() )->report( [ 'date_from' => '2026-10-01', 'date_to' => '2026-10-31' ] );

        $this->assertSame( '2026-10-01', $aliased['from'], 'date_from stopped working' );
        $this->assertSame( '2026-10-31', $aliased['to'], 'date_to stopped working' );
    }

    /**
     * The two reports must never disagree about who a team's coach is, so
     * the row's coach is the one the coverage service resolves.
     */
    public function test_coach_resolution_matches_the_coverage_report(): void {
        $expected = ( new EvalCoverageService() )->headCoaches();

        $this->assertSame( $this->quiet_coach, $expected[ $this->quiet_team ]['coach_id'] ?? 0 );
        $this->assertSame( $expected[ $this->quiet_team ]['coach_id'], $this->rowFor( $this->quiet_coach )['coach_id'] );
    }

    /* ---- the REST surface ----------------------------------------------- */

    public function test_the_route_declares_from_and_to_and_keeps_the_aliases(): void {
        $args = [];
        foreach ( rest_get_server()->get_routes()['/talenttrack/v1/reports/coach-evaluation-quality'] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['GET'] ) ) $args = (array) $handler['args'];
        }
        foreach ( [ 'from', 'to', 'date_from', 'date_to', 'team_id', 'filter' ] as $name ) {
            $this->assertArrayHasKey( $name, $args, "{$name} is not declared on the coach quality route" );
        }
    }

    public function test_the_response_carries_the_window_and_the_new_fields(): void {
        $payload = $this->payload( [ 'from' => $this->week_ago, 'to' => $this->today ] );

        $this->assertSame( $this->week_ago, $payload['from'] ?? null );
        $this->assertSame( $this->today, $payload['to'] ?? null );

        $row = $this->pick( (array) $payload['rows'], $this->quiet_coach );
        foreach ( [ 'squad_size', 'players_evaluated', 'players_never_evaluated', 'days_since_last_eval' ] as $field ) {
            $this->assertArrayHasKey( $field, $row, "{$field} is missing from the REST row" );
        }
    }

    /** The plain alias was this route's declared name until #3809. */
    public function test_the_plain_date_from_alias_still_reaches_the_query(): void {
        $payload = $this->payload( [ 'date_from' => '2026-10-01', 'date_to' => '2026-10-31' ] );

        $this->assertSame( '2026-10-01', $payload['from'] ?? null );
        $this->assertSame( '2026-10-31', $payload['to'] ?? null );
    }

    /** Unchanged: reports capability plus academy-wide scope. */
    public function test_a_subscriber_is_refused(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/coach-evaluation-quality' );
        $this->assertNotSame( 200, rest_do_request( $req )->get_status(), 'a subscriber read the coach stats' );
    }

    /* ---- helpers -------------------------------------------------------- */

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function payload( array $query ): array {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/coach-evaluation-quality' );
        foreach ( $query as $key => $value ) {
            $req->set_param( $key, $value );
        }
        $response = ReportsRestController::coachEvalQuality( $req );
        $this->assertSame( 200, $response->get_status() );
        $data = (array) $response->get_data();
        return (array) ( $data['data'] ?? [] );
    }

    /** @return array<string,mixed> */
    private function rowFor( int $coach_id ): array {
        return $this->pick(
            ( new CoachEvalQualityQuery() )->rows( [ 'from' => $this->week_ago, 'to' => $this->today ] ),
            $coach_id
        );
    }

    /**
     * @param array<int,mixed> $rows
     * @return array<string,mixed>
     */
    private function pick( array $rows, int $coach_id ): array {
        foreach ( $rows as $row ) {
            if ( (int) ( ( (array) $row )['coach_id'] ?? 0 ) === $coach_id ) return (array) $row;
        }
        $this->fail( "coach {$coach_id} has no row — the report still starts from the evaluations" );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first,
            'last_name'  => 'Speler',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * The team-staff path that replaced `tt_teams.head_coach_id` (#1315):
     * a `tt_people` row joined to the team through the `head_coach`
     * functional role.
     */
    private function assignHeadCoach( int $team_id, int $user_id, string $first, string $last ): void {
        global $wpdb;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_functional_roles WHERE role_key = %s LIMIT 1",
            'head_coach'
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [
                'club_id'  => $this->club,
                'role_key' => 'head_coach',
                'label'    => 'Head Coach',
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => $first,
            'last_name'  => $last,
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
        ] );
    }

    private function insertEvaluation( int $coach_id, int $player_id, string $date, bool $with_rating ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'   => $this->club,
            'player_id' => $player_id,
            'coach_id'  => $coach_id,
            'eval_date' => $date,
            'notes'     => '',
        ] );
        if ( ! $with_rating ) return;

        $evaluation_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_eval_categories", [
            'club_id'       => $this->club,
            'category_key'  => 'zz_quality_' . wp_rand( 10000, 99999 ),
            'label'         => 'Quality category',
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
