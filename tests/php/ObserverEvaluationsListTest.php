<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4997 — the read-only observer opens the evaluations list. The tile and
 * dispatch entity is `evaluations_panel`, which the observer did not hold,
 * so `?tt_view=evaluations` said "not authorized" while `GET /evaluations`
 * answered. The list renders from that route, where the staff notes stay
 * masked for this seat (#4881).
 */
final class ObserverEvaluationsListTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0321_authorization_observer_evaluations_panel.php';
    private const NOTES     = 'Staff note 4997: worried about focus at home';

    private string $matrix_was = '';

    /** @var list<array<string,mixed>> */
    private array $tiles_were = [];

    private int $observer = 0;
    private int $hod      = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

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

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Obseval U14' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $team, 'first_name' => 'Obseval', 'last_name' => 'Playerx', 'status' => 'active',
        ] );
        $player = (int) $wpdb->insert_id;

        $this->observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->hod      = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $wpdb->insert( "{$p}tt_evaluations", [
            'club_id' => $club, 'player_id' => $player, 'coach_id' => $this->hod,
            'eval_date' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ), 'notes' => self::NOTES,
        ] );

        $this->migration()->up();
        $this->flush();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
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

    public function test_the_observer_opens_the_evaluations_list(): void {
        $this->assertTrue( MatrixGate::can( $this->observer, 'evaluations_panel', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) );
        $this->assertTrue( TileRegistry::canAccessViewSlug( 'evaluations', $this->observer ), 'the view dispatches for the observer' );
    }

    /** The list's rows are this route's rows: the staff notes are not among them. */
    public function test_the_notes_stay_hidden_on_the_list(): void {
        $this->assertStringNotContainsString( 'Staff note 4997', $this->listBody( $this->observer ), 'the observer gets no staff notes' );
        $this->assertStringContainsString( 'Staff note 4997', $this->listBody( $this->hod ), 'precondition: the head of development does' );
    }

    public function test_the_migration_adds_one_read_row_once(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        $wpdb->delete( $table, [ 'persona' => 'readonly_observer', 'entity' => 'evaluations_panel' ] );

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(
            [ [ 'activity' => 'read', 'scope_kind' => 'global', 'is_default' => '1' ] ],
            $wpdb->get_results( "SELECT activity, scope_kind, is_default FROM {$table} WHERE persona = 'readonly_observer' AND entity = 'evaluations_panel'", ARRAY_A )
        );
    }

    public function test_a_cell_the_academy_changed_keeps_its_value(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        $wpdb->delete( $table, [ 'persona' => 'readonly_observer', 'entity' => 'evaluations_panel' ] );
        foreach ( [ 'grant', 'revoke' ] as $type ) {
            $wpdb->insert( "{$wpdb->prefix}tt_authorization_changelog", [
                'persona' => 'readonly_observer', 'entity' => 'evaluations_panel', 'activity' => 'read',
                'scope_kind' => 'global', 'change_type' => $type, 'actor_user_id' => 1,
                'created_at' => current_time( 'mysql' ),
            ] );
        }

        $this->migration()->up();

        $this->assertSame(
            0,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE persona = 'readonly_observer' AND entity = 'evaluations_panel'" ),
            'the revoked cell stays revoked'
        );
    }

    private function listBody( int $user_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/evaluations' );
        $server   = rest_get_server();
        $response = rest_ensure_response( $server->dispatch( $request ) );
        $response = apply_filters( 'rest_post_dispatch', $response, $server, $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        return (string) wp_json_encode( $response->get_data() );
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
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
