<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Rest\ReportBlocksRestController;

/**
 * #4568 — `GET /report-blocks` and `POST /report-blocks/{key}/preview`: the
 * plan refusal, the filter contract, the body contract and the subject
 * refusal, at the REST boundary.
 */
final class ReportBlocksRestTest extends WP_UnitTestCase {

    private int $team_a = 0;
    private int $team_b = 0;
    private int $manager = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->flush();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Rest U12' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Rest U15' ] );
        $this->team_b = (int) $wpdb->insert_id;

        if ( get_role( 'tt_team_manager' ) === null ) add_role( 'tt_team_manager', 'team_manager', [ 'read' => true ] );
        $this->manager = (int) self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Rest', 'last_name' => 'Manager', 'role_type' => 'team_manager',
            'wp_user_id' => $this->manager, 'status' => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $club, 'person_id' => (int) $wpdb->insert_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team_a,
        ] );
        $this->flush();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->manager );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        $this->flush();
        parent::tear_down();
    }

    public function test_the_list_names_blocks_capabilities_and_no_roles(): void {
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/report-blocks' ) );
        $this->assertSame( 200, $res->get_status() );
        $data = $res->get_data()['data'];
        $keys = array_column( $data['blocks'], 'key' );
        $this->assertContains( 'playing_time', $keys );
        $this->assertContains( 'attendance_per_week', $keys );
        $json = (string) wp_json_encode( $data );
        $this->assertStringNotContainsString( 'tt_team_manager', $json );
        $this->assertStringNotContainsString( 'team_manager', $json );
    }

    public function test_scope_and_audience_are_taken_plainly_or_as_filter(): void {
        $plain = new WP_REST_Request( 'GET', '/talenttrack/v1/report-blocks' );
        $plain->set_param( 'scope', 'team' );
        $this->assertSame( 200, rest_do_request( $plain )->get_status() );

        $nested = new WP_REST_Request( 'GET', '/talenttrack/v1/report-blocks' );
        $nested->set_param( 'filter', [ 'scope' => 'player' ] );
        $res = rest_do_request( $nested );
        $this->assertSame( 200, $res->get_status() );
        $this->assertNotContains( 'playing_time', array_column( $res->get_data()['data']['blocks'], 'key' ), 'a team-only block offered for a player' );

        foreach ( [ 'scope' => 'everyone', 'audience' => 'press' ] as $param => $value ) {
            $bad = new WP_REST_Request( 'GET', '/talenttrack/v1/report-blocks' );
            $bad->set_param( 'filter', [ $param => $value ] );
            $res = rest_do_request( $bad );
            $this->assertSame( 400, $res->get_status(), $param );
            $this->assertSame( 'bad_filter', $res->get_data()['errors'][0]['code'] );
        }
    }

    public function test_both_routes_answer_402_without_the_plan_feature(): void {
        LicenseGate::overrideTierForTests( FeatureMap::TIER_STANDARD );

        $list = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/report-blocks' ) );
        $this->assertSame( 402, $list->get_status() );
        $this->assertSame( 'license_required', $list->get_data()['errors'][0]['code'] );

        $preview = $this->preview( 'attendance_per_week', [ 'context' => [ 'scope' => 'team', 'team_id' => $this->team_a ] ] );
        $this->assertSame( 402, $preview->get_status() );
    }

    public function test_the_feature_switched_off_refuses(): void {
        FeatureRegistry::setEnabled( 'report_studio', false );
        $this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/report-blocks' ) )->get_status() );
    }

    public function test_a_preview_of_another_teams_subject_is_403(): void {
        $res = $this->preview( 'attendance_per_week', [ 'context' => [ 'scope' => 'team', 'team_id' => $this->team_b, 'from' => '2020-03-01', 'to' => '2020-03-31' ] ] );
        $this->assertSame( 403, $res->get_status() );
        $this->assertSame( 'forbidden_team', $res->get_data()['errors'][0]['code'] );

        $own = $this->preview( 'attendance_per_week', [ 'context' => [ 'scope' => 'team', 'team_id' => $this->team_a, 'from' => '2020-03-01', 'to' => '2020-03-31' ] ] );
        $this->assertSame( 200, $own->get_status() );
        $this->assertSame( 'attendance_per_week', $own->get_data()['data']['block'] );
    }

    public function test_an_unknown_block_is_404(): void {
        $res = $this->preview( 'no_such_block', [ 'context' => [ 'scope' => 'team', 'team_id' => $this->team_a ] ] );
        $this->assertSame( 404, $res->get_status() );
        $this->assertSame( 'not_found', $res->get_data()['errors'][0]['code'] );
    }

    public function test_the_body_contract(): void {
        $unknown = $this->preview( 'attendance_per_week', [ 'context' => [ 'scope' => 'team', 'team_id' => $this->team_a ], 'save' => true ] );
        $this->assertSame( 400, $unknown->get_status() );
        $this->assertSame( 'unknown_field', $unknown->get_data()['errors'][0]['code'] );

        $option = $this->preview( 'attendance_per_week', [ 'context' => [ 'scope' => 'team', 'team_id' => $this->team_a ], 'options' => [ 'colour' => 'red' ] ] );
        $this->assertSame( 400, $option->get_status() );
        $this->assertSame( 'unknown_field', $option->get_data()['errors'][0]['code'] );

        $large = $this->preview( 'attendance_per_week', [ 'context' => [ 'scope' => 'team', 'team_id' => $this->team_a, 'pad' => str_repeat( 'x', ReportBlocksRestController::MAX_BODY_BYTES ) ] ] );
        $this->assertSame( 413, $large->get_status() );
        $this->assertSame( 'body_too_large', $large->get_data()['errors'][0]['code'] );
    }

    public function test_a_preview_writes_nothing(): void {
        global $wpdb;
        $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log" );
        $this->preview( 'attendance_per_week', [ 'context' => [ 'scope' => 'team', 'team_id' => $this->team_a ] ] );
        $this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log" ) );
    }

    /**
     * @param array<string,mixed> $body
     */
    private function preview( string $key, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/report-blocks/' . $key . '/preview' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }
}
