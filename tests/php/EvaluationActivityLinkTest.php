<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3582 — an evaluation made through REST can say which training or match it
 * came from.
 *
 * `POST /evaluations` and `PUT /evaluations/{id}` accepted an `activity_id`,
 * answered 200 and stored NULL: neither `extract()` nor `patch()` read it.
 * Only the wizard wrote the link, so any other client produced evaluations
 * the activity's coverage could never count. And `PUT` left `updated_at`
 * where it was, relying on an ON UPDATE clause that did not fire.
 */
final class EvaluationActivityLinkTest extends WP_UnitTestCase {

    private int $admin     = 0;
    private int $team_a    = 0;
    private int $team_b    = 0;
    private int $player_a  = 0;
    private int $training_a = 0;
    private int $training_b = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Hedel O11-1' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Hedel O12-1' ] );
        $this->team_b = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $this->team_a, 'first_name' => 'Bas', 'last_name' => 'Willems', 'status' => 'active',
        ] );
        $this->player_a = (int) $wpdb->insert_id;

        $this->training_a = $this->activity( $this->team_a );
        $this->training_b = $this->activity( $this->team_b );

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_create_stores_the_activity_link(): void {
        [ $data, $status ] = $this->send( 'POST', 'evaluations', [
            'player_id'    => $this->player_a,
            'eval_type_id' => 1,
            'activity_id'  => $this->training_a,
            'eval_date'    => '2026-09-15',
        ] );

        $this->assertSame( 200, $status );
        $this->assertSame( $this->training_a, (int) $this->row( (int) $data['data']['id'] )['activity_id'] );
    }

    public function test_a_create_without_a_link_stores_null(): void {
        [ $data ] = $this->send( 'POST', 'evaluations', [
            'player_id' => $this->player_a,
            'eval_date' => '2026-09-15',
        ] );

        $this->assertNull( $this->row( (int) $data['data']['id'] )['activity_id'] );
    }

    public function test_an_update_sets_keeps_and_clears_the_link(): void {
        $id = $this->evaluation();

        $this->send( 'PUT', 'evaluations/' . $id, [ 'activity_id' => $this->training_a ] );
        $this->assertSame( $this->training_a, (int) $this->row( $id )['activity_id'] );

        $this->send( 'PUT', 'evaluations/' . $id, [ 'notes' => 'Edited.' ] );
        $this->assertSame( $this->training_a, (int) $this->row( $id )['activity_id'], 'a PUT without the key leaves the link' );

        $this->send( 'PUT', 'evaluations/' . $id, [ 'activity_id' => 0 ] );
        $this->assertNull( $this->row( $id )['activity_id'], 'sent as 0, the link is removed' );
    }

    public function test_an_unknown_activity_is_refused_and_nothing_is_written(): void {
        [ $data, $status ] = $this->send( 'POST', 'evaluations', [
            'player_id'   => $this->player_a,
            'activity_id' => 987654,
            'eval_date'   => '2026-09-15',
        ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'bad_activity', $this->errorCode( $data ) );

        $id = $this->evaluation();
        [ $data, $status ] = $this->send( 'PUT', 'evaluations/' . $id, [ 'activity_id' => 987654, 'notes' => 'Should not land.' ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'bad_activity', $this->errorCode( $data ) );
        $this->assertNotSame( 'Should not land.', $this->row( $id )['notes'] );
    }

    public function test_a_coach_cannot_link_another_teams_activity(): void {
        $coach = $this->coachOf( $this->team_a );
        wp_set_current_user( $coach );

        [ $data, $status ] = $this->send( 'POST', 'evaluations', [
            'player_id'   => $this->player_a,
            'activity_id' => $this->training_b,
            'eval_date'   => '2026-09-15',
        ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'bad_activity', $this->errorCode( $data ) );

        [ , $status ] = $this->send( 'POST', 'evaluations', [
            'player_id'   => $this->player_a,
            'activity_id' => $this->training_a,
            'eval_date'   => '2026-09-15',
        ] );
        $this->assertSame( 200, $status, 'their own team\'s training is fine' );
    }

    public function test_an_admin_may_link_any_club_activity(): void {
        [ , $status ] = $this->send( 'POST', 'evaluations', [
            'player_id'   => $this->player_a,
            'activity_id' => $this->training_b,
            'eval_date'   => '2026-09-15',
        ] );
        $this->assertSame( 200, $status );
    }

    public function test_an_update_advances_updated_at(): void {
        global $wpdb;
        $id = $this->evaluation();
        $wpdb->update( "{$wpdb->prefix}tt_evaluations", [ 'updated_at' => '2020-01-01 00:00:00' ], [ 'id' => $id ] );

        $this->send( 'PUT', 'evaluations/' . $id, [ 'eval_date' => '2026-09-16' ] );

        $this->assertNotSame( '2020-01-01 00:00:00', (string) $this->row( $id )['updated_at'] );
    }

    private function activity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => (int) CurrentClub::id(),
            'team_id'             => $team_id,
            'title'               => 'Training',
            'session_date'        => '2026-09-15',
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function evaluation(): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id'   => (int) CurrentClub::id(),
            'player_id' => $this->player_a,
            'coach_id'  => $this->admin,
            'eval_date' => '2026-09-15',
            'notes'     => 'As first written.',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function coachOf( int $team_id ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Team',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        return $uid;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        $response = rest_get_server()->dispatch( $request );
        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }

    /** @param array<string,mixed> $data */
    private function errorCode( array $data ): ?string {
        return $data['errors'][0]['code'] ?? $data['code'] ?? null;
    }

    /** @return array<string,mixed> */
    private function row( int $id ): array {
        global $wpdb;
        return (array) $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_evaluations WHERE id = %d", $id ),
            ARRAY_A
        );
    }
}
