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
use TT\Modules\Journey\Frontend\FrontendCohortTransitionsView;

/**
 * #4794 — `GET /journey/cohort-transitions` follows the `cohort_transitions`
 * read right, the rule the Cohort transitions screen applies, and keeps the
 * feature switch. The settings view right plays no part.
 *
 * Real personas: an account on the role with a `tt_people` row, no
 * capability added to any user.
 */
final class CohortTransitionsRouteGateTest extends WP_UnitTestCase {

    private const ROUTE = '/talenttrack/v1/journey/cohort-transitions';

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        // The cohort surface ships switched off.
        FeatureRegistry::setEnabled( 'cohort_transitions', true );
        $this->flush();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        FeatureRegistry::setEnabled( 'cohort_transitions', false );
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * The observer is seeded the cohort read (#4795). An academy that
     * revokes it leaves a reader who still holds the settings view right,
     * and that right opens nothing here.
     */
    public function test_a_reader_without_the_cohort_read_is_refused_whatever_its_settings_rights(): void {
        global $wpdb;
        $observer = $this->persona( 'tt_readonly_observer' );
        wp_set_current_user( $observer );
        $this->assertSame( 200, $this->status(), 'precondition: the seeded observer reads the cohort' );

        $wpdb->delete( "{$wpdb->prefix}tt_authorization_matrix", [ 'persona' => 'readonly_observer', 'entity' => 'cohort_transitions' ] );
        $this->flush();
        $this->assertTrue( user_can( $observer, 'tt_view_settings' ), 'precondition: the seat holds the settings view right' );
        $this->assertSame( 403, $this->status() );
    }

    public function test_the_academy_admin_and_the_head_of_development_are_allowed(): void {
        wp_set_current_user( $this->persona( 'tt_club_admin' ) );
        $this->assertSame( 200, $this->status(), 'academy admin' );

        $hod = $this->persona( 'tt_head_dev' );
        wp_set_current_user( $hod );
        $this->assertSame( 200, $this->status(), 'head of development, who reads the entity' );
    }

    public function test_the_feature_switched_off_closes_the_route(): void {
        FeatureRegistry::setEnabled( 'cohort_transitions', false );
        $this->flush();

        wp_set_current_user( $this->persona( 'tt_club_admin' ) );
        $this->assertSame( 403, $this->status() );
    }

    /** The screen and its API give every seeded role the same answer. */
    public function test_the_view_and_the_route_agree_for_every_role(): void {
        foreach ( array_keys( ( new RolesService() )->roleDefinitions() ) as $role ) {
            $uid = $this->persona( $role );
            wp_set_current_user( $uid );

            $route_allows = $this->status() === 200;

            ob_start();
            FrontendCohortTransitionsView::render( $uid, false );
            $view_allows = strpos( (string) ob_get_clean(), 'You do not have access to cohort transitions.' ) === false;

            $this->assertSame( $view_allows, $route_allows, "role {$role}: the screen and the route disagree" );
        }
    }

    private function status(): int {
        $request = new WP_REST_Request( 'GET', self::ROUTE );
        $request->set_param( 'event_type', 'signed' );
        $request->set_param( 'from', '2026-01-01' );
        $request->set_param( 'to', '2026-12-31' );
        return (int) rest_do_request( $request )->get_status();
    }

    private function persona( string $role ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Cohort',
            'last_name'  => 'Gate' . $uid,
            'role_type'  => 'other',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $this->flush();
        return $uid;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }
}
