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
 * #4391 — the methodology vocabulary tile shows only to who may edit.
 *
 * The editor tile sat on `methodology`, which the coaches and the scout
 * read, while its view requires `tt_edit_methodology`. Tile visibility
 * now sits on `methodology_vocabulary_panel`, seeded to the head of
 * development and the academy admin; the staff read of `methodology`
 * stays, because the Methodology read tile and the REST reads use it.
 */
final class MethodologyVocabularyTileTest extends WP_UnitTestCase {

    private const PANEL = 'methodology_vocabulary_panel';

    /** @var string */
    private $matrix_was = '';

    /** @var list<array<string,mixed>> */
    private $tiles_were = [];

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();

        // The registry is filled during boot and earlier tests may have
        // cleared it; fill it here and put it back afterwards.
        $this->tiles_were = TileRegistry::allRegistered();
        TileRegistry::clear();
        \TT\Shared\CoreSurfaceRegistration::register();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCache();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Methode O15', 'age_group' => 'U15' ] );
        $this->team = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCache();
        TileRegistry::clear();
        foreach ( $this->tiles_were as $tile ) {
            TileRegistry::register( $tile );
        }
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

    // ---- the seed and the tile ----

    public function test_the_panel_is_seeded_to_exactly_the_personas_who_edit_the_methodology(): void {
        $rows     = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';
        $personas = [];
        foreach ( $rows as $row ) {
            if ( ( $row['entity'] ?? '' ) !== self::PANEL ) continue;
            $this->assertSame( 'read', $row['activity'] );
            $this->assertSame( 'global', $row['scope_kind'] );
            $personas[] = (string) $row['persona'];
        }
        sort( $personas );

        $this->assertSame( [ 'academy_admin', 'head_of_development' ], $personas );
    }

    public function test_the_editor_tile_declares_the_panel_entity(): void {
        $this->assertSame( self::PANEL, TileRegistry::entityForViewSlug( 'methodology-vocabulary' ) );
        $this->assertSame( 'methodology', TileRegistry::entityForViewSlug( 'methodology' ) );
    }

    // ---- who sees it ----

    public function test_coaches_and_the_scout_keep_the_read_tile_and_lose_the_editor_tile(): void {
        $users = [
            'head coach'      => $this->makeCoach( true ),
            'assistant coach' => $this->makeCoach( false ),
            'scout'           => self::factory()->user->create( [ 'role' => 'tt_scout' ] ),
        ];
        AuthorizationService::flushCache();

        foreach ( $users as $who => $uid ) {
            $this->assertTrue( TileRegistry::canAccessViewSlug( 'methodology', $uid ), $who . ' keeps the Methodology read tile' );
            $this->assertFalse( TileRegistry::canAccessViewSlug( 'methodology-vocabulary', $uid ), $who . ' no longer sees the vocabulary editor tile' );
        }
    }

    public function test_the_head_of_development_and_the_academy_admin_see_the_editor_tile(): void {
        foreach ( [ 'tt_head_dev', 'tt_club_admin' ] as $role ) {
            $uid = self::factory()->user->create( [ 'role' => $role ] );
            AuthorizationService::flushCache();

            $this->assertTrue( TileRegistry::canAccessViewSlug( 'methodology-vocabulary', $uid ), $role );
            $this->assertTrue( user_can( $uid, \TT\Modules\Methodology\Frontend\FrontendMethodologyVocabularyView::CAP ), $role . ' opens the editor' );
        }
    }

    // ---- the top-up ----

    public function test_the_migration_adds_the_panel_rows_once(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        $wpdb->delete( $table, [ 'entity' => self::PANEL ] );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0302_authorization_methodology_vocabulary_panel.php';
        $migration->up();
        // Twice, because a re-run must add nothing.
        $migration->up();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT persona, activity, scope_kind FROM {$table} WHERE entity = %s ORDER BY persona",
                self::PANEL
            ),
            ARRAY_A
        );

        $this->assertSame(
            [
                [ 'persona' => 'academy_admin', 'activity' => 'read', 'scope_kind' => 'global' ],
                [ 'persona' => 'head_of_development', 'activity' => 'read', 'scope_kind' => 'global' ],
            ],
            $rows
        );
    }

    // ---- fixtures ----

    private function makeCoach( bool $is_head_coach ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => $is_head_coach ? 'Hoofd' : 'Assistent',
            'last_name'  => 'Methode',
            'role_type'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $this->team,
            'person_id'     => $person_id,
            'role_in_team'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'is_head_coach' => $is_head_coach ? 1 : 0,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->team,
        ] );

        AuthorizationService::flushCache();
        $this->assertContains( $is_head_coach ? 'head_coach' : 'assistant_coach', PersonaResolver::personasFor( $uid ) );
        return $uid;
    }
}
