<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Wizards\Evaluation\EvaluationInserter;

/**
 * `POST /evaluations` and `PUT /evaluations/{id}` ask the rule the
 * evaluation wizard asks (`EvaluationInserter::mayWriteFor()`), through the
 * real route and its permission callback: the head of development writes for
 * any player, a head coach only for their own team's players.
 */
final class EvaluationRestWriteRuleTest extends WP_UnitTestCase {

    private int $team_a   = 0;
    private int $team_b   = 0;
    private int $player_a = 0;
    private int $player_b = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Restrule A U11', 'age_group' => 'U11' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Restrule B U11', 'age_group' => 'U11' ] );
        $this->team_b = (int) $wpdb->insert_id;

        $this->player_a = $this->player( $this->team_a, 'Restrulea' );
        $this->player_b = $this->player( $this->team_b, 'Restruleb' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_head_of_development_creates_for_any_player(): void {
        $hod = $this->headOfDevelopment();
        $this->assertTrue( EvaluationInserter::mayWriteFor( $hod, $this->player_b ), 'precondition: the wizard admits them' );

        [ $data, $status ] = $this->send( 'POST', 'evaluations', $this->body( $this->player_b ) );
        $this->assertSame( 200, $status, (string) wp_json_encode( $data ) );
        $this->assertSame( 1, $this->evaluationsFor( $this->player_b ) );
    }

    public function test_a_head_coach_cannot_create_for_another_teams_player(): void {
        $coach = $this->headCoachOf( $this->team_a );
        $this->assertFalse( EvaluationInserter::mayWriteFor( $coach, $this->player_b ), 'precondition: the wizard refuses them' );

        [ , $status ] = $this->send( 'POST', 'evaluations', $this->body( $this->player_b ) );
        $this->assertSame( 403, $status );
        $this->assertSame( 0, $this->evaluationsFor( $this->player_b ) );

        [ , $own ] = $this->send( 'POST', 'evaluations', $this->body( $this->player_a ) );
        $this->assertSame( 200, $own, 'their own player is still evaluated' );
    }

    public function test_the_head_of_development_updates_any_players_evaluation(): void {
        $eval_id = $this->evaluation( $this->player_b );
        $this->headOfDevelopment();

        [ $data, $status ] = $this->send( 'PUT', 'evaluations/' . $eval_id, [ 'notes' => 'Restrule edited.' ] );
        $this->assertSame( 200, $status, (string) wp_json_encode( $data ) );
        $this->assertSame( 'Restrule edited.', $this->notesOf( $eval_id ) );
    }

    public function test_a_head_coach_cannot_update_another_teams_evaluation(): void {
        $eval_id = $this->evaluation( $this->player_b );
        $this->headCoachOf( $this->team_a );

        [ , $status ] = $this->send( 'PUT', 'evaluations/' . $eval_id, [ 'notes' => 'Restrule edited.' ] );
        $this->assertSame( 403, $status );
        $this->assertSame( 'Restrule original.', $this->notesOf( $eval_id ) );
    }

    /** @return array{0: array<mixed>, 1: int} */
    private function send( string $method, string $route, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        $response = rest_get_server()->dispatch( $request );
        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }

    /** @return array<string, mixed> */
    private function body( int $player_id ): array {
        return [ 'player_id' => $player_id, 'eval_date' => gmdate( 'Y-m-d', (int) strtotime( '-1 day' ) ), 'notes' => 'Restrule note.' ];
    }

    private function evaluation( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id' => 1, 'player_id' => $player_id, 'coach_id' => 0,
            'eval_date' => gmdate( 'Y-m-d', (int) strtotime( '-3 days' ) ), 'notes' => 'Restrule original.',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the evaluation row was written' );
        return $id;
    }

    private function headOfDevelopment(): int {
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        wp_set_current_user( $hod );
        AuthorizationService::flushCache();
        return $hod;
    }

    private function headCoachOf( int $team_id ): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Restrule', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $team_id, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        wp_set_current_user( $coach );
        AuthorizationService::flushCache();
        return $coach;
    }

    private function notesOf( int $eval_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT notes FROM {$wpdb->prefix}tt_evaluations WHERE id = %d", $eval_id
        ) );
    }

    private function evaluationsFor( int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE player_id = %d", $player_id
        ) );
    }

    private function player( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => 1, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Kidx',
            'status' => 'active', 'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the player row was written' );
        return $id;
    }
}
