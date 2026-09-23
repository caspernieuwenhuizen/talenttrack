<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Teams\Services\TeamStaffPrompt;
use TT\Shared\Frontend\FlashMessages;

/**
 * #4007 — a team created with nobody running it says so.
 *
 * Team 76 was created in April with no staff and nothing pointed it out.
 * Almost every notification in the plugin is addressed to a team's head
 * coach, so a team without one stops receiving any of them; the standing
 * alert deliberately skips teams with no players, which is exactly the shape
 * a brand-new team has.
 */
final class TeamStaffPromptTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        FlashMessages::consume();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_creating_a_team_without_staff_prompts_for_a_head_coach(): void {
        $fired = [];
        add_action( TeamStaffPrompt::CREATED_HOOK, static function ( $team_id ) use ( &$fired ): void {
            $fired[] = (int) $team_id;
        } );

        $created = $this->post( 'teams', [ 'name' => 'Prompt JO14-1', 'age_group' => 'U14' ] );

        $this->assertGreaterThan( 0, (int) $created['data']['id'] );
        $this->assertSame( [ (int) $created['data']['id'] ], $fired, 'the create path fires the post-insert hook' );
        $this->assertTrue( $created['data']['needs_head_coach'] );

        $messages = FlashMessages::consume();
        $this->assertCount( 1, $messages );
        $this->assertSame( FlashMessages::TYPE_WARNING, $messages[0]['type'] );
        $this->assertStringContainsString( 'Prompt JO14-1', $messages[0]['message'] );
        $this->assertStringContainsString( 'head coach', $messages[0]['message'] );
    }

    public function test_a_team_that_has_a_head_coach_is_not_prompted(): void {
        $created = $this->post( 'teams', [ 'name' => 'Coached JO15-1' ] );
        $team_id = (int) $created['data']['id'];
        FlashMessages::consume();

        $this->assignHeadCoach( $team_id );

        $this->assertTrue( TeamStaffPrompt::hasHeadCoach( $team_id ) );
        TeamStaffPrompt::afterCreate( $team_id, 'Coached JO15-1' );
        $this->assertSame( [], FlashMessages::consume(), 'nothing to prompt about' );
    }

    /** An assignment that ended is not this team's head coach today. */
    public function test_an_ended_assignment_does_not_count(): void {
        global $wpdb;
        $created = $this->post( 'teams', [ 'name' => 'Lapsed JO16-1' ] );
        $team_id = (int) $created['data']['id'];
        FlashMessages::consume();

        $assignment = $this->assignHeadCoach( $team_id );
        $wpdb->update( "{$wpdb->prefix}tt_team_people", [ 'end_date' => '2020-06-30' ], [ 'id' => $assignment ] );

        $this->assertFalse( TeamStaffPrompt::hasHeadCoach( $team_id ) );
    }

    /** An archived person is not running anything either. */
    public function test_an_archived_head_coach_does_not_count(): void {
        global $wpdb;
        $created = $this->post( 'teams', [ 'name' => 'Archived JO17-1' ] );
        $team_id = (int) $created['data']['id'];
        FlashMessages::consume();

        $this->assignHeadCoach( $team_id, true );

        $this->assertFalse( TeamStaffPrompt::hasHeadCoach( $team_id ) );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return int the tt_team_people row id */
    private function assignHeadCoach( int $team_id, bool $archived = false ): int {
        global $wpdb;
        $club = (int) CurrentClub::id();

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s LIMIT 1",
            'head_coach'
        ) );
        if ( $role_id <= 0 ) {
            // No club_id: `role_key` carries a global unique index and the
            // tenancy column arrived later, so the seed stays column-minimal.
            $wpdb->insert( "{$wpdb->prefix}tt_functional_roles", [
                'role_key' => 'head_coach', 'label' => 'Head coach',
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'     => $club,
            'first_name'  => 'Hoofd',
            'last_name'   => 'Coach',
            'role_type'   => 'staff',
            'archived_at' => $archived ? current_time( 'mysql' ) : null,
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id'            => $club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
            'is_head_coach'      => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function post( string $route, array $body ): array {
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/' . $route );
        $request->set_body_params( $body );
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status(), 'the team is created' );
        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return is_array( $data ) ? $data : [];
    }
}
