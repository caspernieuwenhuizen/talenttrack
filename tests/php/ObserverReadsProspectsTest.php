<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Prospects\Frontend\FrontendProspectDetailView;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4694 (a) — the read-only observer reads the prospects, with the family
 * contact, the scouting notes and the consent trail masked; changes
 * nothing; and has no access to scouting visits. The scout, the head of
 * development and a head coach reading their own age group see everything
 * as before.
 */
final class ObserverReadsProspectsTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0331_authorization_observer_reads_prospects.php';

    private const NOTES    = 'Two-footed, reads the game early.';
    private const ASKED_OF = 'Youth coordinator VV Noord';

    private string $matrix_was = '';
    private int $observer = 0;
    private int $scout    = 0;
    private int $hod      = 0;
    private int $coach    = 0;
    private int $prospect = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();
        $this->migration()->up();
        $this->flush();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( "{$p}tt_lookups", [ 'club_id' => $club, 'lookup_type' => 'age_group', 'name' => 'U13x' ] );
        $age_group = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Obspros U13', 'age_group' => 'U13x' ] );
        $team = (int) $wpdb->insert_id;

        $this->observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->scout    = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->hod      = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->coach    = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Obspros', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $club, 'team_id' => $team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );

        $wpdb->insert( "{$p}tt_prospects", [
            'club_id'               => $club,
            'first_name'            => 'Jesse',
            'last_name'             => 'Obsprosx',
            'discovered_at'         => current_time( 'Y-m-d' ),
            'discovered_by_user_id' => $this->scout,
            'age_group_lookup_id'   => $age_group,
            'current_club'          => 'VV Noord',
            'date_of_birth'         => '2014-05-06',
            'scouting_notes'        => self::NOTES,
            'parent_name'           => 'M. Obsprosx',
            'parent_email'          => 'parent@example.test',
            'parent_phone'          => '0612345678',
        ] );
        $this->prospect = (int) $wpdb->insert_id;
        ( new ProspectConsentRequestsRepository() )->create( $this->prospect, current_time( 'Y-m-d' ), self::ASKED_OF, 'awaiting', 'Father asked for a call first.' );
        $this->flush();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_seed_grants_prospects_and_test_trainings_and_not_the_visits(): void {
        $this->assertTrue( MatrixGate::can( $this->observer, 'prospects', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) );
        // Recorded for the owner's decision; no route asks `test_trainings`
        // read yet, so this opens nothing today.
        $this->assertTrue( MatrixGate::can( $this->observer, 'test_trainings', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) );
        foreach ( [ 'scouting_visits_panel', 'scout_history', 'scout_access', 'scout_my_players' ] as $entity ) {
            $this->assertFalse( MatrixGate::canAnyScope( $this->observer, $entity, 'read' ), "{$entity} stays closed" );
        }
    }

    public function test_the_observer_reads_the_prospect_without_contact_or_notes(): void {
        $prospect = $this->prospectAs( $this->observer );

        $this->assertSame( 'Jesse', $prospect['first_name'] ?? null );
        $this->assertSame( 'VV Noord', $prospect['current_club'] ?? null );
        foreach ( [ 'parent_name', 'parent_email', 'parent_phone', 'scouting_notes' ] as $field ) {
            $this->assertArrayNotHasKey( $field, $prospect, "{$field} is masked" );
        }
    }

    public function test_the_observer_receives_only_the_allowed_keys(): void {
        $allowed = array_merge( \TT\Modules\Prospects\Domain\ProspectRecord::READER_KEYS, [ 'birth_year' ] );
        $prospect = $this->prospectAs( $this->observer );

        $this->assertSame( [], array_values( array_diff( array_keys( $prospect ), $allowed ) ), 'no key outside the allowed list' );
        $this->assertArrayNotHasKey( 'date_of_birth', $prospect );
        $this->assertArrayNotHasKey( 'discovered_by_user_id', $prospect );
        $this->assertSame( '2014', (string) ( $prospect['birth_year'] ?? '' ), 'the birth year, as the page shows' );
    }

    public function test_who_found_the_child_stays_with_the_funnel(): void {
        wp_set_current_user( $this->observer );
        $rows = (array) ( $this->data( $this->dispatch( 'GET', '/talenttrack/v1/prospects' ) )['rows'] ?? [] );
        $mine = array_values( array_filter( $rows, fn( $r ): bool => (int) ( (array) $r )['id'] === $this->prospect ) );
        $this->assertCount( 1, $mine );
        $this->assertArrayNotHasKey( 'discovered_by', (array) $mine[0] );

        // Filtering by scout answers nothing for the observer: the filter is ignored.
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/prospects' );
        $request->set_param( 'filter', [ 'discovered_by_user_id' => $this->hod ] );
        $filtered = (array) ( $this->data( rest_do_request( $request ) )['rows'] ?? [] );
        $this->assertContains( $this->prospect, array_map( static fn( $r ): int => (int) ( (array) $r )['id'], $filtered ) );

        wp_set_current_user( $this->scout );
        $this->flush();
        $rows = (array) ( $this->data( $this->dispatch( 'GET', '/talenttrack/v1/prospects' ) )['rows'] ?? [] );
        $mine = array_values( array_filter( $rows, fn( $r ): bool => (int) ( (array) $r )['id'] === $this->prospect ) );
        $this->assertNotSame( '', (string) ( ( (array) $mine[0] )['discovered_by'] ?? '' ), 'the scout reads who found the child' );
        $this->assertSame( (string) $this->scout, (string) ( $this->prospectAs( $this->scout )['discovered_by_user_id'] ?? '' ) );
    }

    public function test_the_overview_leaves_out_discovered_by_for_the_observer(): void {
        foreach ( [ 'observer' => [ $this->observer, false ], 'scout' => [ $this->scout, true ] ] as $who => [ $user, $shown ] ) {
            wp_set_current_user( $user );
            $this->flush();
            ob_start();
            \TT\Modules\Prospects\Frontend\FrontendProspectsOverviewView::render( $user );
            $html = (string) ob_get_clean();
            $this->assertSame( $shown, str_contains( $html, 'Discovered by' ), "{$who}: the column and filter" );
        }
    }

    public function test_works_the_funnel_needs_a_prospect_read(): void {
        $nobody = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->assertFalse( \TT\Modules\Prospects\ProspectScope::worksTheFunnel( $nobody ) );
        $this->assertFalse( \TT\Modules\Prospects\ProspectScope::worksTheFunnel( $this->observer ) );
        $this->assertTrue( \TT\Modules\Prospects\ProspectScope::worksTheFunnel( $this->scout ) );
        $this->assertTrue( \TT\Modules\Prospects\ProspectScope::worksTheFunnel( $this->coach ) );
    }

    public function test_the_scout_the_head_of_development_and_the_age_group_coach_see_everything(): void {
        foreach ( [ 'scout' => $this->scout, 'head of development' => $this->hod, 'head coach' => $this->coach ] as $who => $user ) {
            $prospect = $this->prospectAs( $user );
            $this->assertSame( 'parent@example.test', $prospect['parent_email'] ?? null, "{$who} reads the contact" );
            $this->assertSame( self::NOTES, $prospect['scouting_notes'] ?? null, "{$who} reads the notes" );
        }
    }

    public function test_the_consent_trail_keeps_dates_and_outcomes_and_masks_who_and_what(): void {
        wp_set_current_user( $this->observer );
        $response = $this->dispatch( 'GET', '/talenttrack/v1/prospects/' . $this->prospect . '/consent-requests' );
        $this->assertSame( 200, $response->get_status() );
        $rows = (array) ( $this->data( $response )['requests'] ?? [] );
        $this->assertCount( 1, $rows );
        $row = (array) $rows[0];
        $this->assertSame( 'awaiting', $row['outcome'] ?? null );
        $this->assertArrayHasKey( 'asked_at', $row );
        $this->assertArrayNotHasKey( 'asked_of', $row );
        $this->assertArrayNotHasKey( 'notes', $row );

        wp_set_current_user( $this->scout );
        $row = (array) ( $this->data( $this->dispatch( 'GET', '/talenttrack/v1/prospects/' . $this->prospect . '/consent-requests' ) )['requests'][0] ?? [] );
        $this->assertSame( self::ASKED_OF, $row['asked_of'] ?? null );
    }

    public function test_the_prospect_page_shows_hidden_in_place_of_the_masked_values(): void {
        wp_set_current_user( $this->observer );
        $_GET['id'] = (string) $this->prospect;
        ob_start();
        FrontendProspectDetailView::render( $this->observer );
        $html = (string) ob_get_clean();
        unset( $_GET['id'] );

        $this->assertStringContainsString( 'Jesse', $html );
        $this->assertStringNotContainsString( self::NOTES, $html );
        $this->assertStringNotContainsString( self::ASKED_OF, $html );
        $this->assertStringContainsString( 'tt-masked', $html );
        $this->assertTrue( RecordRedactor::isMasked( 'prospects', 'scouting_notes', $this->observer, $this->prospect ) );
        $this->assertFalse( RecordRedactor::isMasked( 'prospects', 'scouting_notes', $this->scout, $this->prospect ) );
    }

    public function test_the_observer_changes_nothing_and_opens_no_visit(): void {
        wp_set_current_user( $this->observer );
        $this->assertSame( 403, $this->dispatch( 'PATCH', '/talenttrack/v1/prospects/' . $this->prospect, [ 'scouting_notes' => 'x' ] )->get_status() );
        $this->assertSame( 403, $this->dispatch( 'POST', '/talenttrack/v1/prospects', [ 'first_name' => 'A', 'last_name' => 'B' ] )->get_status() );
        $this->assertSame( 403, $this->dispatch( 'POST', '/talenttrack/v1/prospects/' . $this->prospect . '/consent-requests', [ 'asked_at' => current_time( 'Y-m-d' ), 'asked_of' => 'x' ] )->get_status() );
        $this->assertSame( 403, $this->dispatch( 'POST', '/talenttrack/v1/prospects/' . $this->prospect . '/test-training-proposal' )->get_status() );
        $this->assertSame( 403, $this->dispatch( 'GET', '/talenttrack/v1/scouting-visits' )->get_status() );
        $this->assertFalse( AuthorizationService::userCanOrMatrix( $this->observer, 'tt_edit_prospects' ) );
        $this->assertFalse( AuthorizationService::userCanOrMatrix( $this->observer, 'tt_manage_prospects' ) );

        $tiles_were = TileRegistry::allRegistered();
        TileRegistry::clear();
        \TT\Shared\CoreSurfaceRegistration::register();
        $pipeline = TileRegistry::canAccessViewSlug( 'onboarding-pipeline', $this->observer );
        $visits   = TileRegistry::canAccessViewSlug( 'scouting-visits', $this->observer );
        TileRegistry::clear();
        foreach ( $tiles_were as $tile ) TileRegistry::register( $tile );

        $this->assertTrue( $pipeline, 'the onboarding board opens' );
        $this->assertFalse( $visits, 'the scouting visits tile does not' );
    }

    public function test_the_migration_adds_the_rows_once(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        $wpdb->query( "DELETE FROM {$table} WHERE persona = 'readonly_observer' AND entity IN ('prospects','test_trainings')" );
        $this->flush();

        $this->migration()->up();
        $this->migration()->up();

        $rows = $wpdb->get_results(
            "SELECT entity, activity, scope_kind FROM {$table}
              WHERE persona = 'readonly_observer' AND entity IN ('prospects','test_trainings')
              ORDER BY entity",
            ARRAY_A
        );
        $this->assertSame( [
            [ 'entity' => 'prospects', 'activity' => 'read', 'scope_kind' => 'global' ],
            [ 'entity' => 'test_trainings', 'activity' => 'read', 'scope_kind' => 'global' ],
        ], $rows );
    }

    /** @return array<string,mixed> */
    private function prospectAs( int $user_id ): array {
        wp_set_current_user( $user_id );
        $this->flush();
        $response = $this->dispatch( 'GET', '/talenttrack/v1/prospects/' . $this->prospect );
        $this->assertSame( 200, $response->get_status(), 'the prospect opens' );
        return (array) json_decode( (string) wp_json_encode( $this->data( $response )['prospect'] ?? [] ), true );
    }

    /** @return array<string,mixed> */
    private function data( WP_REST_Response $response ): array {
        $body = (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return (array) ( $body['data'] ?? [] );
    }

    /** @param array<string,mixed> $params */
    private function dispatch( string $method, string $route, array $params = [] ): WP_REST_Response {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $k => $v ) $request->set_param( $k, $v );
        return rest_do_request( $request );
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        RecordRedactor::flush();
    }

    private function resetMatrixActiveCaches(): void {
        foreach ( [ TileRegistry::class, DashboardShortcode::class ] as $class ) {
            $prop = new \ReflectionProperty( $class, 'matrix_active_cache' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    }
}
