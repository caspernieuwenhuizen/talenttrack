<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Shared\Frontend\FrontendGoalsManageView;

/**
 * #3998 — a goal opened by id follows the same per-player check as an
 * evaluation: on the detail and edit views and on every `goals/{id}` write.
 * A goal the caller may not reach is answered exactly as a missing one.
 */
final class GoalPlayerCheckTest extends WP_UnitTestCase {

    private int $admin   = 0;
    private int $teamA   = 0;
    private int $teamB   = 0;
    private int $playerA = 0;
    private int $playerB = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Goal team A' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Goal team B' ] );
        $this->teamB = (int) $wpdb->insert_id;
        $this->playerA = $this->makePlayer( 'Goalalpha', $this->teamA );
        $this->playerB = $this->makePlayer( 'Goalbravo', $this->teamB );

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        $_GET = [];
        parent::tear_down();
    }

    public function test_a_coach_reaches_their_own_squads_goals_and_not_another_squads(): void {
        $goalA = $this->makeGoal( $this->playerA, 'Own squad goal title' );
        $goalB = $this->makeGoal( $this->playerB, 'Other squad goal title' );

        $coach = $this->makeHeadCoach( $this->teamA );
        wp_set_current_user( $coach );
        AuthorizationService::flushCache();
        $this->assertTrue( current_user_can( 'tt_edit_goals' ), 'the coach holds the goals capability, so a refusal comes from the per-player check' );

        // Detail view.
        $html = $this->render( $coach, [ 'tt_view' => 'goals', 'id' => (string) $goalA ] );
        $this->assertStringContainsString( 'Own squad goal title', $html, 'the grant: the squad coach sees their goal' );
        $html = $this->render( $coach, [ 'tt_view' => 'goals', 'id' => (string) $goalB ] );
        $this->assertStringNotContainsString( 'Other squad goal title', $html );
        $this->assertStringContainsString( 'That goal no longer exists.', $html, 'answered as not found' );

        // Edit view.
        $html = $this->render( $coach, [ 'tt_view' => 'goals', 'id' => (string) $goalA, 'action' => 'edit' ] );
        $this->assertStringContainsString( 'Own squad goal title', $html );
        $html = $this->render( $coach, [ 'tt_view' => 'goals', 'id' => (string) $goalB, 'action' => 'edit' ] );
        $this->assertStringNotContainsString( 'Other squad goal title', $html );
        $this->assertStringContainsString( 'That goal no longer exists.', $html );

        // A missing goal and a refused one answer the same.
        $missing = $this->send( $coach, 'PUT', 'goals/' . ( $goalB + 100000 ), [ 'title' => 'x' ] );
        $refused = $this->send( $coach, 'PUT', 'goals/' . $goalB, [ 'title' => 'Changed' ] );
        $this->assertSame( 404, $refused[0] );
        // Compared as the JSON a client receives: the error body carries a
        // `details` object, and assertSame() on two arrays compares objects
        // by instance, which two separate responses never share.
        $this->assertSame( (string) wp_json_encode( $missing ), (string) wp_json_encode( $refused ), 'a refusal reads exactly as a missing goal' );
        $this->assertSame( 'Other squad goal title', $this->column( $goalB, 'title' ) );

        $this->assertSame( 200, $this->send( $coach, 'PUT', 'goals/' . $goalA, [ 'title' => 'Own squad goal title' ] )[0] );

        // Status.
        $this->assertSame( 404, $this->send( $coach, 'PATCH', 'goals/' . $goalB . '/status', [ 'status' => 'completed' ] )[0] );
        $this->assertNotSame( 'completed', $this->column( $goalB, 'status' ) );
        $this->assertSame( 200, $this->send( $coach, 'PATCH', 'goals/' . $goalA . '/status', [ 'status' => 'completed' ] )[0] );

        // Delete (soft archive).
        $this->assertSame( 404, $this->send( $coach, 'DELETE', 'goals/' . $goalB )[0] );
        $this->assertNull( $this->column( $goalB, 'archived_at' ), 'the refused goal stays live' );
        $this->assertSame( 200, $this->send( $coach, 'DELETE', 'goals/' . $goalA )[0] );
        $this->assertNotNull( $this->column( $goalA, 'archived_at' ) );

        // Restore.
        $this->assertSame( 1, ( new ArchiveRepository() )->archive( 'goal', [ $goalB ], $this->admin ) );
        $this->assertSame( 404, $this->send( $coach, 'POST', 'goals/' . $goalB . '/restore' )[0] );
        $this->assertNotNull( $this->column( $goalB, 'archived_at' ), 'the refused goal stays archived' );
        $this->assertSame( 200, $this->send( $coach, 'POST', 'goals/' . $goalA . '/restore' )[0] );
        $this->assertNull( $this->column( $goalA, 'archived_at' ) );
    }

    // Fixtures

    private function makeGoal( int $player_id, string $title ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_goals", [
            'club_id'    => (int) CurrentClub::id(),
            'player_id'  => $player_id,
            'title'      => $title,
            'status'     => 'pending',
            'priority'   => 'medium',
            'created_by' => $this->admin,
        ] );
        $this->assertNotFalse( $ok, 'goal insert must succeed' );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id );
        return $id;
    }

    private function makePlayer( string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Goal',
            'last_name'     => $last,
            'team_id'       => $team_id,
            'status'        => 'active',
            'date_of_birth' => '2011-04-02',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write the player' );
        return $id;
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $person_id, 'the fixture must write the person' );

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        $this->assertContains( 'head_coach', PersonaResolver::personasFor( $uid ) );
        AuthorizationService::flushCache();
        $this->assertTrue( AuthorizationService::canReadPlayerSection( $uid, $this->playerA, 'goals' ), 'the head coach must read their squad, or the refusal is vacuous' );
        $this->assertFalse( AuthorizationService::canViewPlayer( $uid, $this->playerB ), 'the other squad is outside the coach\'s scope' );
        return $uid;
    }

    /** @param array<string,string> $get */
    private function render( int $user_id, array $get ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET = $get;
        ob_start();
        FrontendGoalsManageView::render( $user_id, false );
        $html = (string) ob_get_clean();
        $_GET = [];
        return $html;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:int,1:mixed}
     */
    private function send( int $user_id, string $method, string $route, array $body = [] ): array {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_get_server()->dispatch( $request );
        return [ (int) $response->get_status(), $response->get_data() ];
    }

    /** @return mixed */
    private function column( int $goal_id, string $column ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_goals WHERE id = %d",
            $goal_id
        ), ARRAY_A );
        return is_array( $row ) ? ( $row[ $column ] ?? null ) : null;
    }
}
