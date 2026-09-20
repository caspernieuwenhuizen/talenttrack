<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3792 — `GET reports/minutes-audit` refuses a team the caller may not
 * read, the way the three attendance readers on the same controller do.
 *
 * It used to answer that situation with an empty matrix and a 200. An
 * empty matrix is a statement about the data — this team recorded no
 * minutes — so a coach checking whether another age group had logged
 * theirs got a confident, wrong answer, indistinguishable from a real
 * gap. #2893 settled the principle for the attendance routes; this is
 * the same route family honouring it.
 *
 * The distinction the fix turns on is asserted from both sides: an
 * out-of-scope team is a 403, and an in-scope team with nothing recorded
 * is still an empty matrix with a 200.
 */
final class MinutesAuditScopeTest extends WP_UnitTestCase {

    private const ROUTE  = '/talenttrack/v1/reports/minutes-audit';
    private const WINDOW = [ 'from' => '2020-01-01', 'to' => '2020-12-31' ];

    private string $p = '';
    private int $club = 0;
    private int $team_a = 0;
    private int $team_b = 0;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        global $wpdb, $wp_rest_server;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->hide_errors();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->team_a = $this->insertTeam( 'Audit U11' );
        $this->team_b = $this->insertTeam( 'Audit U13' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_team_outside_the_callers_scope_is_refused(): void {
        $this->makeTeamScopedReader( $this->team_a );

        $response = $this->request( [ 'team_id' => $this->team_b ] );

        $this->assertSame( 403, $response->get_status(), 'an out-of-scope team was answered with data' );
    }

    /** The refusal is the attendance routes' refusal, body shape included. */
    public function test_the_refusal_carries_the_attendance_error_shape(): void {
        $this->makeTeamScopedReader( $this->team_a );

        $data  = (array) $this->request( [ 'team_id' => $this->team_b ] )->get_data();
        $error = (array) ( ( (array) ( $data['errors'] ?? [] ) )[0] ?? [] );

        $this->assertSame( 'forbidden_team', $error['code'] ?? '', 'the refusal does not use the shared error code' );
        $this->assertArrayNotHasKey( 'games', $data, 'the refusal still carries a matrix' );
    }

    /** #3790 unified the two spellings; the refusal has to follow both. */
    public function test_both_spellings_refuse_alike(): void {
        $this->makeTeamScopedReader( $this->team_a );

        $plain  = $this->request( [ 'team_id' => $this->team_b ] );
        $nested = $this->request( [ 'filter' => [ 'team_id' => $this->team_b ] ] );

        $this->assertSame( $plain->get_status(), $nested->get_status(), 'the two spellings answer differently' );
        $this->assertSame( 403, $nested->get_status(), 'filter[team_id] reads past the caller\'s scope' );
    }

    /**
     * The half the fix must not break: "no data" and "not allowed" are
     * different answers, so an in-scope team with nothing recorded keeps
     * its empty matrix and its 200.
     */
    public function test_an_in_scope_team_with_no_minutes_still_gets_an_empty_matrix(): void {
        $this->makeTeamScopedReader( $this->team_a );

        $response = $this->request( [ 'team_id' => $this->team_a ] );
        $this->assertSame( 200, $response->get_status(), 'the caller\'s own team was refused' );

        $payload = (array) ( (array) $response->get_data() )['data'];
        $this->assertSame( [], (array) $payload['games'], 'a team with no minutes should report none' );
        $this->assertSame( 0, (int) ( (array) $payload['summary'] )['total_games'] );
    }

    /** A caller with academy-wide scope reads either team, as before. */
    public function test_an_academy_wide_reader_is_unaffected(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        foreach ( [ $this->team_a, $this->team_b ] as $team_id ) {
            $this->assertSame(
                200,
                $this->request( [ 'team_id' => $team_id ] )->get_status(),
                'an academy-wide reader lost access to a team'
            );
        }
    }

    /** The per-match editor refused before this change and still does. */
    public function test_the_per_match_editor_still_refuses_an_out_of_scope_activity(): void {
        $activity_id = $this->insertGame( $this->team_b, '2020-03-07' );
        $this->makeTeamScopedReader( $this->team_a );
        ( new \WP_User( get_current_user_id() ) )->add_cap( 'tt_edit_activities' );

        $req = new WP_REST_Request( 'GET', self::ROUTE . '/' . $activity_id . '/editor' );
        $this->assertSame( 403, rest_do_request( $req )->get_status(), 'the editor stopped refusing' );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @param array<string,mixed> $query */
    private function request( array $query ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', self::ROUTE );
        foreach ( $query + self::WINDOW as $key => $value ) {
            $req->set_param( $key, $value );
        }
        return rest_do_request( $req );
    }

    /**
     * A reader who holds the analytics capability but no academy-wide
     * scope: a `tt_people` row plus an active team grant is what
     * `QueryHelpers::get_teams_for_coach()` reads. The role id is
     * deliberately one no persona owns, so the matrix grants no global
     * read and the caller stays narrowed to the one team.
     */
    private function makeTeamScopedReader( int $team_id ): void {
        global $wpdb;
        $user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
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

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
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
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }
}
