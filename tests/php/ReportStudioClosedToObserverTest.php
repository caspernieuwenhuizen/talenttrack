<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\SharedWithMe;
use TT\Modules\ReportStudio\Frontend\FrontendReportView;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4695 — Report Studio is closed to the read-only observer.
 *
 * Real personas on a matrix-active install: an account on the role and a
 * `tt_people` row, no capability added to any user. The observer holds
 * `reports` read club-wide, which every Studio read route used to ask; it
 * now also asks `report_studio` read, which the observer never holds and the
 * team manager holds at team scope.
 */
final class ReportStudioClosedToObserverTest extends WP_UnitTestCase {

    // Publishing commits, so every test cleans up what it wrote.
    use CommitsAfterCascade;

    private string $matrix_was = '';

    /** @var list<array<string,mixed>> */
    private array $tiles_were = [];

    private int $team     = 0;
    private int $hod      = 0;
    private int $coach    = 0;
    private int $manager  = 0;
    private int $observer = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        if ( get_role( 'tt_team_manager' ) === null ) {
            add_role( 'tt_team_manager', 'Team Manager', [ 'read' => true ] );
        }

        $this->tiles_were = TileRegistry::allRegistered();
        TileRegistry::clear();
        \TT\Shared\CoreSurfaceRegistration::register();
        // The Studio's tile is registered by its module at boot, not by the
        // core surfaces, so register it here for the tile assertions.
        \TT\Modules\ReportStudio\ReportStudioModule::registerTile();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Studio U14' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Studio', 'last_name' => 'Player', 'status' => 'active',
        ] );

        $this->hod      = $this->persona( 'tt_head_dev', 'head_of_development', 0 );
        $this->coach    = $this->persona( 'tt_coach', 'head_coach', $this->team );
        $this->manager  = $this->persona( 'tt_team_manager', 'team_manager', $this->team );
        $this->observer = $this->persona( 'tt_readonly_observer', 'other', 0 );

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        $this->flush();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        unset( $_GET['id'] );
        // Before the cleanup commits, so the restored value is what stays.
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        TileRegistry::clear();
        foreach ( $this->tiles_were as $tile ) {
            TileRegistry::register( $tile );
        }
        $this->cleanUpAndCommit();
        $this->flush();
        parent::tear_down();
    }

    public function test_the_observer_is_refused_on_every_studio_route(): void {
        $id = $this->published( [ 'head_coach', 'team_manager' ] );

        wp_set_current_user( $this->observer );
        $this->assertTrue( current_user_can( 'tt_view_reports' ), 'Precondition: the observer reads reports.' );
        $this->assertFalse( ReportReader::for( $this->observer )->readsStudio() );

        foreach ( [ 'reports', "reports/{$id}", "reports/{$id}/data", "reports/{$id}/subjects", 'report-blocks' ] as $route ) {
            $this->assertSame( 403, $this->send( 'GET', $route )->get_status(), "GET {$route}" );
        }
        $this->assertSame( 403, $this->send( 'POST', 'report-blocks/attendance_per_week/preview', [
            'context' => [ 'scope' => 'team', 'team_id' => $this->team, 'from' => '2020-03-01', 'to' => '2020-03-31' ],
        ] )->get_status(), 'POST preview' );
        foreach ( [ [ 'POST', 'reports' ], [ 'GET', 'reports/start-options' ], [ 'GET', 'reports/subject-options' ] ] as [ $method, $route ] ) {
            $this->assertSame( 403, $this->send( $method, $route )->get_status(), "{$method} {$route}" );
        }
    }

    public function test_the_observer_sees_no_shared_report_no_viewer_and_no_tile(): void {
        $id = $this->published( [ 'head_coach', 'team_manager' ] );

        $this->assertSame( [], SharedWithMe::forReader( ReportReader::for( $this->observer ) ) );
        $this->assertStringNotContainsString( 'studio-report-', $this->renderLauncher( $this->observer ) );

        $page = $this->renderView( $this->observer, $id );
        $this->assertStringContainsString( 'tt-breadcrumbs', $page );
        $this->assertStringNotContainsString( 'data-tt-rv', $page );

        wp_set_current_user( $this->observer );
        $slugs = $this->tileSlugs( $this->observer );
        $this->assertNotContains( 'report-studio', $slugs, 'no Report Studio tile' );
    }

    public function test_a_report_cannot_be_published_to_the_observer(): void {
        $this->assertNotContains( 'readonly_observer', ReportAccess::PUBLISHABLE_PERSONAS );

        $id = $this->draft();
        wp_set_current_user( $this->hod );
        $res = $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'First', 'persona_keys' => [ 'readonly_observer' ] ] );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'invalid_field', $this->code( $res ) );

        $reach = $this->send( 'GET', "reports/{$id}/reach", [ 'persona_keys' => 'readonly_observer' ] );
        $this->assertNotSame( 200, $reach->get_status(), 'nor counted for the publish confirm' );
    }

    public function test_the_team_manager_still_reads_a_report_published_to_them(): void {
        $id = $this->published( [ 'head_coach', 'team_manager' ] );

        wp_set_current_user( $this->manager );
        $this->assertTrue( ReportReader::for( $this->manager )->readsStudio() );
        $this->assertFalse( current_user_can( 'tt_build_reports' ), 'Precondition: the team manager builds no report.' );

        $this->assertContains( $id, array_column( $this->payload( $this->send( 'GET', 'reports' ) )['rows'] ?? [], 'id' ) );
        $this->assertSame( 200, $this->send( 'GET', "reports/{$id}" )->get_status() );
        $this->assertSame( 200, $this->send( 'GET', "reports/{$id}/data", [ 'source' => 'published', 'team_id' => $this->team, 'from' => '2020-03-01', 'to' => '2020-03-31' ] )->get_status() );
        $this->assertSame( 200, $this->send( 'GET', "reports/{$id}/subjects" )->get_status() );
        $this->assertSame( 200, $this->send( 'GET', 'report-blocks' )->get_status(), 'the viewer names its blocks from here' );
        $this->assertSame( 403, $this->send( 'POST', 'report-blocks/attendance_per_week/preview', [
            'context' => [ 'scope' => 'team', 'team_id' => $this->team, 'from' => '2020-03-01', 'to' => '2020-03-31' ],
        ] )->get_status(), 'previewing a block is the canvas\'s' );

        $this->assertContains( $id, array_column( SharedWithMe::forReader( ReportReader::for( $this->manager ) ), 'id' ) );
        $this->assertStringContainsString( 'data-tt-rv', $this->renderView( $this->manager, $id ) );
    }

    /**
     * The builder tile is offered only to whoever the canvas answers: Studio
     * read alone, the team manager's in either shape, does not offer it.
     */
    public function test_the_studio_tile_is_offered_to_builders_only(): void {
        $staff_manager = $this->staffWithManagerRole();
        $this->assertTrue( ReportReader::for( $staff_manager )->readsStudio(), 'Precondition: the Manager role reads the Studio.' );
        $this->assertTrue( ReportReader::for( $this->manager )->readsStudio(), 'Precondition: the team manager reads the Studio.' );

        $tile = function ( int $uid ): bool {
            wp_set_current_user( $uid );
            return in_array( 'report-studio', $this->tileSlugs( $uid ), true );
        };
        $this->assertTrue( $tile( $this->coach ), 'the head coach builds' );
        $this->assertTrue( $tile( $this->hod ), 'the head of development builds' );
        $this->assertFalse( $tile( $this->manager ), 'the team manager persona reads and does not build' );
        $this->assertFalse( $tile( $staff_manager ), 'the Manager functional role reads and does not build' );
        $this->assertFalse( $tile( $this->observer ), 'the observer neither reads nor builds' );
    }

    public function test_builders_are_unchanged(): void {
        $id = $this->published( [ 'head_coach' ] );

        foreach ( [ 'head coach' => $this->coach, 'head of development' => $this->hod ] as $who => $uid ) {
            wp_set_current_user( $uid );
            $this->assertSame( 200, $this->send( 'GET', "reports/{$id}" )->get_status(), "{$who}: GET" );
            $this->assertSame( 200, $this->send( 'GET', 'report-blocks' )->get_status(), "{$who}: blocks" );
            $this->assertSame( 200, $this->send( 'POST', 'report-blocks/attendance_per_week/preview', [
                'context' => [ 'scope' => 'team', 'team_id' => $this->team, 'from' => '2020-03-01', 'to' => '2020-03-31' ],
            ] )->get_status(), "{$who}: preview" );
        }
        $this->assertContains( $id, array_column( SharedWithMe::forReader( ReportReader::for( $this->coach ) ), 'id' ) );
    }

    // ---- helpers ------------------------------------------------------------------

    /** A team report by the head of development, its draft holding one block. */
    private function draft(): int {
        wp_set_current_user( $this->hod );
        $res = $this->send( 'POST', 'reports', [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role', 'name' => 'Studio pack' ] );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $id = (int) $res->get_data()['data']['id'];
        $this->assertSame( 200, $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'id' => 'b1', 'key' => 'attendance_per_week' ] ] ] ] )->get_status() );
        return $id;
    }

    /** @param list<string> $personas */
    private function published( array $personas ): int {
        $id = $this->draft();
        wp_set_current_user( $this->hod );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'First', 'persona_keys' => $personas ] )->get_status() );
        wp_set_current_user( 0 );
        return $id;
    }

    private function persona( string $role, string $role_type, int $team ): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $uid  = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Studio', 'last_name' => 'Persona' . $uid,
            'role_type' => $role_type, 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        if ( $team > 0 ) {
            $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
                'club_id' => $club, 'team_id' => $team, 'person_id' => $person,
                'role_in_team' => $role_type, 'is_head_coach' => $role_type === 'head_coach' ? 1 : 0,
            ] );
            $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
                'club_id' => $club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
            ] );
        }
        $this->flush();
        return $uid;
    }

    /** @return list<string> the view slugs of every tile this user is offered */
    private function tileSlugs( int $uid ): array {
        $this->flush();
        $buckets = TileRegistry::tilesForUser( $uid );
        return array_values( array_map( 'strval', array_column( array_merge( $buckets['work'], $buckets['setup'] ), 'view_slug' ) ) );
    }

    /** A `staff` account holding the Manager functional role on the team. */
    private function staffWithManagerRole(): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $uid  = (int) self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Studio', 'last_name' => 'Manager' . $uid,
            'role_type' => 'staff', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;

        $role = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            'manager', $club
        ) );
        if ( $role <= 0 ) {
            $wpdb->insert( "{$wpdb->prefix}tt_functional_roles", [ 'club_id' => $club, 'role_key' => 'manager', 'label' => 'Manager' ] );
            $role = (int) $wpdb->insert_id;
        }
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id' => $club, 'team_id' => $this->team, 'person_id' => $person,
            'functional_role_id' => $role, 'role_in_team' => 'manager',
        ] );
        $this->flush();
        return $uid;
    }

    private function renderView( int $user, int $id ): string {
        wp_set_current_user( $user );
        $this->flush();
        $_GET['id'] = (string) $id;
        ob_start();
        FrontendReportView::render( $user, false );
        return (string) ob_get_clean();
    }

    private function renderLauncher( int $user ): string {
        wp_set_current_user( $user );
        $this->flush();
        ob_start();
        \TT\Shared\Frontend\FrontendReportsLauncherView::render( $user, false );
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $params */
    private function send( string $method, string $route, array $params = [] ): WP_REST_Response {
        $req = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $method === 'GET' ) {
            if ( $params !== [] ) $req->set_query_params( $params );
        } else {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( (string) wp_json_encode( (object) $params ) );
        }
        $this->flush();
        return rest_do_request( $req );
    }

    /** @return array<string,mixed> */
    private function payload( WP_REST_Response $res ): array {
        $body = (array) $res->get_data();
        return (array) ( $body['data'] ?? [] );
    }

    private function code( WP_REST_Response $res ): string {
        return (string) ( $res->get_data()['errors'][0]['code'] ?? '' );
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        \TT\Modules\Authorization\MatrixGate::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    private function resetMatrixActiveCaches(): void {
        foreach ( [ TileRegistry::class, DashboardShortcode::class ] as $class ) {
            $prop = new \ReflectionProperty( $class, 'matrix_active_cache' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    }
}
