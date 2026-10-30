<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4386 — a tile's `entity` must be one the authorization matrix knows.
 *
 * The Tournaments tile declared `tournament` while the seed grants
 * `tournaments`. With the matrix active, `canAccessViewSlug()` asks the
 * matrix about the tile's entity, finds no grant for anyone, and refuses
 * every non-admin `?tt_view=tournaments`. Nothing else reads the tile's
 * entity, so the typo was invisible until a coach opened the planner.
 */
final class TileEntitySeedTest extends WP_UnitTestCase {

    /** @var string */
    private $matrix_was = '';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        // The tile registry is populated during boot; a test process has not
        // necessarily been through it.
        \TT\Shared\CoreSurfaceRegistration::register();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCache();
    }

    public function tear_down(): void {
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCache();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function resetMatrixActiveCache(): void {
        $prop = new \ReflectionProperty( TileRegistry::class, 'matrix_active_cache' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );
    }

    /** @return array<string, true> every entity the shipped seed grants on */
    private function seededEntities(): array {
        $rows = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';
        $out  = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $entity = (string) ( $row['entity'] ?? '' );
            if ( $entity !== '' ) $out[ $entity ] = true;
        }
        return $out;
    }

    // ---- the drift guard ----

    public function test_every_tile_entity_is_a_seeded_matrix_entity(): void {
        $seeded = $this->seededEntities();
        $this->assertNotEmpty( $seeded, 'authorization_seed.php returned no rows' );

        $tiles = TileRegistry::allRegistered();
        $this->assertNotEmpty( $tiles, 'no tiles registered' );

        foreach ( $tiles as $tile ) {
            $entity = (string) ( $tile['entity'] ?? '' );
            if ( $entity === '' ) continue;
            $this->assertArrayHasKey(
                $entity,
                $seeded,
                sprintf(
                    'Tile ?tt_view=%s declares entity "%s", which the authorization seed does not grant on; with the matrix active nobody but an administrator could open it',
                    (string) ( $tile['view_slug'] ?? '' ),
                    $entity
                )
            );
        }
    }

    // ---- the reported surface ----

    public function test_the_tournaments_tile_declares_the_seeded_entity(): void {
        $this->assertSame( 'tournaments', TileRegistry::entityForViewSlug( 'tournaments' ) );
    }

    public function test_a_head_of_development_can_open_tournaments_with_the_matrix_on(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();

        $this->assertTrue( TileRegistry::canAccessViewSlug( 'tournaments', $uid ) );
    }

    public function test_a_head_coach_can_open_tournaments_with_the_matrix_on(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Toernooi O13-1', 'age_group' => 'U13' ] );
        $team_id = (int) $wpdb->insert_id;

        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Toernooi',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => 1,
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        AuthorizationService::flushCache();
        $this->assertContains( 'head_coach', PersonaResolver::personasFor( $uid ) );

        $this->assertTrue( TileRegistry::canAccessViewSlug( 'tournaments', $uid ) );
    }
}
