<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use WP_REST_Request;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Shared\Frontend\Components\MediaGallery;

/**
 * #2745 — the gallery pages instead of rendering a whole season at once.
 *
 * The property worth guarding is not "24 tiles appear". It is that paging
 * cannot lose an item: `filterVisible()` runs after the query, so an offset
 * advanced by the number of visible tiles would step over whatever the
 * filter removed, and those items would become unreachable — a silent data
 * loss that looks exactly like "the coach never uploaded it".
 */
final class MediaPagingTest extends WP_UnitTestCase {

    /** @var list<array{0:string,1:string,2:string}> */
    private $granted = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        foreach ( $this->granted as [ $persona, $activity, $scope ] ) {
            ( new MatrixRepository() )->removeRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope );
        }
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_the_repository_pages_without_repeating_or_skipping(): void {
        $team = 9901;
        $this->attach( $team, 7 );

        $repo = new MediaRepository();

        $page1 = $repo->listForEntity( MediaEntityType::TEAM, $team, false, 3, 0 );
        $page2 = $repo->listForEntity( MediaEntityType::TEAM, $team, false, 3, 3 );
        $page3 = $repo->listForEntity( MediaEntityType::TEAM, $team, false, 3, 6 );

        $this->assertCount( 3, $page1 );
        $this->assertCount( 3, $page2 );
        $this->assertCount( 1, $page3 );

        $seen = array_merge(
            array_column( $page1, 'uuid' ),
            array_column( $page2, 'uuid' ),
            array_column( $page3, 'uuid' )
        );

        $this->assertCount( 7, $seen, 'every stored item is reachable' );
        $this->assertSame( $seen, array_unique( $seen ), 'and none is handed out twice' );
    }

    public function test_no_limit_still_means_every_row(): void {
        $team = 9902;
        $this->attach( $team, 5 );

        $this->assertCount(
            5,
            ( new MediaRepository() )->listForEntity( MediaEntityType::TEAM, $team ),
            'the default has to stay unpaged — other callers rely on it'
        );
    }

    public function test_the_gallery_caps_the_page_and_offers_more(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team = 9903;
        $this->attach( $team, MediaGallery::PAGE_SIZE + 3 );
        wp_set_current_user( $this->makeCoachScopedToTeam( $team ) );

        $html = $this->render( $team );

        $this->assertSame(
            MediaGallery::PAGE_SIZE,
            substr_count( $html, 'class="tt-media-tile' ),
            'a page is capped regardless of how much is stored'
        );
        $this->assertStringContainsString( 'data-role="more"', $html );
        $this->assertStringContainsString( 'data-offset="' . MediaGallery::PAGE_SIZE . '"', $html );
    }

    public function test_no_button_when_everything_already_fits(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team = 9904;
        $this->attach( $team, 3 );
        wp_set_current_user( $this->makeCoachScopedToTeam( $team ) );

        $html = $this->render( $team );

        $this->assertSame( 3, substr_count( $html, 'class="tt-media-tile' ) );
        $this->assertStringNotContainsString( 'data-role="more"', $html );
    }

    /**
     * #2717's badge counts what the academy holds, not what one page
     * happens to render — otherwise a player with 30 photos would show 24.
     */
    public function test_the_tab_badge_still_counts_everything(): void {
        $player = $this->makePlayer( 9905 );
        $links  = new MediaLinksRepository();

        for ( $i = 0; $i < MediaGallery::PAGE_SIZE + 6; $i++ ) {
            $links->link( $this->makeMedia( 'Item ' . $i )->id, MediaEntityType::PLAYER, $player );
        }

        $this->assertSame(
            MediaGallery::PAGE_SIZE + 6,
            PlayerFileCounts::for( $player )['media']
        );
    }

    public function test_the_endpoint_reports_the_next_offset_and_whether_more_remains(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team = 9906;
        $this->attach( $team, 5 );
        wp_set_current_user( $this->makeCoachScopedToTeam( $team ) );

        $first = $this->get( $team, 2, 0 );
        $this->assertCount( 2, $first['items'] );
        $this->assertTrue( $first['has_more'] );
        $this->assertSame( 2, $first['next_offset'] );

        $last = $this->get( $team, 2, 4 );
        $this->assertCount( 1, $last['items'] );
        $this->assertFalse( $last['has_more'] );
    }

    public function test_tiles_are_opt_in(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team = 9907;
        $this->attach( $team, 2 );
        wp_set_current_user( $this->makeCoachScopedToTeam( $team ) );

        $plain = $this->get( $team, 2, 0 );
        $this->assertArrayNotHasKey( 'tile_html', $plain['items'][0], 'a data consumer should not be sent markup' );

        $withTiles = $this->get( $team, 2, 0, true );
        $this->assertArrayHasKey( 'tile_html', $withTiles['items'][0] );
        $this->assertStringContainsString( 'tt-media-tile', $withTiles['items'][0]['tile_html'] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function get( int $team, int $limit, int $offset, bool $tiles = false ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/media' );
        $request->set_param( 'entity_type', MediaEntityType::TEAM );
        $request->set_param( 'entity_id', $team );
        $request->set_param( 'limit', $limit );
        $request->set_param( 'offset', $offset );
        if ( $tiles ) $request->set_param( 'with_tiles', 1 );

        return (array) rest_do_request( $request )->get_data()['data'];
    }

    private function render( int $team ): string {
        ob_start();
        MediaGallery::render( [
            'entity_type' => MediaEntityType::TEAM,
            'entity_id'   => $team,
        ] );
        return (string) ob_get_clean();
    }

    private function attach( int $team, int $count ): void {
        $links = new MediaLinksRepository();
        for ( $i = 0; $i < $count; $i++ ) {
            $links->link( $this->makeMedia( 'Item ' . $i )->id, MediaEntityType::TEAM, $team );
        }
    }

    private function grant( string $persona, string $activity, string $scope ): void {
        ( new MatrixRepository() )->setRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope, '' );
        $this->granted[] = [ $persona, $activity, $scope ];
        MatrixRepository::clearCache();
    }

    private function makeMedia( string $title ): object {
        $repo = new MediaRepository();
        return $repo->find( $repo->insert( [
            'kind'            => MediaKind::IMAGE,
            'title'           => $title,
            'storage_adapter' => 'local_private',
            'storage_key'     => 'ab/cd/' . str_repeat( 'a', 32 ) . '.jpg',
            'mime_type'       => 'image/jpeg',
            'captured_at'     => '2026-08-14 18:00:00',
        ] ) );
    }

    private function makePlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Paged',
            'last_name'  => 'Player',
            'team_id'    => $team_id,
            'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeCoachScopedToTeam( int $team_id ): int {
        global $wpdb;

        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->assertContains( 'head_coach', PersonaResolver::effectivePersonas( $uid ) );

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Paged',
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
