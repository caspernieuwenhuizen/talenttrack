<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\TeamDevelopment\Rest\PlayerAttributesRestController;
use TT\Modules\TeamDevelopment\Services\PlayerAttributeAudience;

/**
 * #4030 — a family does not read the academy's forecast for its child.
 *
 * `GET /players/{id}/attributes` was gated on `canViewPlayer` and returned
 * the catalogue whole. The catalogue's `development` group is Potential,
 * Development forecast and Ceiling estimate — the academy's judgement of
 * how far the child will go, which #3978 had already decided is staff-only
 * when it withheld the status verdict and the potential band from the
 * player and the guardian. This route never asked, so a parent opening
 * their own child's attributes was handed a ceiling estimate.
 *
 * These are minors' records, so the leak is asserted from both ends: the
 * audience rule, and the payload the REST handler actually returns.
 *
 * The refusals are paired with grants throughout. A test that only
 * asserted absence would pass just as well against a route that returned
 * nothing to anybody.
 */
final class PlayerAttributeAudienceTest extends WP_UnitTestCase {

    private string $p       = '';
    private int $teamId     = 0;
    private int $playerId   = 0;
    private int $physicalId = 0;
    private int $forecastId = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->query( "DELETE FROM {$this->p}tt_player_parents" );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => 1, 'name' => 'Forecast U13' ] );
        $this->teamId = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => 1,
            'team_id'    => $this->teamId,
            'first_name' => 'Forecast',
            'last_name'  => 'Subject',
            'status'     => 'active',
        ] );
        $this->playerId = (int) $wpdb->insert_id;

        // Own defs rather than the migration's seed, so the test states
        // what it needs instead of depending on catalogue content.
        $this->physicalId = $this->def( 'physical', 'tt4030_pace', 'Pace' );
        $this->forecastId = $this->def( 'development', 'tt4030_forecast', 'Development forecast' );

        $this->value( $this->physicalId, 70 );
        $this->value( $this->forecastId, 88 );
    }

    private function def( string $group, string $key, string $label ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_attribute_defs", [
            'club_id'    => 1,
            'uuid'       => wp_generate_uuid4(),
            'attr_group' => $group,
            'attr_key'   => $key,
            'label'      => $label,
            'min_value'  => 0,
            'max_value'  => 100,
            'sort_order' => 9000,
            'is_active'  => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function value( int $def_id, int $value ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_attribute_values", [
            'club_id'          => 1,
            'uuid'             => wp_generate_uuid4(),
            'player_id'        => $this->playerId,
            'attribute_def_id' => $def_id,
            'value'            => $value,
        ] );
    }

    private function parent_of_the_player(): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$this->p}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $this->playerId,
            'parent_user_id' => $uid,
            'is_primary'     => 1,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function head_coach_of_the_team(): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Forecast',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->teamId,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    /** The payload the route hands this user. */
    private function groupsFor( int $user_id ): array {
        wp_set_current_user( $user_id );
        $request = new \WP_REST_Request( 'GET', "/talenttrack/v1/players/{$this->playerId}/attributes" );
        $request->set_param( 'player_id', $this->playerId );

        $response = PlayerAttributesRestController::get_attributes( $request );
        $data     = $response->get_data();
        wp_set_current_user( 0 );

        return is_array( $data ) && isset( $data['groups'] ) && is_array( $data['groups'] )
            ? $data['groups']
            : [];
    }

    // -----------------------------------------------------------------
    // the audience rule
    // -----------------------------------------------------------------

    public function test_a_guardian_may_not_read_the_forecast(): void {
        $this->assertFalse(
            PlayerAttributeAudience::canReadDevelopment( $this->parent_of_the_player(), $this->playerId ),
            'no family persona holds player_potential in the seed'
        );
    }

    public function test_an_academy_admin_may_read_the_forecast(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->assertTrue(
            PlayerAttributeAudience::canReadDevelopment( $admin, $this->playerId ),
            'the grant exists at global scope; without this the test below proves nothing'
        );
    }

    public function test_the_head_coach_of_the_team_may_read_the_forecast(): void {
        $this->assertTrue(
            PlayerAttributeAudience::canReadDevelopment( $this->head_coach_of_the_team(), $this->playerId ),
            'the head coach sets potential for their own squad'
        );
    }

    public function test_the_rule_refuses_nonsense_ids(): void {
        $this->assertFalse( PlayerAttributeAudience::canReadDevelopment( 0, $this->playerId ) );
        $this->assertFalse( PlayerAttributeAudience::canReadDevelopment( 1, 0 ) );
    }

    // -----------------------------------------------------------------
    // the payload
    // -----------------------------------------------------------------

    public function test_the_payload_withholds_the_forecast_from_a_guardian(): void {
        $groups = $this->groupsFor( $this->parent_of_the_player() );

        $this->assertArrayHasKey(
            'physical',
            $groups,
            'fixture sanity: the guardian still reads the observed attributes'
        );
        $this->assertArrayNotHasKey(
            PlayerAttributeAudience::DEVELOPMENT_GROUP,
            $groups,
            'potential, development forecast and ceiling estimate are the academy\'s judgement of a minor'
        );
    }

    /**
     * The group is dropped, not blanked. A `null` score would read as
     * "not recorded yet", and an empty group would still tell the reader
     * a forecast exists.
     */
    public function test_the_withheld_group_leaves_no_trace_in_the_payload(): void {
        $groups = $this->groupsFor( $this->parent_of_the_player() );

        $encoded = (string) wp_json_encode( $groups );
        $this->assertStringNotContainsString( 'tt4030_forecast', $encoded );
        $this->assertStringNotContainsString( 'Development forecast', $encoded, 'nor the label that names it' );
    }

    public function test_the_payload_keeps_the_forecast_for_staff(): void {
        $groups = $this->groupsFor( $this->head_coach_of_the_team() );

        $this->assertArrayHasKey(
            PlayerAttributeAudience::DEVELOPMENT_GROUP,
            $groups,
            'staff who set potential must still be able to read it'
        );
        $keys = array_column( $groups[ PlayerAttributeAudience::DEVELOPMENT_GROUP ], 'attr_key' );
        $this->assertContains( 'tt4030_forecast', $keys );
    }
}
