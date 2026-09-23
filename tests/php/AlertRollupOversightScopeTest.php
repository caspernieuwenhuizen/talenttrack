<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;
use TT\Modules\Alerts\Services\AlertOversight;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #4023 — the roll-up scope is resolved from the matrix, not from a
 * settings capability.
 *
 * Epic decision 7 sends a Head of Development no occurrences of their own,
 * and the roll-up is the whole compensation for that. It asked
 * `tt_edit_settings`, which the HoD does not hold, and fell through to
 * `get_teams_for_coach()`, which returns nothing for a globally scoped
 * user — so the one surface they were owed answered `[]` while their
 * academy had dozens of open alerts.
 */
final class AlertRollupOversightScopeTest extends WP_UnitTestCase {

    private string $p = '';

    private int $club = 1;

    private AlertOccurrencesRepository $repo;

    private int $hod = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->repo = new AlertOccurrencesRepository();

        // DELETE, not TRUNCATE: TRUNCATE commits and breaks the rollback.
        $wpdb->query( "DELETE FROM {$this->p}tt_alert_occurrences" );

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        $this->hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * The premise of the bug. If the HoD ever gains `tt_edit_settings` the
     * old code would look correct and this whole file would pass for the
     * wrong reason, so the absence is asserted rather than assumed.
     */
    public function test_the_head_of_development_holds_no_settings_capability(): void {
        $this->assertFalse( user_can( $this->hod, 'tt_edit_settings' ) );
    }

    public function test_the_head_of_development_oversees_every_team(): void {
        $team_a = $this->insertTeam( 'U14 rollup hod' );
        $team_b = $this->insertTeam( 'U16 rollup hod' );

        $ids = AlertOversight::teamIdsFor( $this->hod );

        $this->assertContains( $team_a, $ids );
        $this->assertContains( $team_b, $ids );
        $this->assertTrue( AlertOversight::isAvailableTo( $this->hod ) );
    }

    /**
     * The reported reproduction: `GET alerts` returns a long list including
     * an urgent activity alert while `GET alerts/rollup` returns nothing.
     */
    public function test_an_open_activity_alert_reaches_the_head_of_developments_rollup(): void {
        $team = $this->insertTeam( 'U17 rollup hod' );
        $act  = $this->insertActivity( $team );

        // The occurrence goes to a coach, per decision 7 — the HoD receives
        // none of their own, which is exactly why they need the aggregate.
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->seed( 'activity', $act, $coach, Severity::URGENT );

        $rows = AlertOversight::forUser( $this->hod );

        $by = [];
        foreach ( $rows as $row ) $by[ $row['team_id'] ] = $row;

        $this->assertArrayHasKey( $team, $by );
        $this->assertSame( 1, $by[ $team ]['count'] );
        $this->assertSame( Severity::URGENT, $by[ $team ]['severity'] );
    }

    public function test_the_rest_rollup_is_not_empty_for_the_head_of_development(): void {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $team = $this->insertTeam( 'U19 rollup hod' );
        $act  = $this->insertActivity( $team );
        $this->seed( 'activity', $act, (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] ) );

        wp_set_current_user( $this->hod );
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/alerts/rollup' ) );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $rows = is_array( $data ) && isset( $data['data'] ) ? $data['data'] : $data;

        $this->assertIsArray( $rows );
        $this->assertNotEmpty( $rows, 'the roll-up is the HoD surface; empty is the bug' );
    }

    /**
     * The other half of the fix: widening the club-wide branch must not
     * widen the narrow one. A coach assigned to one team still oversees
     * that team only, and there is no request parameter in play — the
     * scope list is computed here and passed as the IN-list.
     */
    public function test_a_team_scoped_coach_still_sees_only_their_own_team(): void {
        $mine   = $this->insertTeam( 'U14 mine' );
        $theirs = $this->insertTeam( 'U16 theirs' );

        $coach = $this->coachOf( $mine );

        $this->seed( 'activity', $this->insertActivity( $mine ), $coach );
        $this->seed( 'activity', $this->insertActivity( $theirs ), $coach );

        $this->assertSame( [ $mine ], AlertOversight::teamIdsFor( $coach ) );

        $rows = AlertOversight::forUser( $coach );
        $this->assertCount( 1, $rows );
        $this->assertSame( $mine, $rows[0]['team_id'] );
        $this->assertNotContains( $theirs, array_column( $rows, 'team_id' ) );
    }

    // Helpers

    private function seed(
        string $subjectType,
        int $subjectId,
        int $userId,
        string $severity = Severity::ATTENTION
    ): void {
        $this->repo->upsert(
            new AlertOccurrence(
                'test.rollup',
                $userId,
                $subjectType,
                $subjectId,
                $severity,
                [ 'title' => 'Something needs doing', 'url' => 'https://example.test/' ],
                null
            ),
            current_time( 'mysql' )
        );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $teamId ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => $this->club,
            'team_id'           => $teamId,
            'title'             => 'Rollup fixture',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'training',
            'plan_state'        => 'planned',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * A coach narrowed to one team. The scope grant hangs off a `tt_people`
     * row, not off the WP user: `get_teams_for_coach()` resolves the person
     * first and returns nothing without one.
     */
    private function coachOf( int $teamId ): int {
        global $wpdb;
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Rollup',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $teamId,
        ] );
        return $coach;
    }
}
