<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
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
 * #2594 (epic #2589) — the player media tab.
 *
 * Rendered markup is asserted rather than eyeballed for the handful of
 * properties that are easy to break and expensive to notice: that a
 * gallery never emits an item the viewer may not see, that video is not
 * told to download itself, that tiles reserve space, and that no
 * filesystem detail reaches the page.
 */
final class MediaGalleryTest extends WP_UnitTestCase {

    /** @var list<array{0:string,1:string,2:string}> */
    private $granted = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();
    }

    public function tear_down(): void {
        $repo = new MatrixRepository();
        foreach ( $this->granted as [ $persona, $activity, $scope ] ) {
            $repo->removeRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope );
        }
        $this->granted = [];
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();
        parent::tear_down();
    }

    // ── visibility ─────────────────────────────────────────────────────

    /**
     * The gallery is scoped to one record, but reaching that record does
     * not entitle a viewer to everything hanging off it. Every item is
     * filtered again on the way out.
     */
    public function test_a_gallery_never_renders_an_item_the_viewer_cannot_see(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team   = 9101;
        $coach  = $this->makeCoachScopedToTeam( $team );
        $player = $this->makePlayer( 9199 ); // a player on nobody's team

        $visible = $this->makeMedia( 'Visible clip' );
        ( new MediaLinksRepository() )->link( $visible->id, MediaEntityType::PLAYER, $player );
        ( new MediaLinksRepository() )->link( $visible->id, MediaEntityType::TEAM, $team );

        $hidden = $this->makeMedia( 'Hidden clip' );
        ( new MediaLinksRepository() )->link( $hidden->id, MediaEntityType::PLAYER, $player );

        wp_set_current_user( $coach );
        $html = $this->renderFor( MediaEntityType::PLAYER, $player );

        $this->assertStringContainsString( 'Visible clip', $html );
        $this->assertStringNotContainsString(
            'Hidden clip',
            $html,
            'the item is attached to this player but the coach has no route to it'
        );
    }

    public function test_an_empty_gallery_explains_itself(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team   = 9201;
        $coach  = $this->makeCoachScopedToTeam( $team );
        $player = $this->makePlayer( $team );

        wp_set_current_user( $coach );
        $html = $this->renderFor( MediaEntityType::PLAYER, $player, 'Nothing here', 'Add something.' );

        $this->assertStringContainsString( 'Nothing here', $html );
        $this->assertStringContainsString( 'Add something.', $html );
    }

    // ── mobile + performance properties ────────────────────────────────

    /**
     * A tab holding several clips must not pull the video down when it
     * opens. `preload="auto"` here would spend a coach's data plan on
     * footage nobody pressed play on.
     */
    public function test_video_is_never_preloaded_eagerly(): void {
        $html = $this->renderWithOneItem( MediaKind::VIDEO );

        $this->assertStringNotContainsString( 'preload="auto"', $html );
        // The tile only carries a poster and a link; the video element
        // itself is built by the lightbox, on open.
        $this->assertStringNotContainsString( '<video', $html );
    }

    public function test_images_are_lazy_and_reserve_their_space(): void {
        $html = $this->renderWithOneItem( MediaKind::IMAGE );

        $this->assertStringContainsString( 'loading="lazy"', $html );
        $this->assertStringContainsString( 'tt-media-tile__open', $html );
    }

    /**
     * No path, no uploads URL, no storage key — the page only ever knows
     * the REST address (CLAUDE.md §4).
     */
    public function test_the_markup_discloses_no_storage_detail(): void {
        $html = $this->renderWithOneItem( MediaKind::IMAGE );

        $this->assertStringNotContainsString( 'wp-content/uploads', $html );
        $this->assertStringNotContainsString( 'tt-media/', $html );

        // Decoded first: with plain permalinks the route rides inside a
        // ?rest_route= parameter, where add_query_arg percent-encodes the
        // slashes. Same endpoint, different spelling.
        $this->assertStringContainsString( '/talenttrack/v1/media/', $this->decoded( $html ) );
    }

    /**
     * Hover does not exist on touch, so an action that only appears on
     * hover is unreachable for the person standing at the pitch.
     */
    public function test_the_remove_action_is_present_in_the_markup_not_hover_revealed(): void {
        $html = $this->renderWithOneItem( MediaKind::IMAGE, true );

        $this->assertStringContainsString( 'data-role="delete"', $html );
        $this->assertStringContainsString( 'aria-label=', $html );
    }

    public function test_a_reader_without_write_rights_gets_no_remove_control(): void {
        $html = $this->renderWithOneItem( MediaKind::IMAGE, false );

        $this->assertStringNotContainsString( 'data-role="delete"', $html );
    }

    /**
     * A link tile leaves the site, so it must open safely and say where
     * it is going.
     */
    public function test_external_links_open_safely_and_name_their_provider(): void {
        $html = $this->renderWithOneItem( MediaKind::VIDEO_LINK );

        $this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
        $this->assertStringContainsString( 'target="_blank"', $html );
        $this->assertStringContainsString( 'Veo', $html );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * #2742 — an upload used to be invisible until the page was reloaded,
     * partly because an empty gallery rendered no gallery at all: there was
     * no grid for the new tile to go into.
     */
    public function test_an_empty_gallery_still_has_a_grid_to_insert_into(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team  = 9801;
        $coach = $this->makeCoachScopedToTeam( $team );
        wp_set_current_user( $coach );

        $html = $this->renderFor( MediaEntityType::PLAYER, $this->makePlayer( $team ), 'Nothing here', '', true );

        $this->assertStringContainsString( 'Nothing here', $html, 'the empty state still explains itself' );
        $this->assertStringContainsString( 'class="tt-media-gallery"', $html );
        $this->assertStringContainsString( 'data-role="grid"', $html );
        $this->assertStringContainsString( 'data-role="empty"', $html, 'JS needs a handle to remove it' );
        $this->assertStringContainsString( 'data-role="lightbox"', $html, 'the first upload must be openable' );
    }

    /**
     * #2742 — the tile is rendered server-side and handed back with the
     * upload. Building it in JS from the payload would have re-created
     * #2715, because `_links` carry no nonce.
     */
    public function test_a_tile_can_be_rendered_on_its_own_for_the_rest_layer(): void {
        $media = $this->makeMedia( 'Fresh upload', MediaKind::IMAGE );

        $html = MediaGallery::tileHtml( $media, true );

        $this->assertStringContainsString( 'tt-media-tile', $html );
        $this->assertStringContainsString( 'Fresh upload', $html );
        $this->assertStringContainsString( 'data-role="open"', $html, 'must open in the lightbox like any other tile' );
        $this->assertStringContainsString( 'data-role="delete"', $html, 'can_edit means a remove control' );
        $this->assertStringContainsString( '_wpnonce=', $this->decoded( $html ), 'or the browser gets a 401' );
    }

    public function test_a_rendered_tile_omits_the_remove_control_for_a_reader(): void {
        $html = MediaGallery::tileHtml( $this->makeMedia( 'Read only', MediaKind::IMAGE ), false );

        $this->assertStringNotContainsString( 'data-role="delete"', $html );
    }

    /**
     * #2715 — the tile's <img> is the thing that was broken in the wild.
     * Assert the rendered markup, not just the URL builder, because the
     * builder was right and the view was bypassing it.
     */
    public function test_tile_image_urls_carry_the_nonce(): void {
        $html = $this->decoded( $this->renderWithOneItem( MediaKind::IMAGE ) );

        $this->assertMatchesRegularExpression(
            '#<img[^>]+src="[^"]*/talenttrack/v1/media/[^"]+_wpnonce=[a-z0-9]+#i',
            $html,
            'thumbnail <img> must carry a nonce or the browser gets a 401'
        );
        $this->assertMatchesRegularExpression(
            '#data-src="[^"]*/talenttrack/v1/media/[^"]+_wpnonce=[a-z0-9]+#i',
            $html,
            'the lightbox / video source must carry one too'
        );
    }

    /**
     * Rendered URLs are HTML-escaped, and under plain permalinks the route
     * is percent-encoded inside ?rest_route=. Normalise both so assertions
     * describe the endpoint rather than the install's permalink setting.
     */
    private function decoded( string $html ): string {
        return rawurldecode( html_entity_decode( $html, ENT_QUOTES ) );
    }

    /**
     * #2717 — the Media tab badge read a key PlayerFileCounts never set,
     * so a player with photos showed a bare tab. Pinned against the same
     * scope the gallery lists, so badge and tiles cannot disagree.
     */
    public function test_media_count_tracks_what_the_gallery_shows(): void {
        $team   = 9701;
        $player = $this->makePlayer( $team );
        $links  = new MediaLinksRepository();

        $this->assertSame( 0, PlayerFileCounts::for( $player )['media'] );

        $a = $this->makeMedia( 'One' );
        $b = $this->makeMedia( 'Two' );
        $links->link( $a->id, MediaEntityType::PLAYER, $player );
        $links->link( $b->id, MediaEntityType::PLAYER, $player );

        $this->assertSame( 2, PlayerFileCounts::for( $player )['media'] );

        // Media on somebody else must not leak into this player's badge.
        $other = $this->makePlayer( $team );
        $links->link( $this->makeMedia( 'Theirs' )->id, MediaEntityType::PLAYER, $other );

        $this->assertSame( 2, PlayerFileCounts::for( $player )['media'] );
    }

    public function test_archived_media_is_not_counted(): void {
        global $wpdb;

        $player = $this->makePlayer( 9702 );
        $media  = $this->makeMedia( 'Archived later' );
        ( new MediaLinksRepository() )->link( $media->id, MediaEntityType::PLAYER, $player );

        $this->assertSame( 1, PlayerFileCounts::for( $player )['media'] );

        $wpdb->update(
            $wpdb->prefix . 'tt_media',
            [ 'archived_at' => current_time( 'mysql' ) ],
            [ 'id' => $media->id ]
        );

        $this->assertSame( 0, PlayerFileCounts::for( $player )['media'] );
    }

    private function renderWithOneItem( string $kind, bool $can_edit = false ): string {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team  = 9301 + crc32( $kind ) % 500;
        $coach = $this->makeCoachScopedToTeam( $team );

        $media = $this->makeMedia( 'A clip', $kind );
        ( new MediaLinksRepository() )->link( $media->id, MediaEntityType::TEAM, $team );

        wp_set_current_user( $coach );
        return $this->renderFor( MediaEntityType::TEAM, $team, '', '', $can_edit );
    }

    private function renderFor(
        string $type,
        int $id,
        string $headline = '',
        string $explainer = '',
        bool $can_edit = false
    ): string {
        ob_start();
        MediaGallery::render( array_filter( [
            'entity_type'     => $type,
            'entity_id'       => $id,
            'can_edit'        => $can_edit,
            'empty_headline'  => $headline,
            'empty_explainer' => $explainer,
        ], static function ( $v ) { return $v !== ''; } ) );
        return (string) ob_get_clean();
    }

    private function grant( string $persona, string $activity, string $scope ): void {
        ( new MatrixRepository() )->setRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope, '' );
        $this->granted[] = [ $persona, $activity, $scope ];
        MatrixRepository::clearCache();
    }

    private function makeMedia( string $title, string $kind = MediaKind::VIDEO_LINK ): object {
        $repo = new MediaRepository();

        $payload = [ 'kind' => $kind, 'title' => $title, 'captured_at' => '2026-08-14 18:00:00' ];

        if ( $kind === MediaKind::VIDEO_LINK ) {
            $payload['provider']     = 'veo';
            $payload['external_url'] = 'https://app.veo.co/matches/test/';
        } else {
            $payload['storage_adapter'] = 'local_private';
            $payload['storage_key']     = 'ab/cd/' . str_repeat( 'a', 32 ) . '.jpg';
            $payload['mime_type']       = $kind === MediaKind::VIDEO ? 'video/mp4' : 'image/jpeg';
        }

        return $repo->find( $repo->insert( $payload ) );
    }

    private function makePlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Test',
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
