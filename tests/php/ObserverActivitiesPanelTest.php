<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4661 — the read-only observer opens the activities panel, read-only.
 *
 * The seat reads `activities` at global scope, but the dashboard dispatches
 * `?tt_view=activities` on `activities_panel`, which it did not hold, so
 * neither the list nor an activity's page opened and the "View match prep"
 * entry was out of reach. Asked through the dashboard's own gates and
 * router, not `render()`, because the dispatch is what refused.
 */
final class ObserverActivitiesPanelTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0308_authorization_observer_activities_panel.php';

    private string $matrix_was = '';

    /** @var list<array<string,mixed>> */
    private array $tiles_were = [];

    private int $observer = 0;
    private int $match    = 0;
    private int $training = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();

        $this->tiles_were = TileRegistry::allRegistered();
        TileRegistry::clear();
        \TT\Shared\CoreSurfaceRegistration::register();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();

        // The install seeds the matrix from the seed file; the migration is
        // how an existing install gets the row, so ask it rather than assume.
        $this->migration()->up();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Obspanel U15', 'age_group' => 'U15' ] );
        $team = (int) $wpdb->insert_id;

        $this->match    = $this->activity( $team, 'match', 'completed', '-3 days' );
        $this->training = $this->activity( $team, 'training', 'scheduled', '+3 days' );
        ( new MatchPrepRepository() )->ensureForActivity( $this->match );

        $this->observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        wp_set_current_user( $this->observer );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        $_GET = [];
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        TileRegistry::clear();
        foreach ( $this->tiles_were as $tile ) {
            TileRegistry::register( $tile );
        }
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_dispatch_lets_the_observer_open_the_activities_panel(): void {
        $this->assertSame( 'activities_panel', TileRegistry::entityForViewSlug( 'activities' ), 'precondition: the slug dispatches on the panel entity' );
        $this->assertTrue( TileRegistry::canAccessViewSlug( 'activities', $this->observer ) );
        $this->assertTrue( DashboardShortcode::dispatchAllows( 'activities', $this->observer ) );
    }

    public function test_the_list_opens_without_a_create_entry(): void {
        $_GET = [ 'tt_view' => 'activities' ];
        [ $handled, $html ] = $this->dispatch( 'activities' );

        $this->assertTrue( $handled );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'CLAUDE.md §5: the list renders the chain' );
        $this->assertStringNotContainsString( 'New activity', $html );
        $this->assertStringNotContainsString( 'action=new', html_entity_decode( $html, ENT_QUOTES ) );
    }

    public function test_a_match_page_opens_read_only_and_offers_view_match_prep(): void {
        $_GET = [ 'tt_view' => 'activities', 'id' => (string) $this->match ];
        [ $handled, $html ] = $this->dispatch( 'activities' );

        $this->assertTrue( $handled );
        $this->assertStringNotContainsString( 'That activity no longer exists.', $html );
        $this->assertStringContainsString( 'Obspanel match', $html );
        $this->assertStringContainsString( 'data-tt-match-prep-read', $html );
        $this->assertStringContainsString( 'View match prep', $html );

        $this->assertNoWriteEntries( $html );
        $this->assertStringNotContainsString( 'tt-act-result__form', $html, 'the result is read, not offered for editing' );
        $this->assertStringNotContainsString( 'new-evaluation', $html, 'no rating entry' );
    }

    public function test_a_scheduled_activity_page_offers_no_status_actions(): void {
        $_GET = [ 'tt_view' => 'activities', 'id' => (string) $this->training ];
        [ $handled, $html ] = $this->dispatch( 'activities' );

        $this->assertTrue( $handled );
        $this->assertStringContainsString( 'Obspanel training', $html );
        // The status actions ride the `data-tt-archive-*` plumbing, so the
        // assertion below already covers them; the labels make it legible.
        $this->assertNoWriteEntries( $html );
        $this->assertStringNotContainsString( 'Mark completed', $html );
        $this->assertStringNotContainsString( 'Cancel activity', $html );
    }

    public function test_the_migration_adds_the_row_once_and_read_only(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        $wpdb->delete( $table, [ 'persona' => 'readonly_observer', 'entity' => 'activities_panel' ] );

        $this->migration()->up();
        // Twice, because a re-run must add nothing.
        $this->migration()->up();

        $rows = $wpdb->get_results(
            "SELECT activity, scope_kind FROM {$table} WHERE persona = 'readonly_observer' AND entity = 'activities_panel'",
            ARRAY_A
        );
        $this->assertSame( [ [ 'activity' => 'read', 'scope_kind' => 'global' ] ], $rows );
    }

    /** No archive, edit or live-match action anywhere on the page. */
    private function assertNoWriteEntries( string $html ): void {
        $this->assertStringNotContainsString( 'data-tt-archive-rest-path', $html );
        $this->assertStringNotContainsString( 'action=edit', html_entity_decode( $html, ENT_QUOTES ) );
        $this->assertStringNotContainsString( 'tt_view=match-execution', $html );
    }

    /** @return array{0:bool,1:string} */
    private function dispatch( string $view ): array {
        $this->assertTrue( DashboardShortcode::dispatchAllows( $view, $this->observer ), "dispatch refuses {$view}" );

        $m = new \ReflectionMethod( DashboardShortcode::class, 'tryDispatch' );
        $m->setAccessible( true );
        ob_start();
        $handled = (bool) $m->invoke( null, $view, $this->observer, current_user_can( 'tt_edit_settings' ), null );
        return [ $handled, (string) ob_get_clean() ];
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }

    private function resetMatrixActiveCaches(): void {
        foreach ( [ TileRegistry::class, DashboardShortcode::class ] as $class ) {
            $prop = new \ReflectionProperty( $class, 'matrix_active_cache' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    }

    private function activity( int $team_id, string $type, string $status, string $when ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => (int) CurrentClub::id(),
            'team_id'             => $team_id,
            'title'               => 'Obspanel ' . $type,
            'session_date'        => gmdate( 'Y-m-d', (int) strtotime( $when ) ),
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
            'plan_state'          => $status === 'completed' ? 'completed' : 'scheduled',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the activity row was written' );
        return $id;
    }
}
