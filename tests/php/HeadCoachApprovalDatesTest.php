<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\GoalStatus;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;

/**
 * Approving a goal a player proposed is the player's head coach's call, and
 * the head coach is the person whose head-coach assignment on the player's
 * team is live today. An ended or a future one does not count; one ending
 * today does.
 */
final class HeadCoachApprovalDatesTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $player = 0;
    private int $head_role      = 0;
    private int $assistant_role = 0;

    private string $matrix_was = '';
    private string $yesterday  = '';
    private string $today      = '';
    private string $tomorrow   = '';

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );

        global $wpdb;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Approval U14' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $club, 'first_name' => 'Approval', 'last_name' => 'Player',
            'team_id' => $this->team, 'status' => 'active', 'date_of_birth' => '2012-03-01',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->head_role      = $this->functionalRole( 'head_coach' );
        $this->assistant_role = $this->functionalRole( 'assistant_coach' );

        $now             = new \DateTimeImmutable( current_time( 'Y-m-d' ) );
        $this->today     = $now->format( 'Y-m-d' );
        $this->yesterday = $now->modify( '-1 day' )->format( 'Y-m-d' );
        $this->tomorrow  = $now->modify( '+1 day' )->format( 'Y-m-d' );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        $this->flush();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_current_head_coach_approves(): void {
        $uid  = $this->coach( [ [ $this->head_role, '2020-08-01', null ] ] );
        $goal = $this->pendingGoal();

        $this->assertTrue( AuthorizationService::isHeadCoachOfPlayer( $uid, $this->player ) );
        $this->assertSame( 200, $this->approve( $uid, $goal )[0] );
        $this->assertSame( GoalStatus::IN_PROGRESS, $this->status( $goal ) );
    }

    public function test_an_ended_head_coach_who_now_assists_is_refused(): void {
        $uid  = $this->coach( [
            [ $this->head_role, '2020-08-01', $this->yesterday ],
            [ $this->assistant_role, $this->today, null ],
        ] );
        $goal = $this->pendingGoal();

        $this->assertFalse( AuthorizationService::isHeadCoachOfPlayer( $uid, $this->player ) );
        [ $status, $data ] = $this->approve( $uid, $goal );
        $this->assertSame( 403, $status );
        $this->assertSame( 'forbidden_approval', $this->code( $data ) );
        $this->assertSame( GoalStatus::PENDING_APPROVAL, $this->status( $goal ) );
    }

    public function test_a_future_head_coach_who_now_assists_is_refused(): void {
        $uid  = $this->coach( [
            [ $this->head_role, $this->tomorrow, null ],
            [ $this->assistant_role, '2020-08-01', null ],
        ] );
        $goal = $this->pendingGoal();

        $this->assertFalse( AuthorizationService::isHeadCoachOfPlayer( $uid, $this->player ) );
        [ $status, $data ] = $this->approve( $uid, $goal );
        $this->assertSame( 403, $status );
        $this->assertSame( 'forbidden_approval', $this->code( $data ) );
        $this->assertSame( GoalStatus::PENDING_APPROVAL, $this->status( $goal ) );
    }

    public function test_a_head_coach_assignment_ending_today_still_approves(): void {
        $uid  = $this->coach( [ [ $this->head_role, '2020-08-01', $this->today ] ] );
        $goal = $this->pendingGoal();

        $this->assertTrue( AuthorizationService::isHeadCoachOfPlayer( $uid, $this->player ) );
        $this->assertSame( 200, $this->approve( $uid, $goal )[0] );
        $this->assertSame( GoalStatus::IN_PROGRESS, $this->status( $goal ) );
    }

    /**
     * With no live assignment the coach holds no team, so no goals
     * capability at any scope: the route's own capability check refuses
     * before the per-goal lookup that would answer 404.
     */
    public function test_an_ended_head_coach_with_nothing_else_does_not_reach_the_goal(): void {
        $uid  = $this->coach( [ [ $this->head_role, '2020-08-01', $this->yesterday ] ] );
        $goal = $this->pendingGoal();

        $this->assertFalse( AuthorizationService::isHeadCoachOfPlayer( $uid, $this->player ) );
        [ $status, $data ] = $this->approve( $uid, $goal );
        $this->assertSame( 403, $status );
        $this->assertSame( 'rest_forbidden', $this->code( $data ) );
        $this->assertSame( GoalStatus::PENDING_APPROVAL, $this->status( $goal ) );
    }

    // ---- helpers ------------------------------------------------------------------

    /**
     * A tt_coach user with a staff record, assigned to the team once per
     * `[ functional role id, start, end ]`.
     *
     * @param list<array{0:int,1:?string,2:?string}> $assignments
     */
    private function coach( array $assignments ): int {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Approval', 'last_name' => 'Coach' . $uid,
            'role_type' => 'staff', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $repo   = new PeopleRepository();
        foreach ( $assignments as [ $role, $start, $end ] ) {
            $this->assertTrue( $repo->assignToTeam( $this->team, $person, $role, $start, $end ) );
        }
        $this->flush();
        return $uid;
    }

    private function pendingGoal(): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_goals", [
            'club_id'   => (int) CurrentClub::id(),
            'player_id' => $this->player,
            'title'     => 'Proposed by the player',
            'status'    => GoalStatus::PENDING_APPROVAL,
            'priority'  => 'medium',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id );
        return $id;
    }

    /** @return array{0:int,1:mixed} */
    private function approve( int $uid, int $goal ): array {
        wp_set_current_user( $uid );
        $this->flush();
        $request = new WP_REST_Request( 'PATCH', '/talenttrack/v1/goals/' . $goal . '/status' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [ 'status' => GoalStatus::IN_PROGRESS ] ) );
        $response = rest_get_server()->dispatch( $request );
        return [ (int) $response->get_status(), $response->get_data() ];
    }

    /** @param mixed $data */
    private function code( $data ): string {
        $data = (array) $data;
        if ( isset( $data['code'] ) ) return (string) $data['code'];
        $errors = (array) ( $data['errors'] ?? [] );
        $first  = (array) ( $errors[0] ?? [] );
        return (string) ( $first['code'] ?? '' );
    }

    private function status( int $goal ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}tt_goals WHERE id = %d",
            $goal
        ) );
    }

    private function functionalRole( string $key ): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $id   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            $key, $club
        ) );
        if ( $id > 0 ) return $id;
        $wpdb->insert( "{$wpdb->prefix}tt_functional_roles", [ 'club_id' => $club, 'role_key' => $key, 'label' => $key ] );
        return (int) $wpdb->insert_id;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        MatrixGate::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_cache_flush();
    }
}
