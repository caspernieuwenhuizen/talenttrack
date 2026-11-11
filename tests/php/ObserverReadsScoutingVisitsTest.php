<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Prospects\ScoutingVisitsAccess;
use TT\Modules\Prospects\Frontend\FrontendScoutingVisitDetailView;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * #5085 — the read-only observer reads every scouting visit through
 * `scouting_visits_all`, with the scout's notes masked, and writes none. A
 * scout still sees only their own visits; the head of development sees all.
 */
final class ObserverReadsScoutingVisitsTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0334_observer_scouting_visits.php';
    private const NOTES     = 'Watch the left winger, number 11.';

    private string $p;
    private string $matrix_was = '';
    private int $club;
    private int $observer = 0;
    private int $scout    = 0;
    private int $other    = 0;
    private int $hod      = 0;
    private int $mine     = 0;
    private int $theirs   = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();
        $this->migration()->up();

        $this->observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->scout    = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->other    = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->hod      = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->mine     = $this->visit( $this->scout );
        $this->theirs   = $this->visit( $this->other );
        $this->flush();
    }

    public function tear_down(): void {
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        $this->flush();
        unset( $_GET['id'], $_GET['tt_view'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_only_the_observer_holds_the_new_right(): void {
        $this->assertTrue( ScoutingVisitsAccess::readsEveryVisit( $this->observer ) );
        $this->assertFalse( ScoutingVisitsAccess::readsEveryVisit( $this->scout ) );
        $this->assertFalse( ScoutingVisitsAccess::readsEveryVisit( $this->hod ) );

        global $wpdb;
        $holders = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT persona FROM {$this->p}tt_authorization_matrix WHERE entity = %s",
            ScoutingVisitsAccess::ALL_ENTITY
        ) );
        $this->assertSame( [ 'readonly_observer' ], array_values( (array) $holders ) );
    }

    public function test_the_observer_lists_and_opens_every_visit_without_the_notes(): void {
        wp_set_current_user( $this->observer );
        $rows = (array) ( $this->data( $this->dispatch( 'GET', '/talenttrack/v1/scouting-visits' ) )['rows'] ?? [] );
        $ids  = array_map( static fn( $r ): int => (int) ( (array) $r )['id'], $rows );
        sort( $ids );
        $this->assertSame( [ $this->mine, $this->theirs ], $ids, 'every visit in the club' );
        foreach ( $rows as $row ) {
            $this->assertArrayNotHasKey( 'notes', (array) $row, 'the notes are left out of the list' );
        }

        $one = $this->dispatch( 'GET', '/talenttrack/v1/scouting-visits/' . $this->theirs );
        $this->assertSame( 200, $one->get_status() );
        $visit = (array) ( $this->data( $one )['visit'] ?? [] );
        $this->assertArrayNotHasKey( 'notes', $visit );
        $this->assertSame( 'Sportpark Noord', $visit['location'] ?? null, 'where, when and who stay visible' );

        $this->assertTrue( RecordRedactor::isMasked( 'scouting_visits', 'notes', $this->observer, $this->theirs ) );
    }

    public function test_the_visit_page_shows_hidden_for_the_observer(): void {
        wp_set_current_user( $this->observer );
        $_GET['tt_view'] = 'scouting-visit';
        $_GET['id']      = (string) $this->theirs;
        ob_start();
        FrontendScoutingVisitDetailView::render( $this->observer, false );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Sportpark Noord', $html );
        $this->assertStringNotContainsString( self::NOTES, $html );
        $this->assertStringContainsString( 'tt-masked-value', $html );
    }

    /**
     * The scout persona holds `prospects: create_delete` (`tt_manage_prospects`)
     * at global scope, which already reads every visit; nothing here changes
     * that, and the notes stay readable for them.
     */
    public function test_scouts_and_the_head_of_development_read_as_before(): void {
        $this->assertFalse( ScoutingVisitsAccess::readsEveryVisit( $this->scout ), 'not through the new right' );

        wp_set_current_user( $this->scout );
        $this->flush();
        $rows = (array) ( $this->data( $this->dispatch( 'GET', '/talenttrack/v1/scouting-visits' ) )['rows'] ?? [] );
        $ids  = array_map( static fn( $r ): int => (int) ( (array) $r )['id'], $rows );
        sort( $ids );
        $this->assertSame( [ $this->mine, $this->theirs ], $ids );
        foreach ( $rows as $row ) {
            $this->assertSame( self::NOTES, (string) ( ( (array) $row )['notes'] ?? '' ), 'the scout reads the notes' );
        }

        wp_set_current_user( $this->hod );
        $this->flush();
        $rows = (array) ( $this->data( $this->dispatch( 'GET', '/talenttrack/v1/scouting-visits' ) )['rows'] ?? [] );
        $this->assertCount( 2, $rows );
        $visit = (array) ( $this->data( $this->dispatch( 'GET', '/talenttrack/v1/scouting-visits/' . $this->theirs ) )['visit'] ?? [] );
        $this->assertSame( self::NOTES, (string) ( $visit['notes'] ?? '' ), 'the head of development reads the notes' );
    }

    public function test_the_observer_writes_nothing(): void {
        wp_set_current_user( $this->observer );
        $this->assertSame( 403, $this->dispatch( 'POST', '/talenttrack/v1/scouting-visits', [ 'visit_date' => '2026-12-01', 'location' => 'X' ] )->get_status() );
        $this->assertSame( 403, $this->dispatch( 'POST', '/talenttrack/v1/scouting-visits/' . $this->theirs, [ 'notes' => 'x' ] )->get_status() );
        $this->assertSame( 403, $this->dispatch( 'DELETE', '/talenttrack/v1/scouting-visits/' . $this->theirs )->get_status() );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->p}tt_scouting_plan_visits WHERE id = %d", $this->theirs ) );
        $this->assertFalse( ScoutingVisitsAccess::canEditVisit( $this->observer, $row ), 'reading every visit adds no write' );
    }

    public function test_the_migration_adds_both_rows_once_and_leaves_an_edited_one(): void {
        global $wpdb;
        $table = "{$this->p}tt_authorization_matrix";
        $wpdb->query( "DELETE FROM {$table} WHERE persona = 'readonly_observer' AND entity IN ('scouting_visits_panel','scouting_visits_all')" );
        $this->flush();

        $this->migration()->up();
        $this->migration()->up();
        $rows = $wpdb->get_col( "SELECT entity FROM {$table} WHERE persona = 'readonly_observer' AND entity IN ('scouting_visits_panel','scouting_visits_all') ORDER BY entity" );
        $this->assertSame( [ 'scouting_visits_all', 'scouting_visits_panel' ], array_values( (array) $rows ) );

        // An academy that switched the panel off keeps its choice.
        $wpdb->query( "DELETE FROM {$table} WHERE persona = 'readonly_observer' AND entity IN ('scouting_visits_panel','scouting_visits_all')" );
        $wpdb->insert( $table, [
            'persona' => 'readonly_observer', 'entity' => 'scouting_visits_all', 'activity' => 'read',
            'scope_kind' => 'self', 'module_class' => \TT\Modules\Prospects\ProspectsModule::class, 'is_default' => 0,
        ] );
        $this->migration()->up();
        $kept = $wpdb->get_results( "SELECT scope_kind FROM {$table} WHERE persona = 'readonly_observer' AND entity = 'scouting_visits_all'", ARRAY_A );
        $this->assertSame( [ [ 'scope_kind' => 'self' ] ], $kept, 'the edited cell is left as it is' );
        $this->flush();
        $this->assertFalse( MatrixGate::can( $this->observer, 'scouting_visits_all', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) );
    }

    private function visit( int $scout ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_scouting_plan_visits", [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => $this->club,
            'scout_user_id' => $scout,
            'visit_date'    => '2026-11-20',
            'location'      => 'Sportpark Noord',
            'status'        => 'planned',
            'notes'         => self::NOTES,
        ] );
        return (int) $wpdb->insert_id;
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
