<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\PersonaDashboard\Frontend\PersonaLandingRenderer;
use TT\Shared\Frontend\Components\FrontendAppNav;
use TT\Shared\Frontend\FrontendTileGrid;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4449 — the landing, the coach's week, the tile grid and the shell nav's
 * role label all ask `PersonaResolver::landingPersona()`: the switcher's
 * choice, else the first persona the user holds. The shell nav used to ask
 * `activePersona()` alone, which is null until somebody uses the switcher,
 * so it showed no role under their name.
 */
final class LandingPersonaFallbackTest extends WP_UnitTestCase {

    /** @var list<array<string,mixed>> */
    private array $tiles_were = [];
    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        $this->tiles_were = TileRegistry::allRegistered();
        TileRegistry::clear();
        \TT\Shared\CoreSurfaceRegistration::register();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Landing JO12', 'age_group' => 'U12' ] );
        $this->team = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        TileRegistry::clear();
        foreach ( $this->tiles_were as $tile ) {
            TileRegistry::register( $tile );
        }
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_no_persona_fallback_is_hand_rolled_outside_the_resolver(): void {
        $rii = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( TT_PLUGIN_DIR . 'src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $rii as $file ) {
            if ( $file->getExtension() !== 'php' || $file->getFilename() === 'PersonaResolver.php' ) continue;
            $source = (string) file_get_contents( $file->getPathname() );
            $this->assertStringNotContainsString( 'PersonaResolver::activePersona(', $source, $file->getFilename() . ' reads the switcher choice without the fallback' );
        }
    }

    public function test_a_head_coach_who_never_switched_lands_as_head_coach_everywhere(): void {
        $uid = $this->headCoach();
        delete_user_meta( $uid, 'tt_active_persona' );
        wp_set_current_user( $uid );

        $this->assertNull( PersonaResolver::activePersona( $uid ) );
        $this->assertSame( 'head_coach', PersonaResolver::landingPersona( $uid ) );

        $this->assertLandingDressedAs( 'head_coach' );
        $this->assertTrue( CoachWeek::visibleFor( $uid ), 'the coach sees their week' );
        $this->assertSame( 'Head Coach', $this->navRole( $uid ) );
    }

    public function test_all_three_follow_the_switch(): void {
        $uid = $this->headCoach();
        ( new \WP_User( $uid ) )->add_role( 'tt_parent' );
        AuthorizationService::flushCache();
        wp_set_current_user( $uid );
        $this->assertContains( 'parent', PersonaResolver::personasFor( $uid ) );

        update_user_meta( $uid, 'tt_active_persona', 'parent' );
        $this->assertLandingDressedAs( 'parent' );
        $this->assertFalse( CoachWeek::visibleFor( $uid ), 'no coach week while dressed as a parent' );
        $this->assertSame( 'Parent', $this->navRole( $uid ) );

        update_user_meta( $uid, 'tt_active_persona', 'head_coach' );
        $this->assertLandingDressedAs( 'head_coach' );
        $this->assertTrue( CoachWeek::visibleFor( $uid ) );
        $this->assertSame( 'Head Coach', $this->navRole( $uid ) );
    }

    public function test_a_players_tile_grid_still_treats_them_as_a_player(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        wp_set_current_user( $uid );
        $this->assertSame( 'player', PersonaResolver::landingPersona( $uid ) );

        ob_start();
        FrontendTileGrid::render();
        $html = (string) ob_get_clean();

        // The grid prints its own <style> block naming these classes, so
        // match the markup, not the bare class name.
        $this->assertStringNotContainsString( '<aside class="tt-ftile-rail"', $html, 'a player gets no "My work" rail' );
        $this->assertDoesNotMatchRegularExpression( '/class="[^"]*tt-ftile-daily--has-week/', $html, 'and no coach week' );
        $this->assertStringContainsString( 'class="tt-ftile-daily', $html, 'the work grid rendered at all' );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * The landing reads `persona_dashboard.<persona>.enabled` for the persona
     * it is dressed as; switching that one off is how to see which it chose.
     */
    private function assertLandingDressedAs( string $persona ): void {
        QueryHelpers::set_config( 'persona_dashboard.enabled', '1' );
        QueryHelpers::set_config( 'persona_dashboard.' . $persona . '.enabled', '0' );
        $this->assertFalse( PersonaLandingRenderer::shouldRender(), 'the landing reads the ' . $persona . ' setting' );
        QueryHelpers::set_config( 'persona_dashboard.' . $persona . '.enabled', '' );
        $this->assertTrue( PersonaLandingRenderer::shouldRender() );
    }

    private function navRole( int $uid ): string {
        ob_start();
        FrontendAppNav::render( $uid );
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( 'tt-shell-nav__user', $html, 'the nav renders its user block' );
        if ( ! preg_match( '#<span class="tt-shell-nav__role">([^<]*)</span>#', $html, $m ) ) return '';
        return html_entity_decode( $m[1] );
    }

    private function headCoach(): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Hoofd', 'last_name' => 'Landing',
            'role_type' => 'head_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'team_id' => $this->team, 'person_id' => $person_id, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id' => 1, 'person_id' => $person_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }
}
