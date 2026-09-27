<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerVisibility;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\ActivityAccess;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3972 — a scout reads goals and activities for the players they are
 * linked to, not academy-wide.
 *
 * The fourth and fifth narrowing of the scout block, after evaluations
 * (#1378), media (#2591) and players (#3807). Asserted with one linked and
 * one unlinked player, over REST and at the per-record check.
 */
final class ScoutGoalsActivitiesScopeTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $scout    = 0;
    private int $linked   = 0;
    private int $unlinked = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        PlayerVisibility::flush();

        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $this->scout    = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->linked   = $this->makePlayer( 'Maxim', 'Terpstra' );
        $this->unlinked = $this->makePlayer( 'Joris', 'Mulder' );

        // The assignment list, one of the two links ScoutPlayerLinks reads.
        update_user_meta( $this->scout, 'tt_scout_player_ids', wp_json_encode( [ $this->linked ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        PlayerVisibility::flush();
        MatrixRepository::clearCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_seed_grants_both_at_player_scope_and_not_global(): void {
        $repo = new MatrixRepository();
        foreach ( [ 'goals', 'activities' ] as $entity ) {
            $this->assertTrue( $repo->lookup( 'scout', $entity, 'read', 'player' ), "{$entity} at player scope" );
            $this->assertFalse( $repo->lookup( 'scout', $entity, 'read', 'global' ), "{$entity} no longer global" );
        }
    }

    public function test_the_readable_players_are_the_linked_ones(): void {
        $this->assertSame( [ $this->linked ], AuthorizationService::scoutLinkedReadablePlayerIds( $this->scout, 'goals' ) );
        $this->assertSame( [ $this->linked ], AuthorizationService::scoutLinkedReadablePlayerIds( $this->scout, 'activities' ) );
    }

    public function test_activities_follow_the_link(): void {
        $this->assertTrue( ActivityAccess::canReadAsLinkedScout( $this->scout, $this->linked ) );
        $this->assertFalse( ActivityAccess::canReadAsLinkedScout( $this->scout, $this->unlinked ) );
        $this->assertFalse( ActivityAccess::hasGlobalRead( $this->scout ), 'a scout no longer reads every team' );
    }

    public function test_the_goals_list_carries_only_linked_players(): void {
        $this->makeGoal( $this->linked, 'Linked goal' );
        $this->makeGoal( $this->unlinked, 'Unlinked goal' );

        wp_set_current_user( $this->scout );
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/goals' ) );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        $this->assertSame( 200, (int) $response->get_status() );
        $titles = array_map(
            static function ( $row ): string { return (string) ( $row['title'] ?? '' ); },
            (array) ( $data['data']['rows'] ?? [] )
        );
        $this->assertContains( 'Linked goal', $titles );
        $this->assertNotContains( 'Unlinked goal', $titles, 'an unlinked player\'s goals are filtered out' );
    }

    public function test_an_unlinked_players_goals_are_refused(): void {
        wp_set_current_user( $this->scout );
        PlayerVisibility::flush();
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->unlinked . '/goals' ) );

        $this->assertSame( 403, (int) $response->get_status() );
    }

    public function test_the_migration_narrows_default_rows_and_leaves_customised_ones(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";

        // An install that still carries the old default global rows.
        $wpdb->query( "DELETE FROM {$table} WHERE persona = 'scout' AND entity IN ('goals','activities')" );
        $wpdb->insert( $table, [
            'persona' => 'scout', 'entity' => 'goals', 'activity' => 'read', 'scope_kind' => 'global',
            'module_class' => \TT\Modules\Goals\GoalsModule::class, 'is_default' => 1,
        ] );
        // An operator who widened activities on purpose.
        $wpdb->insert( $table, [
            'persona' => 'scout', 'entity' => 'activities', 'activity' => 'read', 'scope_kind' => 'global',
            'module_class' => \TT\Modules\Activities\ActivitiesModule::class, 'is_default' => 0,
        ] );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0291_scout_goals_activities_scope_tightening.php';
        $migration->up();
        $migration->up(); // a re-run changes nothing

        $rows = $wpdb->get_results(
            "SELECT entity, scope_kind, is_default FROM {$table}
              WHERE persona = 'scout' AND entity IN ('goals','activities') AND activity = 'read'
              ORDER BY entity",
            ARRAY_A
        );

        $this->assertSame( [
            [ 'entity' => 'activities', 'scope_kind' => 'global', 'is_default' => '0' ],
            [ 'entity' => 'goals',      'scope_kind' => 'player', 'is_default' => '1' ],
        ], $rows );
    }

    private function makePlayer( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'first_name'    => $first,
            'last_name'     => $last,
            'date_of_birth' => '2012-03-03',
            'status'        => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeGoal( int $player_id, string $title ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'title'      => $title,
            'status'     => 'in_progress',
            'created_by' => 1,
        ] );
    }
}
