<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;

/**
 * The consent log of a prospect answers the same "not found" as the
 * prospect itself for a caller outside that prospect's scope.
 */
final class ConsentRequestScopeTest extends WP_UnitTestCase {

    private int $coach   = 0;
    private int $outside = 0;
    private int $own     = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Consent-scope U14', 'age_group' => 'U14' ] );
        $this->coach = $this->makeHeadCoach( (int) $wpdb->insert_id );

        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [ 'club_id' => 1, 'first_name' => 'Far', 'last_name' => 'Awayx' ] );
        $this->outside = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id' => 1, 'first_name' => 'Own', 'last_name' => 'Findx', 'discovered_by_user_id' => $this->coach,
        ] );
        $this->own = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_head_coach_outside_the_scope_gets_not_found(): void {
        $this->assertTrue( AuthorizationService::userCanOrMatrix( $this->coach, 'tt_view_prospects' ), 'precondition: the refusal comes from the scope, not the right' );
        $entry = $this->entryFor( $this->outside );

        wp_set_current_user( $this->coach );
        $this->assertSame( 404, $this->list( $this->outside )->get_status() );

        // The head coach holds no write right on prospects; whatever the
        // writes answer, they leave the log as it was.
        $this->assertNotContains( $this->create( $this->outside )->get_status(), [ 200, 201 ] );
        $this->assertNotContains( $this->patch( $this->outside, $entry )->get_status(), [ 200, 201 ] );
        $this->assertSame( ConsentOutcome::AWAITING, ( new ProspectConsentRequestsRepository() )->findById( $entry )['outcome'] ?? null, 'nothing changed' );
        $this->assertCount( 1, ( new ProspectConsentRequestsRepository() )->forProspect( $this->outside ), 'nothing added' );
    }

    public function test_the_same_head_coach_inside_the_scope_still_reads_the_log(): void {
        $this->entryFor( $this->own );

        wp_set_current_user( $this->coach );
        $res = $this->list( $this->own );
        $this->assertSame( 200, $res->get_status() );
        $this->assertCount( 1, (array) ( $res->get_data()['data']['requests'] ?? $res->get_data()['requests'] ?? [] ) );
    }

    public function test_an_editor_outside_the_scope_gets_not_found_on_the_writes(): void {
        // A scoped editor does not exist in the shipped seed; give the head
        // coach the edit right alone, so the scope is the only thing between
        // them and the log.
        add_filter( 'user_has_cap', [ $this, 'grantEdit' ], 999, 4 );
        $entry = $this->entryFor( $this->outside );

        wp_set_current_user( $this->coach );
        $this->assertTrue( AuthorizationService::userCanOrMatrix( $this->coach, 'tt_edit_prospects' ), 'precondition: the edit right is held' );
        $this->assertSame( 404, $this->create( $this->outside )->get_status() );
        $this->assertSame( 404, $this->patch( $this->outside, $entry )->get_status() );
        $this->assertSame( 201, $this->create( $this->own )->get_status(), 'inside the scope the write still works' );

        remove_filter( 'user_has_cap', [ $this, 'grantEdit' ], 999 );
    }

    /**
     * @param array<string,bool> $allcaps
     * @return array<string,bool>
     */
    public function grantEdit( array $allcaps, array $caps, array $args, \WP_User $user ): array {
        if ( (int) $user->ID === $this->coach ) $allcaps['tt_edit_prospects'] = true;
        return $allcaps;
    }

    public function test_the_head_of_development_is_unchanged(): void {
        $entry = $this->entryFor( $this->outside );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_head_dev' ] ) );
        AuthorizationService::flushCache();
        $this->assertSame( 200, $this->list( $this->outside )->get_status() );
        $this->assertSame( 201, $this->create( $this->outside )->get_status() );
        $this->assertSame( 200, $this->patch( $this->outside, $entry )->get_status() );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function entryFor( int $prospect_id ): int {
        $id = ( new ProspectConsentRequestsRepository() )->create( $prospect_id, current_time( 'Y-m-d' ), 'Youth coordinator', ConsentOutcome::AWAITING );
        $this->assertGreaterThan( 0, $id );
        return $id;
    }

    private function list( int $prospect_id ): \WP_REST_Response {
        return rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/prospects/' . $prospect_id . '/consent-requests' ) );
    }

    private function create( int $prospect_id ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/prospects/' . $prospect_id . '/consent-requests' );
        $req->set_body_params( [ 'asked_at' => current_time( 'Y-m-d' ), 'asked_of' => 'Youth coordinator' ] );
        return rest_do_request( $req );
    }

    private function patch( int $prospect_id, int $entry_id ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PATCH', '/talenttrack/v1/prospects/' . $prospect_id . '/consent-requests/' . $entry_id );
        $req->set_body_params( [ 'outcome' => ConsentOutcome::AGREED ] );
        return rest_do_request( $req );
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Hoofd', 'last_name' => 'Trainer',
            'role_type' => 'head_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'team_id' => $team_id, 'person_id' => $person_id, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }
}
