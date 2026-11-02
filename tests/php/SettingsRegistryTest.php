<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Shared\Configuration\SettingsRegistry;
use TT\Shared\CoreSurfaceRegistration;
use TT\Shared\Tiles\NavigationGroups;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4670 — Configuration grouped by task, every setting once.
 *
 * The landing used to build one section per owning module, list every setup
 * tile a second time, show the access matrix twice, and offer no link at all
 * to custom fields, evaluation categories or their weights. These tests pin
 * the registry that replaced it: ten task sections in order, one home per
 * setting, a filter that finds a setting by the words an admin types, and a
 * REST route that returns only what the caller may open.
 */
final class SettingsRegistryTest extends WP_UnitTestCase {

    private const SPOND = 'TT\\Modules\\Spond\\SpondModule';

    /** @var list<array<string,mixed>> */
    private array $tiles_were = [];

    private int $admin = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        $this->tiles_were = TileRegistry::allRegistered();
        CoreSurfaceRegistration::register();
        SettingsRegistry::clear();
        AuthorizationService::flushCache();
        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    public function tear_down(): void {
        ModuleRegistry::setEnabled( self::SPOND, true );
        SettingsRegistry::clear();
        TileRegistry::clear();
        foreach ( $this->tiles_were as $tile ) {
            TileRegistry::register( $tile );
        }
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @return list<string> */
    private function keysFor( int $user_id ): array {
        return array_column( SettingsRegistry::entriesFor( $user_id ), 'key' );
    }

    /** @return array<string,array<string,mixed>> */
    private function byKey( int $user_id ): array {
        $out = [];
        foreach ( SettingsRegistry::entriesFor( $user_id ) as $entry ) $out[ $entry['key'] ] = $entry;
        return $out;
    }

    // ---- sections ----

    public function test_the_landing_shows_the_settings_sections_in_order(): void {
        $sections = SettingsRegistry::sectionsFor( $this->admin );
        $keys     = array_column( $sections, 'key' );

        $this->assertNotSame( [], $keys );
        $order = NavigationGroups::keysFor( NavigationGroups::SURFACE_SETTINGS );
        $this->assertSame( array_values( array_intersect( $order, $keys ) ), $keys, 'sections follow config/navigation_groups.php' );
        // Folding may merge a section an install has switched off modules
        // in, so the admin sees most of the ten, never fewer than eight.
        $this->assertGreaterThanOrEqual( 8, count( $keys ) );

        foreach ( $sections as $section ) {
            $this->assertSame( NavigationGroups::label( $section['key'] ), $section['label'] );
            $this->assertNotSame( '', $section['question'], $section['key'] . ' says which question it answers' );
            $this->assertGreaterThanOrEqual( SettingsRegistry::MIN_SECTION_SIZE, count( $section['entries'] ), $section['key'] );
        }
    }

    public function test_no_section_is_named_after_a_module(): void {
        $labels = array_column( SettingsRegistry::sectionsFor( $this->admin ), 'label' );
        foreach ( [ 'Academy-wide', 'Spond', 'Trials', 'Tournaments', 'Communication' ] as $module_label ) {
            $this->assertNotContains( $module_label, $labels );
        }
    }

    public function test_a_small_section_folds_into_the_next(): void {
        $entry    = static fn ( string $k ): array => [ 'key' => $k ];
        $sections = [
            [ 'key' => 'a', 'label' => 'A', 'question' => '', 'entries' => [ $entry( 'a1' ) ] ],
            [ 'key' => 'b', 'label' => 'B', 'question' => '', 'entries' => [ $entry( 'b1' ), $entry( 'b2' ), $entry( 'b3' ) ] ],
            [ 'key' => 'c', 'label' => 'C', 'question' => '', 'entries' => [ $entry( 'c1' ) ] ],
        ];

        $folded = SettingsRegistry::fold( $sections );

        $this->assertSame( [ 'b' ], array_column( $folded, 'key' ) );
        $this->assertSame( [ 'a1', 'b1', 'b2', 'b3', 'c1' ], array_column( $folded[0]['entries'], 'key' ) );
    }

    // ---- one home per setting ----

    public function test_every_entry_appears_once(): void {
        $keys = $this->keysFor( $this->admin );
        $this->assertSame( array_values( array_unique( $keys ) ), $keys );

        $urls = array_column( SettingsRegistry::entriesFor( $this->admin ), 'url' );
        $count = static function ( string $needle ) use ( $urls ): int {
            return count( array_filter( $urls, static fn ( string $u ): bool => strpos( $u, $needle ) !== false ) );
        };
        $this->assertSame( 1, $count( 'tt_view=matrix' ), 'one access matrix entry, not a matrix and an authorization matrix' );
        $this->assertSame( 1, $count( 'config_sub=football-form' ), 'football form once' );
        $this->assertSame( 1, $count( 'config_sub=rating' ), 'rating scale once' );
    }

    public function test_settings_that_had_no_link_are_reachable(): void {
        $entries = $this->byKey( $this->admin );
        foreach ( [ 'custom-fields' => 'custom-fields', 'eval-categories' => 'eval-categories', 'eval-category-weights' => 'eval-category-weights' ] as $key => $slug ) {
            $this->assertArrayHasKey( $key, $entries );
            $this->assertStringContainsString( 'tt_view=' . $slug, $entries[ $key ]['url'] );
        }
    }

    public function test_setup_tiles_are_not_listed_a_second_time(): void {
        $labels = array_column( SettingsRegistry::entriesFor( $this->admin ), 'label' );
        $this->assertSame( array_values( array_unique( $labels ) ), $labels, 'two entries with one label' );
        $this->assertNotContains( 'configuration', $this->keysFor( $this->admin ), 'the door is not a room inside itself' );
    }

    // ---- filter ----

    /** @return array<string, array{0:string, 1:string}> */
    public static function queries(): array {
        return [
            'logo'      => [ 'logo', 'appearance' ],
            'weighting' => [ 'weighting', 'eval-category-weights' ],
            'spond'     => [ 'spond', 'spond' ],
            '2fa'       => [ '2fa', 'mfa-required' ],
            'accents'   => [ 'LÓGO', 'appearance' ],
        ];
    }

    /** @dataProvider queries */
    public function test_the_filter_finds_a_setting_by_what_an_admin_types( string $query, string $expected ): void {
        $found = array_column(
            array_filter( SettingsRegistry::entriesFor( $this->admin ), static fn ( array $e ): bool => SettingsRegistry::matches( $e, $query ) ),
            'key'
        );
        $this->assertContains( $expected, $found );
    }

    public function test_the_filter_finds_nothing_for_nonsense(): void {
        $found = array_filter( SettingsRegistry::entriesFor( $this->admin ), static fn ( array $e ): bool => SettingsRegistry::matches( $e, 'qqxzzv' ) );
        $this->assertSame( [], $found );
    }

    // ---- who sees what ----

    public function test_a_head_of_development_sees_no_system_section_and_no_matrix(): void {
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();

        $this->assertNotContains( 'system', array_column( SettingsRegistry::sectionsFor( $hod ), 'key' ) );
        $this->assertNotContains( 'system', array_column( SettingsRegistry::entriesFor( $hod ), 'section' ) );
        $this->assertNotContains( 'matrix', $this->keysFor( $hod ) );
    }

    public function test_an_admin_sees_a_switched_off_module_marked_off(): void {
        ModuleRegistry::setEnabled( self::SPOND, false );
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        $entries = $this->byKey( $this->admin );
        $this->assertArrayHasKey( 'spond', $entries );
        $this->assertSame( SettingsRegistry::STATE_OFF, $entries['spond']['module_state'] );
        $this->assertSame( 'features_plan', $entries['spond']['section'] );
        $this->assertStringContainsString( 'tt_view=modules', $entries['spond']['url'], 'the link leads to where it is turned on' );

        $this->assertNotContains( 'spond', $this->keysFor( $hod ), 'hidden from anyone who cannot switch it on' );
    }

    public function test_an_entry_with_a_module_that_is_on_says_so(): void {
        $entries = $this->byKey( $this->admin );
        $this->assertSame( SettingsRegistry::STATE_ON, $entries['spond']['module_state'] );
        $this->assertSame( SettingsRegistry::STATE_NONE, $entries['general']['module_state'] );
    }

    // ---- the deprecated filter ----

    public function test_a_contributed_tile_for_a_screen_with_an_entry_is_not_added_twice(): void {
        $add = static function ( array $groups ): array {
            $groups[] = [ 'label' => 'Extra', 'tiles' => [
                [ 'label' => 'Authorization matrix', 'url' => home_url( '/?tt_view=matrix' ), 'cap' => 'read' ],
                [ 'label' => 'Add-on settings', 'url' => home_url( '/?tt_view=addon-settings' ), 'cap' => 'read' ],
            ] ];
            return $groups;
        };
        add_filter( 'tt_config_tile_groups', $add );
        $entries = SettingsRegistry::entriesFor( $this->admin );
        remove_filter( 'tt_config_tile_groups', $add );

        $labels = array_column( $entries, 'label' );
        $this->assertNotContains( 'Authorization matrix', $labels );
        $this->assertContains( 'Add-on settings', $labels );
        $addon = array_values( array_filter( $entries, static fn ( array $e ): bool => $e['label'] === 'Add-on settings' ) );
        $this->assertSame( 'academy', $addon[0]['section'] );
    }

    // ---- REST ----

    /** @return array{0:int, 1:list<array<string,mixed>>} */
    private function rest( int $user_id, string $q = '' ): array {
        wp_set_current_user( $user_id );
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/settings-entries' );
        if ( $q !== '' ) $req->set_param( 'q', $q );
        $res  = rest_get_server()->dispatch( $req );
        $data = (array) $res->get_data();
        return [ $res->get_status(), (array) ( $data['data']['entries'] ?? [] ) ];
    }

    public function test_rest_returns_the_admins_entries_in_the_documented_shape(): void {
        [ $status, $entries ] = $this->rest( $this->admin );

        $this->assertSame( 200, $status );
        $this->assertSame( $this->keysFor( $this->admin ), array_column( $entries, 'key' ) );
        $this->assertSame(
            [ 'key', 'section', 'section_label', 'label', 'description', 'keywords', 'url', 'module_state' ],
            array_keys( $entries[0] )
        );
    }

    public function test_rest_narrows_by_query(): void {
        [ , $entries ] = $this->rest( $this->admin, 'spond' );
        $this->assertContains( 'spond', array_column( $entries, 'key' ) );
        $this->assertNotContains( 'general', array_column( $entries, 'key' ) );
    }

    public function test_rest_returns_the_head_of_development_only_what_they_may_open(): void {
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();

        [ $status, $entries ] = $this->rest( $hod );
        $keys = array_column( $entries, 'key' );

        $this->assertSame( 200, $status );
        $this->assertSame( $this->keysFor( $hod ), $keys );
        $this->assertNotContains( 'matrix', $keys );
        $this->assertNotContains( 'system', array_column( $entries, 'section' ) );
    }

    public function test_rest_gives_a_coach_nothing_they_may_not_open(): void {
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        AuthorizationService::flushCache();

        [ $status, $entries ] = $this->rest( $coach );
        $keys = array_column( $entries, 'key' );

        if ( $status === 403 ) {
            $this->assertSame( [], $this->keysFor( $coach ), 'refused only when there is nothing to open' );
            return;
        }
        $this->assertSame( 200, $status );
        $this->assertSame( $this->keysFor( $coach ), $keys );
        foreach ( [ 'matrix', 'general', 'appearance', 'backups', 'modules' ] as $admin_only ) {
            $this->assertNotContains( $admin_only, $keys );
        }
    }

    public function test_rest_refuses_a_visitor(): void {
        [ $status ] = $this->rest( 0 );
        $this->assertContains( $status, [ 401, 403 ] );
    }

    // ---- the CI gate ----

    public function test_the_gate_passes_on_the_repository_and_fails_on_a_planted_route(): void {
        require_once dirname( __DIR__, 2 ) . '/tools/lib/settings-entries.php';
        $root   = dirname( __DIR__, 2 );
        $config = tt_settings_require( $root . '/config/settings_entries.php' );
        $routes = tt_settings_routes( $root );

        $this->assertContains( 'sub:rating', $routes );
        $this->assertContains( 'tile:audit-log', $routes );
        $this->assertSame( [], tt_settings_missing( $routes, $config ) );

        $planted = array_merge( $routes, [ 'view:tt-planted-settings', 'sub:planted-form' ] );
        $this->assertSame( [ 'view:tt-planted-settings', 'sub:planted-form' ], tt_settings_missing( $planted, $config ) );
    }

    public function test_the_gate_reports_an_entry_declared_twice(): void {
        require_once dirname( __DIR__, 2 ) . '/tools/lib/settings-entries.php';
        $config = [ 'entries' => [
            [ 'key' => 'one', 'section' => 'academy' ],
            [ 'key' => 'one', 'section' => 'academy' ],
            [ 'key' => 'two', 'section' => 'people_teams' ],
        ] ];
        $problems = tt_settings_problems( $config, NavigationGroups::keysFor( NavigationGroups::SURFACE_SETTINGS ) );

        $this->assertCount( 2, $problems );
    }
}
