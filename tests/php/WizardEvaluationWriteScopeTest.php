<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Wizards\Evaluation\EvaluationRowRestController;
use TT\Modules\Wizards\Evaluation\PlayerPickerStep;
use TT\Modules\Wizards\Evaluation\ReviewStep;

/**
 * The new-evaluation wizard writes an evaluation only for a player the
 * reader may evaluate, the question `POST /evaluations` asks: the academy
 * admin, or the coach of the player's team. A coach of one team writes
 * nothing for another team's player, on any of the wizard's write paths.
 */
final class WizardEvaluationWriteScopeTest extends WP_UnitTestCase {

    private int $own_player   = 0;
    private int $other_player = 0;
    private int $own_activity = 0;
    private int $coach        = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Evalwrite Own U15', 'age_group' => 'U15' ] );
        $own_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Evalwrite Other U15', 'age_group' => 'U15' ] );
        $other_team = (int) $wpdb->insert_id;

        $this->own_player   = $this->player( $own_team, 'Ownwrite' );
        $this->other_player = $this->player( $other_team, 'Otherwrite' );

        $wpdb->insert( "{$p}tt_activities", [
            'club_id' => $club, 'team_id' => $own_team, 'title' => 'Evalwrite training',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '-2 days' ) ),
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        $this->own_activity = (int) $wpdb->insert_id;

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Evalwrite', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $club, 'team_id' => $own_team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $own_team,
        ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_row_route_writes_for_the_coachs_own_player(): void {
        $this->assertSame( 201, $this->rowRoute( $this->own_player )->get_status() );
        $this->assertSame( 1, $this->evaluationsFor( $this->own_player ) );
    }

    public function test_the_row_route_refuses_another_teams_player(): void {
        $this->assertSame( 403, $this->rowRoute( $this->other_player )->get_status() );
        $this->assertSame( 0, $this->evaluationsFor( $this->other_player ) );
    }

    public function test_the_player_pick_refuses_another_teams_player(): void {
        $step = new PlayerPickerStep();
        $this->assertIsArray( $step->validate( [ 'player_id' => $this->own_player ], [] ), 'precondition: the own player is a pick' );
        $this->assertInstanceOf( \WP_Error::class, $step->validate( [ 'player_id' => $this->other_player ], [] ) );
    }

    public function test_the_player_first_submit_writes_nothing_for_another_teams_player(): void {
        $result = ( new ReviewStep() )->submit( [
            '_path' => 'player-first', 'player_id' => $this->other_player,
            'ratings_self' => [ 1 => 7 ], 'eval_date' => current_time( 'Y-m-d' ),
        ] );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 0, $this->evaluationsFor( $this->other_player ) );
    }

    public function test_the_activity_first_submit_writes_only_for_the_coachs_own_players(): void {
        ( new ReviewStep() )->submit( [
            '_path' => 'activity-first', 'activity_id' => $this->own_activity,
            'ratings' => [ $this->own_player => [ 1 => 7 ], $this->other_player => [ 1 => 7 ] ],
        ] );
        $this->assertSame( 1, $this->evaluationsFor( $this->own_player ), 'precondition: the own player is written' );
        $this->assertSame( 0, $this->evaluationsFor( $this->other_player ) );
    }

    private function rowRoute( int $player_id ): \WP_REST_Response {
        $request = new \WP_REST_Request( 'POST', '/talenttrack/v1/wizards/new-evaluation/insert-row' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [
            'player_id' => $player_id, 'eval_date' => current_time( 'Y-m-d' ), 'ratings' => [ 1 => 7 ],
        ] ) );
        return EvaluationRowRestController::insert( $request );
    }

    private function evaluationsFor( int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE player_id = %d",
            $player_id
        ) );
    }

    private function player( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team_id,
            'first_name' => $first, 'last_name' => 'Kidx', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }
}
