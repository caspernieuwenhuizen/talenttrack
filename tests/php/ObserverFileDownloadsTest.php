<?php
namespace TT\Tests\Php;

use WPDieException;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\ExportGate;
use TT\Modules\Export\ExportScope;
use TT\Modules\Export\ExportService;
use TT\Modules\Stats\PrintRouter;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Frontend\FrontendExportsView;
use TT\Shared\Frontend\FrontendStandardReportsView;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4718 — the read-only observer receives no file the server produces.
 *
 * Downloads and prints follow the export rule and the record's own read
 * rule. Real personas on a matrix-active install: an account on the role
 * and a `tt_people` row, no capability added to any user. The `exports`
 * rows come from migration 0313, as on an upgraded install.
 */
final class ObserverFileDownloadsTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0313_authorization_exports.php';

    private string $matrix_was = '';

    /** @var list<array<string,mixed>> */
    private array $tiles_were = [];

    private int $team_a   = 0;
    private int $team_b   = 0;
    private int $player_a = 0;
    private int $player_b = 0;
    private int $observer = 0;
    private int $hod      = 0;
    private int $admin    = 0;
    private int $coach    = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $this->tiles_were = TileRegistry::allRegistered();
        TileRegistry::clear();
        \TT\Shared\CoreSurfaceRegistration::register();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();

        // An upgraded install: the rows arrive through the migration.
        $wpdb->delete( "{$wpdb->prefix}tt_authorization_matrix", [ 'entity' => ExportGate::ENTITY ] );
        $this->migration()->up();
        $this->flush();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Filedl A', 'age_group' => 'U14' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Filedl B', 'age_group' => 'U15' ] );
        $this->team_b = (int) $wpdb->insert_id;
        $this->player_a = $this->player( $this->team_a, 'Anouk' );
        $this->player_b = $this->player( $this->team_b, 'Bram' );

        $this->observer = $this->persona( 'tt_readonly_observer', 'other' );
        $this->hod      = $this->persona( 'tt_head_dev', 'head_of_development' );
        $this->admin    = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->coach    = $this->headCoachOn( $this->team_a );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_GET = [];
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        TileRegistry::clear();
        foreach ( $this->tiles_were as $tile ) {
            TileRegistry::register( $tile );
        }
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- the right ------------------------------------------------------

    public function test_the_observer_holds_no_right_to_files_and_everyone_else_keeps_it(): void {
        $this->assertFalse( ExportGate::mayExport( $this->observer ), 'the observer receives no file' );
        $this->assertTrue( ExportGate::mayExport( $this->hod ) );
        $this->assertTrue( ExportGate::mayExport( $this->admin ) );
        $this->assertTrue( ExportGate::mayExport( $this->coach ) );
        $this->assertTrue( ExportGate::mayExport( (int) self::factory()->user->create( [ 'role' => 'tt_player' ] ) ) );
        $this->assertTrue( ExportGate::mayExport( (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] ) ) );
        $this->assertTrue( ExportGate::mayExport( $this->persona( 'tt_scout', 'scout' ) ) );
        $this->assertTrue( ExportGate::mayExport( $this->persona( 'tt_team_manager', 'team_manager' ) ) );
        // Staff rows are team-scoped: a staff member on a squad receives
        // files, one attached to none does not, as for every other row.
        $this->assertTrue( ExportGate::mayExport( $this->onTeam( $this->persona( 'tt_staff', 'other' ), $this->team_a ) ) );
        $this->assertFalse( ExportGate::mayExport( $this->persona( 'tt_staff', 'other' ) ) );
        $this->assertFalse( ExportGate::mayExport( 0 ), 'a visitor receives nothing' );
    }

    // ---- the export pipeline -------------------------------------------

    public function test_the_pipeline_refuses_the_observer_and_serves_hod_and_admin(): void {
        try {
            ( new ExportService() )->run( $this->request( $this->observer ) );
            $this->fail( 'the observer received an export' );
        } catch ( ExportException $e ) {
            $this->assertSame( 'forbidden', $e->errorKey );
        }

        foreach ( [ 'HoD' => $this->hod, 'admin' => $this->admin ] as $who => $uid ) {
            wp_set_current_user( $uid );
            $result = ( new ExportService() )->run( $this->request( $uid ) );
            $this->assertNotSame( '', $result->filename, "{$who} receives the file" );
        }
    }

    public function test_rest_lists_no_exporter_and_answers_403_for_the_observer(): void {
        wp_set_current_user( $this->observer );
        $list = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/exports' ) );
        $this->assertSame( 200, $list->get_status() );
        $this->assertSame( [], ( (array) $list->get_data() )['exporters'] ?? null, 'no export offered' );

        $run = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/exports/players_list' ) );
        $this->assertSame( 403, $run->get_status() );

        wp_set_current_user( $this->hod );
        $hod_list = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/exports' ) );
        $this->assertNotEmpty( ( (array) $hod_list->get_data() )['exporters'] ?? [], 'the HoD is offered exports' );
    }

    public function test_the_exports_page_and_tile_are_closed_to_the_observer(): void {
        $this->assertFalse( TileRegistry::canAccessViewSlug( 'exports', $this->observer ), 'no Exports tile for the observer' );
        $this->assertTrue( TileRegistry::canAccessViewSlug( 'exports', $this->hod ) );

        wp_set_current_user( $this->observer );
        ob_start();
        FrontendExportsView::render( $this->observer, false );
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( ExportGate::refusalMessage(), $html );
        $this->assertStringNotContainsString( 'tt_export_key', $html, 'no export form' );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
    }

    public function test_the_observer_gets_no_report_csv(): void {
        wp_set_current_user( $this->observer );
        foreach ( [ 'learning-courses', 'coach-evaluation-quality' ] as $slug ) {
            $_GET = [ 'tt_view' => 'standard-report', 'slug' => $slug, 'action' => 'export_csv' ];
            ob_start();
            try {
                FrontendStandardReportsView::render( $this->observer, false );
                $html = (string) ob_get_clean();
                // Refused before the stream by the report's own gate.
                $this->assertStringNotContainsString( 'Export (CSV)', $html, $slug );
            } catch ( WPDieException $e ) {
                ob_end_clean();
                $this->assertSame( 403, $e->getCode(), $slug );
            }
        }
    }

    public function test_scheduled_files_skip_the_observer(): void {
        $observer_mail = (string) get_userdata( $this->observer )->user_email;
        $hod_mail      = (string) get_userdata( $this->hod )->user_email;

        $plan    = ScheduledReportsRunner::planKpiDelivery( [ $observer_mail, $hod_mail ], 'attendance', $this->hod );
        $reasons = [];
        foreach ( $plan['skipped'] as $s ) $reasons[ $s['email'] ] = $s['reason'];
        $this->assertSame( 'no_file_access', $reasons[ $observer_mail ] ?? null, 'the observer is left out and named' );
        $this->assertArrayNotHasKey( $hod_mail, $reasons, 'the HoD still receives it' );
    }

    public function test_scheduled_files_follow_the_export_rule_for_every_account(): void {
        // An observer account with no person record in this club, typed as
        // an address, on a fact with no player data.
        $elsewhere      = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer', 'user_email' => 'observer-elsewhere-4718@example.test' ] );
        $elsewhere_mail = (string) get_userdata( $elsewhere )->user_email;
        $this->flush();
        $this->assertFalse( ScheduledReportsRunner::factCarriesPlayerData( 'activities' ), 'precondition: an aggregate fact' );

        $plan    = ScheduledReportsRunner::planKpiDelivery( [ $elsewhere_mail, 'nobody-4718@example.test' ], 'activities', $this->hod );
        $reasons = array_column( $plan['skipped'], 'reason', 'email' );
        $this->assertSame( 'no_file_access', $reasons[ $elsewhere_mail ] ?? null, 'the account is asked, wherever its club' );

        $reached = [];
        foreach ( $plan['deliveries'] as $delivery ) {
            foreach ( $delivery['recipients'] as $recipient ) $reached[] = strtolower( $recipient->emailAddress );
        }
        $this->assertNotContains( $elsewhere_mail, $reached );
        $this->assertContains( 'nobody-4718@example.test', $reached, 'an address with no account still receives the aggregate' );
    }

    public function test_the_exports_page_stays_closed_to_a_player_and_a_parent(): void {
        $player = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->flush();

        $this->assertFalse( TileRegistry::canAccessViewSlug( 'exports', $player ), 'no Exports tile for a player' );
        $this->assertFalse( TileRegistry::canAccessViewSlug( 'exports', $parent ), 'no Exports tile for a parent' );
    }

    // ---- exports and prints follow the record's read rule ------------

    public function test_an_export_covers_the_teams_the_reader_works_with(): void {
        $this->withOperatorMatrixRow();
        $this->assertTrue( user_can( $this->coach, 'tt_view_settings' ), 'precondition: the extra matrix row applies' );

        $this->assertSame( [ $this->team_a ], ExportScope::teamIdsFor( $this->coach, 'players' ), 'narrowed to the coach\'s own team' );
        $this->assertNull( ExportScope::teamIdsFor( $this->hod, 'players' ), 'a global reader is not narrowed' );
    }

    public function test_print_follows_the_players_read_rule(): void {
        $this->withOperatorMatrixRow();

        $this->assertTrue( PrintRouter::canPrint( $this->coach, $this->player_a ), 'the coach prints their own player' );
        $this->assertFalse( PrintRouter::canPrint( $this->coach, $this->player_b ), 'not a player outside their scope' );
        $this->assertTrue( PrintRouter::canPrint( $this->observer, $this->player_b ), 'the observer still prints what they read' );
        $this->assertTrue( PrintRouter::canPrint( $this->admin, $this->player_b ) );
    }

    // ---- the migration --------------------------------------------------

    public function test_the_migration_adds_each_row_once_and_none_for_the_observer(): void {
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}tt_authorization_matrix", [ 'entity' => ExportGate::ENTITY ] );

        $this->migration()->up();
        $first = $this->exportRows();
        $this->migration()->up();
        $this->assertSame( $first, $this->exportRows(), 'running it twice adds nothing' );

        $personas = array_column( $first, 'persona' );
        sort( $personas );
        $this->assertSame(
            [ 'academy_admin', 'assistant_coach', 'head_coach', 'head_of_development', 'parent', 'player', 'scout', 'staff', 'team_manager' ],
            $personas
        );
        $this->assertNotContains( 'readonly_observer', $personas );
        $this->assertSame( [ 'read' ], array_values( array_unique( array_column( $first, 'activity' ) ) ) );
    }

    // ---- fixtures -------------------------------------------------------

    private function request( int $user_id ): ExportRequest {
        return new ExportRequest( 'players_list', 'csv', (int) CurrentClub::id(), $user_id, null, [], null, null );
    }

    /** @return list<array<string,string>> */
    private function exportRows(): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT persona, activity, scope_kind FROM {$wpdb->prefix}tt_authorization_matrix WHERE entity = %s ORDER BY persona, scope_kind",
            ExportGate::ENTITY
        ), ARRAY_A );
    }

    /** An operator's extra matrix row on the head coach persona. */
    private function withOperatorMatrixRow(): void {
        ( new MatrixRepository() )->setRow( 'head_coach', 'settings', 'read', 'global', 'TT\\Modules\\Configuration\\ConfigurationModule' );
        $this->flush();
    }

    private function player( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Filedl', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function persona( string $role, string $role_type ): int {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Filedl', 'last_name' => 'Persona' . $uid,
            'role_type' => $role_type, 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $this->flush();
        return $uid;
    }

    /** A team scope for an existing persona account. */
    private function onTeam( int $uid, int $team_id ): int {
        global $wpdb;
        $person = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d", $uid ) );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [ 'club_id' => (int) CurrentClub::id(), 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id ] );
        $this->flush();
        return $uid;
    }

    private function headCoachOn( int $team_id ): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $uid  = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Filedl', 'last_name' => 'Coach', 'role_type' => 'head_coach',
            'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [ 'team_id' => $team_id, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1 ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [ 'club_id' => $club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id ] );
        $this->flush();
        return $uid;
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        MatrixGate::clearCache();
        AuthorizationService::flushCache();
    }

    private function resetMatrixActiveCaches(): void {
        foreach ( [ TileRegistry::class, DashboardShortcode::class ] as $class ) {
            $prop = new \ReflectionProperty( $class, 'matrix_active_cache' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    }
}
