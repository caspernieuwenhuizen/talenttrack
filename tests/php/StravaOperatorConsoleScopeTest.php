<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Strava\ConnectionRepository;
use TT\Modules\Strava\StravaConfig;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * The Strava club console (connections list, webhook subscription, app
 * credentials, `?tt_view=strava-admin`) is configuration. It asks
 * `strava_operator_console` at global scope, so a player's self-scope
 * `strava_integration` row and a head coach's team-scope row no longer
 * open it, while the player still connects and disconnects their own
 * account. Every persona is real (role, people and scope rows, the matrix
 * on); nothing is granted with add_cap, and the routes are dispatched
 * through the REST server so the permission callbacks decide.
 */
final class StravaOperatorConsoleScopeTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0309_authorization_strava_operator_console.php';

    private string $matrix_was = '';

    private int $player_uid = 0;
    private int $player_id  = 0;
    private int $other_player_id = 0;
    private int $coach_uid  = 0;
    private int $admin_uid  = 0;
    private int $hod_uid    = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        ( new RolesService() )->installRoles();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();

        $this->migration()->up();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Stravascope U15', 'age_group' => 'U15' ] );
        $team = (int) $wpdb->insert_id;

        $this->player_uid = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'first_name' => 'Stravascope',
            'last_name'  => 'Runner',
            'team_id'    => $team,
            'status'     => 'active',
            'wp_user_id' => $this->player_uid,
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Stravascope U17', 'age_group' => 'U17' ] );
        $other_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'first_name' => 'Stravascope',
            'last_name'  => 'Elsewhere',
            'team_id'    => $other_team,
            'status'     => 'active',
            'wp_user_id' => 0,
        ] );
        $this->other_player_id = (int) $wpdb->insert_id;

        $repo = new ConnectionRepository();
        foreach ( [ $this->player_id => 7001, $this->other_player_id => 7002 ] as $pid => $athlete ) {
            $repo->connect( $pid, [
                'athlete_id'    => $athlete,
                'access_token'  => '',
                'refresh_token' => 'r-' . $athlete,
                'expires_at'    => time() + 3600,
            ] );
        }

        $this->coach_uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $club,
            'first_name' => 'Stravascope',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $this->coach_uid,
            'status'     => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => $club,
            'team_id'       => $team,
            'person_id'     => $person,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team,
        ] );

        $this->admin_uid = self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        $this->hod_uid   = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->assertContains( 'player', PersonaResolver::personasFor( $this->player_uid ) );
        $this->assertContains( 'head_coach', PersonaResolver::personasFor( $this->coach_uid ) );
        $this->assertContains( 'academy_admin', PersonaResolver::personasFor( $this->admin_uid ) );
        $this->assertContains( 'head_of_development', PersonaResolver::personasFor( $this->hod_uid ) );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_GET = [];
        // The config reader caches; clear the credentials so the next test
        // class does not start with an app configured.
        StravaConfig::saveCredentials( '', '' );
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_self_row_on_the_connection_entity_does_not_open_the_console(): void {
        // The precondition the rule is about: the player holds a self row
        // on `strava_integration`, and it answers neither console cap.
        $this->assertTrue( MatrixGate::canAnyScope( $this->player_uid, 'strava_integration', MatrixGate::READ ) );
        $this->assertFalse( user_can( $this->player_uid, 'tt_view_strava' ) );
        $this->assertFalse( user_can( $this->player_uid, 'tt_edit_strava_credentials' ) );
    }

    public function test_the_player_is_refused_every_console_route(): void {
        wp_set_current_user( $this->player_uid );
        AuthorizationService::flushCache();

        $this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/strava/connections' ) )->get_status(), 'GET connections' );
        $this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/strava/webhook/subscription' ) )->get_status(), 'GET subscription' );
        $this->assertWritesRefused();
    }

    public function test_the_head_coach_reads_only_their_own_teams_connections(): void {
        $this->assertTrue( user_can( $this->coach_uid, 'tt_view_strava' ) );
        $this->assertFalse( user_can( $this->coach_uid, 'tt_edit_strava_credentials' ), 'read-only' );

        wp_set_current_user( $this->coach_uid );
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/strava/connections' ) );
        $this->assertSame( 200, $res->get_status() );

        $data = $res->get_data()['data'];
        $ids  = array_map( 'intval', array_column( $data['connections'], 'player_id' ) );
        $this->assertSame( [ $this->player_id ], $ids, 'their own team only, not the other team' );
        $this->assertArrayNotHasKey( 'subscription_id', $data, 'webhook state is club-wide' );
        $this->assertArrayHasKey( 'configured', $data );
    }

    public function test_a_club_wide_reader_gets_the_subscription_id(): void {
        wp_set_current_user( $this->hod_uid );
        $data = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/strava/connections' ) )->get_data()['data'];
        $this->assertArrayHasKey( 'subscription_id', $data );
    }

    public function test_the_head_coach_roster_leaves_out_players_who_left_the_squad(): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $team = (int) $wpdb->get_var( $wpdb->prepare( "SELECT team_id FROM {$p}tt_players WHERE id = %d", $this->player_id ) );

        $left = [];
        foreach ( [ [ 'status' => 'released' ], [ 'status' => 'active', 'archived_at' => current_time( 'mysql' ) ] ] as $i => $state ) {
            $wpdb->insert( "{$p}tt_players", array_merge( [
                'club_id'    => $club,
                'first_name' => 'Stravascope',
                'last_name'  => 'Gone' . $i,
                'team_id'    => $team,
            ], $state ) );
            $pid = (int) $wpdb->insert_id;
            $this->assertGreaterThan( 0, $pid, 'fixture: the player row was written' );
            ( new ConnectionRepository() )->connect( $pid, [
                'athlete_id'    => 7100 + $i,
                'access_token'  => '',
                'refresh_token' => 'r-gone-' . $i,
                'expires_at'    => time() + 3600,
            ] );
            $left[] = $pid;
        }

        wp_set_current_user( $this->coach_uid );
        $ids = array_map( 'intval', array_column(
            rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/strava/connections' ) )->get_data()['data']['connections'],
            'player_id'
        ) );
        $this->assertSame( [ $this->player_id ], $ids, 'only the live roster' );
        foreach ( $left as $pid ) {
            $this->assertNotContains( $pid, $ids );
        }
    }

    public function test_the_configuration_tile_tells_a_head_coach_what_they_get(): void {
        $this->assertSame(
            'See which players in your teams have connected their Strava accounts.',
            $this->stravaTileDesc( $this->coach_uid )
        );
        $this->assertStringContainsString( 'webhook', $this->stravaTileDesc( $this->hod_uid ) );
    }

    public function test_the_webhook_status_is_for_club_wide_readers_only(): void {
        wp_set_current_user( $this->coach_uid );
        AuthorizationService::flushCache();
        $this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/strava/webhook/subscription' ) )->get_status(), 'head coach' );

        wp_set_current_user( $this->hod_uid );
        AuthorizationService::flushCache();
        $this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/strava/webhook/subscription' ) )->get_status(), 'head of development' );

        wp_set_current_user( $this->admin_uid );
        AuthorizationService::flushCache();
        $this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/strava/webhook/subscription' ) )->get_status(), 'academy admin' );
    }

    public function test_the_head_coach_sees_only_the_connections_table(): void {
        $callback = StravaConfig::webhookCallbackUrl();
        $this->assertNotSame( '', $callback, 'precondition: there is a callback URL to leak' );

        [ $handled, $html ] = $this->dispatch( 'strava-admin', $this->coach_uid );
        $this->assertTrue( $handled );
        $this->assertStringContainsString( 'data-tt-strava-admin-rows', $html, 'the roster renders' );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
        $this->assertStringNotContainsString( 'data-tt-strava-admin-sub-state', $html, 'no webhook status' );
        $this->assertStringNotContainsString( esc_html( $callback ), $html, 'no callback URL' );
        $this->assertStringNotContainsString( 'data-tt-strava-admin-app-form', $html, 'no app credentials' );
    }

    public function test_the_head_of_development_sees_the_webhook_status_and_callback(): void {
        [ $handled, $html ] = $this->dispatch( 'strava-admin', $this->hod_uid );
        $this->assertTrue( $handled );
        $this->assertStringContainsString( 'data-tt-strava-admin-sub-state', $html );
        $this->assertStringContainsString( esc_html( StravaConfig::webhookCallbackUrl() ), $html );
        $this->assertStringContainsString( 'data-tt-strava-admin-app-form', $html );
        $this->assertStringContainsString( 'data-tt-strava-admin-rows', $html );
    }

    public function test_the_head_coach_cannot_change_the_app_or_the_webhook(): void {
        wp_set_current_user( $this->coach_uid );
        AuthorizationService::flushCache();
        $this->assertWritesRefused();
    }

    public function test_a_club_wide_reader_sees_every_teams_connections(): void {
        wp_set_current_user( $this->hod_uid );
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/strava/connections' ) );
        $this->assertSame( 200, $res->get_status() );

        $ids = array_map( 'intval', array_column( $res->get_data()['data']['connections'], 'player_id' ) );
        sort( $ids );
        $expected = [ $this->player_id, $this->other_player_id ];
        sort( $expected );
        $this->assertSame( $expected, $ids );
    }

    public function test_the_player_is_refused_the_console_view(): void {
        wp_set_current_user( $this->player_uid );
        $_GET = [ 'tt_view' => 'strava-admin' ];

        [ $handled, $html ] = $this->dispatch( 'strava-admin', $this->player_uid );

        $this->assertTrue( $handled );
        $this->assertStringContainsString( 'You do not have permission to view this section.', $html );
        $this->assertStringNotContainsString( 'data-tt-strava-admin', $html );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'the refusal still renders the chain' );
    }

    public function test_the_player_still_connects_and_disconnects_their_own_account(): void {
        StravaConfig::saveCredentials( '12345', 'stravascope-secret' );
        wp_set_current_user( $this->player_uid );

        $connect = new WP_REST_Request( 'POST', '/talenttrack/v1/players/' . $this->player_id . '/strava/connect' );
        $connect->set_param( 'consent', true );
        $res = rest_do_request( $connect );
        $this->assertSame( 200, $res->get_status() );
        $this->assertStringContainsString( '/oauth/authorize', (string) $res->get_data()['data']['authorize_url'] );

        $status = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player_id . '/strava/status' ) );
        $this->assertSame( 200, $status->get_status() );

        $disconnect = rest_do_request( new WP_REST_Request( 'DELETE', '/talenttrack/v1/players/' . $this->player_id . '/strava/connect' ) );
        $this->assertSame( 200, $disconnect->get_status() );
    }

    public function test_the_academy_admin_keeps_the_whole_console(): void {
        $this->assertTrue( user_can( $this->admin_uid, 'tt_view_strava' ) );
        $this->assertTrue( user_can( $this->admin_uid, 'tt_edit_strava_credentials' ) );

        wp_set_current_user( $this->admin_uid );
        $this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/strava/connections' ) )->get_status() );

        $app = new WP_REST_Request( 'POST', '/talenttrack/v1/strava/app' );
        $app->set_param( 'client_id', '12345' );
        $app->set_param( 'client_secret', 'stravascope-secret' );
        $this->assertNotSame( 403, rest_do_request( $app )->get_status() );

        [ $handled, $html ] = $this->dispatch( 'strava-admin', $this->admin_uid );
        $this->assertTrue( $handled );
        $this->assertStringContainsString( 'data-tt-strava-admin', $html );
    }

    public function test_the_head_of_development_reads_the_console(): void {
        $this->assertTrue( user_can( $this->hod_uid, 'tt_view_strava' ) );
        $this->assertFalse( user_can( $this->hod_uid, 'tt_edit_strava_credentials' ), 'read-only by default' );

        wp_set_current_user( $this->hod_uid );
        $this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/strava/connections' ) )->get_status() );

        [ $handled, $html ] = $this->dispatch( 'strava-admin', $this->hod_uid );
        $this->assertTrue( $handled );
        $this->assertStringContainsString( 'data-tt-strava-admin', $html );
    }

    public function test_the_migration_run_twice_leaves_one_row_each(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        $wpdb->delete( $table, [ 'entity' => 'strava_operator_console' ] );

        $this->migration()->up();
        $this->migration()->up();

        $rows = $wpdb->get_results(
            "SELECT persona, activity, scope_kind, COUNT(*) AS n FROM {$table}
              WHERE entity = 'strava_operator_console'
              GROUP BY persona, activity, scope_kind
              ORDER BY persona, activity",
            ARRAY_A
        );
        $this->assertSame( [
            [ 'persona' => 'academy_admin',       'activity' => 'change',        'scope_kind' => 'global', 'n' => '1' ],
            [ 'persona' => 'academy_admin',       'activity' => 'create_delete', 'scope_kind' => 'global', 'n' => '1' ],
            [ 'persona' => 'academy_admin',       'activity' => 'read',          'scope_kind' => 'global', 'n' => '1' ],
            [ 'persona' => 'head_coach',          'activity' => 'read',          'scope_kind' => 'team',   'n' => '1' ],
            [ 'persona' => 'head_of_development', 'activity' => 'read',          'scope_kind' => 'global', 'n' => '1' ],
        ], $rows );
    }

    /** The current user may change neither the app credentials nor the webhook. */
    private function assertWritesRefused(): void {
        $this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', '/talenttrack/v1/strava/webhook/subscription' ) )->get_status(), 'POST subscription' );
        $this->assertSame( 403, rest_do_request( new WP_REST_Request( 'DELETE', '/talenttrack/v1/strava/webhook/subscription' ) )->get_status(), 'DELETE subscription' );

        $app = new WP_REST_Request( 'POST', '/talenttrack/v1/strava/app' );
        $app->set_param( 'client_id', '99999' );
        $app->set_param( 'client_secret', 'not-theirs' );
        $this->assertSame( 403, rest_do_request( $app )->get_status(), 'POST app' );
        $this->assertFalse( StravaConfig::hasCredentials(), 'the refused write stored nothing' );
    }

    /** @return array{0:bool,1:string} */
    private function dispatch( string $view, int $uid ): array {
        $this->assertTrue( DashboardShortcode::dispatchAllows( $view, $uid ) );
        wp_set_current_user( $uid );

        $m = new \ReflectionMethod( DashboardShortcode::class, 'tryDispatch' );
        $m->setAccessible( true );
        ob_start();
        $handled = (bool) $m->invoke( null, $view, $uid, current_user_can( 'tt_edit_settings' ), null );
        return [ $handled, (string) ob_get_clean() ];
    }

    private function stravaTileDesc( int $uid ): string {
        wp_set_current_user( $uid );
        AuthorizationService::flushCache();
        $groups = \TT\Shared\Configuration\ConfigurationSurfaces::groups( $uid, home_url( '/?config_sub=' ), home_url( '/?tt_view=' ) );
        foreach ( $groups as $group ) {
            foreach ( $group['tiles'] as $tile ) {
                if ( str_contains( (string) ( $tile['url'] ?? '' ), 'strava-admin' ) ) {
                    return (string) ( $tile['desc'] ?? '' );
                }
            }
        }
        $this->fail( 'the Strava tile is listed for this reader' );
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
}
