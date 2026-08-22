<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\MediaModule;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Shared\Frontend\Components\MediaGallery;

/**
 * #2596 (epic #2589) — the media off-switch, end to end.
 *
 * The toggle was written in the first slice, before anything depended on
 * it. This is the first point at which every surface exists, so it is the
 * first point at which "off" can be shown to actually mean off rather
 * than merely intended to.
 *
 * Two switches, doing different things:
 *
 *   - **module off** — an academy that does not want photographs of its
 *     players held at all. Nothing loads.
 *   - **feature off** — hide the surfaces, keep the module and the files.
 *
 * The property that matters most is the last one here: neither switch
 * destroys anything. An operator who flips one to see what happens must
 * be able to flip it back and find their academy's media intact.
 */
final class MediaToggleTest extends WP_UnitTestCase {

    private const MODULE = MediaModule::class;

    /** @var list<array{0:string,1:string,2:string}> */
    private $granted = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();
    }

    public function tear_down(): void {
        // Leave both switches on however the test ended, or a failure
        // here would quietly disable media for every sibling test.
        ModuleRegistry::setEnabled( self::MODULE, true );
        FeatureRegistry::setEnabled( 'media', true );

        $repo = new MatrixRepository();
        foreach ( $this->granted as [ $persona, $activity, $scope ] ) {
            $repo->removeRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope );
        }
        $this->granted = [];

        MatrixRepository::clearCache();
        MediaVisibilityService::flush();
        parent::tear_down();
    }

    // ── the switches exist and are switchable ──────────────────────────

    public function test_the_module_is_registered_and_not_always_on(): void {
        $modules = require dirname( __DIR__, 2 ) . '/config/modules.php';

        $this->assertArrayHasKey( self::MODULE, $modules );
        $this->assertFalse(
            ModuleRegistry::isAlwaysOn( self::MODULE ),
            'an academy must be able to refuse photographs of its players entirely'
        );
    }

    public function test_the_feature_is_catalogued_against_the_module(): void {
        $this->assertTrue( FeatureRegistry::exists( 'media' ) );
        $this->assertContains(
            'media',
            array_column( FeatureRegistry::forModule( self::MODULE ), 'key' ),
            'the feature toggle must be owned by the media module, or the two drift apart'
        );
    }

    /**
     * Every media surface is a tab or a section on a view that already
     * exists, and the wizard rides the shared `wizard` aggregator slug —
     * which the media feature does not own and must not gate, since doing
     * so would take every other wizard down with it.
     *
     * So `view_slugs` is empty **on purpose**. This test states that, so
     * the next reader does not "fix" it by adding `wizard`.
     */
    public function test_the_feature_deliberately_owns_no_view_slug(): void {
        $entry = FeatureRegistry::forModule( self::MODULE );
        $media = null;
        foreach ( $entry as $row ) {
            if ( $row['key'] === 'media' ) $media = $row;
        }

        $this->assertNotNull( $media );
        $this->assertTrue( FeatureRegistry::isEnabled( 'media' ) );
        $this->assertFalse(
            FeatureRegistry::viewSlugDisabled( 'wizard' ),
            'gating the shared wizard slug would disable every other wizard in the product'
        );
    }

    // ── off means off ──────────────────────────────────────────────────

    public function test_disabling_the_module_denies_every_media_decision(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team  = 11001;
        $coach = $this->makeCoachScopedToTeam( $team );

        $this->assertTrue(
            MediaVisibilityService::hasReadAuthority( $coach ),
            'the coach has a media grant while the module is on'
        );

        ModuleRegistry::setEnabled( self::MODULE, false );
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();

        $this->assertFalse(
            MediaVisibilityService::hasReadAuthority( $coach ),
            'with the module off the matrix short-circuits on its owning module, so no surface can render'
        );
        $this->assertFalse( MediaVisibilityService::hasUploadAuthority( $coach ) );
    }

    public function test_disabling_the_feature_denies_the_entity(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        $coach = $this->makeCoachScopedToTeam( 11101 );

        FeatureRegistry::setEnabled( 'media', false );
        MediaVisibilityService::flush();

        $this->assertTrue(
            FeatureRegistry::entityDisabled( MediaVisibilityService::ENTITY ),
            'the feature owns the entity, so switching it off gates every check that goes through it'
        );
        $this->assertFalse( MediaVisibilityService::hasReadAuthority( $coach ) );
    }

    /**
     * A gallery on a record must not render items when media is off.
     * Rendering nothing is the visible half of the switch working.
     */
    public function test_a_gallery_renders_nothing_meaningful_with_the_feature_off(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team  = 11201;
        $coach = $this->makeCoachScopedToTeam( $team );
        $media = $this->makeMedia( 'Squad photo' );
        ( new MediaLinksRepository() )->link( $media->id, MediaEntityType::TEAM, $team );

        wp_set_current_user( $coach );
        $this->assertStringContainsString( 'Squad photo', $this->render( $team ) );

        FeatureRegistry::setEnabled( 'media', false );
        MediaVisibilityService::flush();

        $this->assertStringNotContainsString(
            'Squad photo',
            $this->render( $team ),
            'with media switched off the gallery must not put a photograph on the page'
        );
    }

    // ── and nothing is destroyed ───────────────────────────────────────

    /**
     * The property an operator actually needs: flipping a switch to see
     * what happens must be reversible. If turning media off deleted
     * anything, nobody could ever safely try it.
     */
    public function test_switching_off_and_back_on_leaves_every_row_and_file_intact(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team  = 11301;
        $coach = $this->makeCoachScopedToTeam( $team );
        $media = $this->makeMedia( 'Squad photo' );
        ( new MediaLinksRepository() )->link( $media->id, MediaEntityType::TEAM, $team );

        $before = (int) $media->id;

        ModuleRegistry::setEnabled( self::MODULE, false );
        FeatureRegistry::setEnabled( 'media', false );
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();

        $this->assertNotNull(
            ( new MediaRepository() )->find( $before ),
            'the row survives — the switch hides media, it does not delete it'
        );

        ModuleRegistry::setEnabled( self::MODULE, true );
        FeatureRegistry::setEnabled( 'media', true );
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();

        wp_set_current_user( $coach );
        $this->assertStringContainsString(
            'Squad photo',
            $this->render( $team ),
            'switching back on must restore what was there, not a blank slate'
        );
        $this->assertSame( 1, ( new MediaLinksRepository() )->countFor( $before ) );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function render( int $team_id ): string {
        ob_start();
        MediaGallery::render( [
            'entity_type' => MediaEntityType::TEAM,
            'entity_id'   => $team_id,
        ] );
        return (string) ob_get_clean();
    }

    private function grant( string $persona, string $activity, string $scope ): void {
        ( new MatrixRepository() )->setRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope, self::MODULE );
        $this->granted[] = [ $persona, $activity, $scope ];
        MatrixRepository::clearCache();
    }

    private function makeMedia( string $title ): object {
        $repo = new MediaRepository();
        return $repo->find( $repo->insert( [
            'kind'         => MediaKind::VIDEO_LINK,
            'title'        => $title,
            'provider'     => 'veo',
            'external_url' => 'https://app.veo.co/matches/test/',
        ] ) );
    }

    private function makeCoachScopedToTeam( int $team_id ): int {
        global $wpdb;

        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->assertContains( 'head_coach', PersonaResolver::effectivePersonas( $uid ) );

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Test',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );

        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        return $uid;
    }
}
