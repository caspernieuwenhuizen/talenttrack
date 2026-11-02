<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;

/**
 * #4591 — before the current season starts, the "this season" period pill
 * on `GET /reports/{id}/data` answers as an omitted period does: 422
 * no_period (#4637). Nothing here publishes, so the season fixture stays in
 * the test's transaction.
 */
final class ReportPeriodPillTest extends WP_UnitTestCase {

    private int $hod = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $wpdb->update( "{$wpdb->prefix}tt_seasons", [ 'is_current' => 0 ], [ 'club_id' => CurrentClub::id() ] );
        $wpdb->insert( "{$wpdb->prefix}tt_seasons", [
            'club_id' => CurrentClub::id(), 'name' => 'Not yet', 'start_date' => gmdate( 'Y-m-d', (int) strtotime( '+30 days' ) ),
            'end_date' => gmdate( 'Y-m-d', (int) strtotime( '+330 days' ) ), 'is_current' => 1,
        ] );

        $this->hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();
        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->hod );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_this_season_before_it_starts_is_the_same_422_as_no_period(): void {
        $create = new WP_REST_Request( 'POST', '/talenttrack/v1/reports' );
        $create->set_header( 'Content-Type', 'application/json' );
        $create->set_body( (string) wp_json_encode( [ 'scope' => 'academy', 'name' => 'Before the season' ] ) );
        $res = rest_do_request( $create );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $id = (int) $res->get_data()['data']['id'];

        $omitted = $this->data( $id, [] );
        $pill    = $this->data( $id, [ 'period' => 'this_season' ] );
        $this->assertSame( 422, $omitted->get_status(), 'Precondition: no period before the season is 422.' );
        $this->assertSame( 422, $pill->get_status() );
        $this->assertSame( 'no_period', $pill->get_data()['errors'][0]['code'] ?? null );
        $this->assertSame( $omitted->get_data()['errors'][0]['message'] ?? null, $pill->get_data()['errors'][0]['message'] ?? null, 'the same answer' );

        $this->assertSame( 200, $this->data( $id, [ 'period' => 'last_month' ] )->get_status(), 'a pill with a window still reads' );
    }

    /** @param array<string,string> $query */
    private function data( int $id, array $query ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', "/talenttrack/v1/reports/{$id}/data" );
        if ( $query !== [] ) $req->set_query_params( $query );
        return rest_do_request( $req );
    }
}
