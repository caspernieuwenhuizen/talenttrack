<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use WP_REST_Request;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Modules\Players\Services\MediaConsentStatement;
use TT\Shared\Frontend\Components\MediaGallery;

/**
 * #3804 — consent is now *shown*, and still not enforced.
 *
 * `MediaConsentTest` (#2744) pins that recording consent does not gate an
 * upload. This file pins the other half, which is easier to break by
 * accident: that surfacing the record did not turn it into a filter.
 *
 * The temptation is real. Once a screen says "no consent on record" next
 * to a photograph of a child, hiding that photograph looks like the
 * responsible next step. It is not, and the reasoning is in the issue: a
 * coach who cannot see a picture cannot judge whether they may use it,
 * and a file whose images silently disappear reads as broken rather than
 * careful. The club's answer is to ask the parent, not to blind the staff.
 *
 * So every assertion below is of the form "still there, and marked".
 */
final class MediaConsentSurfacedTest extends WP_UnitTestCase {

    /** @var list<array{0:string,1:string}> */
    private $granted = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();

        // `MediaVisibilityService::filterVisible()` asks the matrix, not the
        // capability map, and the suite does not install the authorization
        // seed — so without these rows the gallery renders empty for
        // everybody and the assertions below would be measuring the
        // visibility layer instead of the consent marker.
        //
        // Global scope on the academy admin, because these tests are about
        // what the surfaces say about consent; a squad boundary would only
        // give them a second reason to fail. `MediaVisibilityTest` pins that
        // this is the persona a `tt_club_admin` resolves to — a WordPress
        // `administrator` does not, which is why the user below is not one.
        foreach ( [ MatrixGate::READ, MatrixGate::CREATE_DELETE, MatrixGate::CHANGE ] as $activity ) {
            ( new MatrixRepository() )->setRow(
                'academy_admin',
                MediaVisibilityService::ENTITY,
                $activity,
                MatrixGate::SCOPE_GLOBAL,
                ''
            );
            $this->granted[] = [ $activity, MatrixGate::SCOPE_GLOBAL ];
        }
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_club_admin' ] ) );
    }

    public function tear_down(): void {
        $repo = new MatrixRepository();
        foreach ( $this->granted as [ $activity, $scope ] ) {
            $repo->removeRow( 'academy_admin', MediaVisibilityService::ENTITY, $activity, $scope );
        }
        $this->granted = [];
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();
        parent::tear_down();
    }

    // ── nothing is hidden ──────────────────────────────────────────────

    public function test_an_unconsented_players_media_list_is_not_filtered(): void {
        $player = $this->makePlayer();
        $this->attachVideoLink( $player, 'Kept in place' );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/media' );
        $request->set_param( 'entity_type', MediaEntityType::PLAYER );
        $request->set_param( 'entity_id', $player );

        $response = rest_do_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount(
            1,
            $data['data']['items'],
            'marking is not hiding (#3804) — if this fails, read the decision before changing it'
        );
    }

    public function test_the_gallery_renders_every_tile_for_an_unconsented_player(): void {
        $player = $this->makePlayer();
        $this->attachVideoLink( $player, 'Still rendered' );

        $html = $this->galleryHtml( $player );

        $this->assertStringContainsString( 'Still rendered', $html );
        $this->assertSame(
            1,
            substr_count( $html, '<li class="tt-media-tile' ),
            'the tile is present exactly once, not dropped and not doubled'
        );
    }

    public function test_recording_consent_removes_the_marker_but_changes_nothing_else(): void {
        $player = $this->makePlayer();
        $this->attachVideoLink( $player, 'Same picture either way' );

        $before = $this->galleryHtml( $player );
        $this->assertStringContainsString( 'tt-media-gallery--no-consent', $before );
        $this->assertStringContainsString( 'No consent on record', $before );

        $this->recordConsent( $player );

        $after = $this->galleryHtml( $player );
        $this->assertStringNotContainsString( 'tt-media-gallery--no-consent', $after );
        $this->assertStringNotContainsString( 'No consent on record', $after );
        $this->assertStringContainsString( 'Same picture either way', $after, 'the media itself is untouched' );
    }

    // ── and the state is reported, in words ────────────────────────────

    public function test_the_media_envelope_carries_the_viewing_players_consent(): void {
        $player = $this->makePlayer();
        $this->attachVideoLink( $player, 'Anything' );

        $data = $this->mediaList( $player );

        $this->assertArrayHasKey( 'player_consent', $data );
        $this->assertFalse( $data['player_consent']['recorded'] );
        $this->assertNotSame( '', (string) $data['player_consent']['statement'] );

        $this->recordConsent( $player );

        $data = $this->mediaList( $player );
        $this->assertTrue( $data['player_consent']['recorded'] );
        $this->assertNotNull( $data['player_consent']['at'] );
        $this->assertNotNull( $data['player_consent']['by'] );
    }

    /**
     * A team photo depicts many children and has no single consent to
     * report, so the envelope carries none rather than an arbitrary one.
     */
    public function test_a_team_collection_carries_no_consent_block(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Consent FC' ] );
        $team = (int) $wpdb->insert_id;

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/media' );
        $request->set_param( 'entity_type', MediaEntityType::TEAM );
        $request->set_param( 'entity_id', $team );

        $data = rest_do_request( $request )->get_data();

        $this->assertArrayNotHasKey( 'player_consent', $data['data'] );
    }

    public function test_the_statement_says_the_state_in_words_not_by_colour(): void {
        $player = $this->makePlayer();
        $row    = $this->playerRow( $player );

        $this->assertFalse( MediaConsentStatement::isRecorded( $row ) );
        $this->assertNotSame( '', MediaConsentStatement::sentence( $row ) );
        $this->assertSame( 'Not recorded', MediaConsentStatement::summary( $row ) );

        $this->recordConsent( $player );
        $row = $this->playerRow( $player );

        $this->assertTrue( MediaConsentStatement::isRecorded( $row ) );
        $this->assertStringContainsString( 'On record', MediaConsentStatement::summary( $row ) );
    }

    public function test_a_missing_player_reports_not_recorded_rather_than_throwing(): void {
        $this->assertFalse( MediaConsentStatement::isRecorded( null ) );
        $this->assertSame( 'Not recorded', MediaConsentStatement::summary( null ) );
        $this->assertNotSame( '', MediaConsentStatement::sentence( null ) );
        $this->assertFalse( MediaGallery::marksUnconsented( MediaEntityType::PLAYER, 0 ) );
    }

    // ── the list lens ──────────────────────────────────────────────────

    public function test_the_players_list_filter_finds_only_children_with_images_and_no_consent(): void {
        $with_images    = $this->makePlayer( 'Pictured' );
        $without_images = $this->makePlayer( 'Unpictured' );
        $consented      = $this->makePlayer( 'Permitted' );

        $this->attachVideoLink( $with_images, 'A picture' );
        $this->attachVideoLink( $consented, 'Another picture' );
        $this->recordConsent( $consented );

        $ids = $this->listPlayerIds( [ 'media_consent' => 'media_no_consent' ] );

        $this->assertContains( $with_images, $ids );
        $this->assertNotContains(
            $without_images,
            $ids,
            'a child nobody has photographed is an administrative gap, not this week work'
        );
        $this->assertNotContains( $consented, $ids );
    }

    public function test_the_plain_no_consent_filter_still_includes_children_without_images(): void {
        $with_images    = $this->makePlayer( 'Pictured' );
        $without_images = $this->makePlayer( 'Unpictured' );
        $this->attachVideoLink( $with_images, 'A picture' );

        $ids = $this->listPlayerIds( [ 'media_consent' => 'no_consent' ] );

        $this->assertContains( $with_images, $ids );
        $this->assertContains( $without_images, $ids );
    }

    public function test_the_list_row_reports_consent_and_how_many_items_are_held(): void {
        $player = $this->makePlayer();
        $this->attachVideoLink( $player, 'One item' );

        $row = $this->listRow( $player );

        $this->assertFalse( $row['media_consent'] );
        $this->assertSame( 1, $row['media_count'] );
        $this->assertStringContainsString( 'No consent', $row['media_consent_pill_html'] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function galleryHtml( int $player_id ): string {
        ob_start();
        MediaGallery::render( [
            'entity_type' => MediaEntityType::PLAYER,
            'entity_id'   => $player_id,
            'can_edit'    => false,
        ] );
        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private function mediaList( int $player_id ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/media' );
        $request->set_param( 'entity_type', MediaEntityType::PLAYER );
        $request->set_param( 'entity_id', $player_id );

        $data = rest_do_request( $request )->get_data();
        return (array) $data['data'];
    }

    /**
     * @param array<string, string> $filter
     * @return list<int>
     */
    private function listPlayerIds( array $filter ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players' );
        $request->set_param( 'filter', $filter );
        $request->set_param( 'per_page', 100 );

        $data = rest_do_request( $request )->get_data();

        // The players list answers `rows`, not `items` — the media list's
        // envelope is the one with `items`, and they are different shapes.
        return array_map(
            static fn( array $row ): int => (int) $row['id'],
            (array) ( $data['data']['rows'] ?? [] )
        );
    }

    /** @return array<string, mixed> */
    private function listRow( int $player_id ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players' );
        $request->set_param( 'per_page', 100 );

        $data = rest_do_request( $request )->get_data();

        foreach ( (array) ( $data['data']['rows'] ?? [] ) as $row ) {
            if ( (int) $row['id'] === $player_id ) return (array) $row;
        }

        $this->fail( 'the player was not in the list at all' );
    }

    private function recordConsent( int $player_id ): void {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/players/' . $player_id );
        $request->set_param( 'id', $player_id );
        $request->set_param( 'media_consent', '1' );
        rest_do_request( $request );
    }

    private function attachVideoLink( int $player_id, string $title ): void {
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/media' );
        $request->set_param( 'entity_type', MediaEntityType::PLAYER );
        $request->set_param( 'entity_id', $player_id );
        $request->set_param( 'external_url', 'https://app.veo.co/matches/test/' );
        $request->set_param( 'title', $title );

        $response = rest_do_request( $request );
        $this->assertSame( 201, $response->get_status(), 'fixture media must attach' );
        $this->assertCount(
            1,
            ( new MediaRepository() )->listForEntity( MediaEntityType::PLAYER, $player_id )
        );
    }

    private function playerRow( int $player_id ): object {
        global $wpdb;
        return (object) $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ) );
    }

    private function makePlayer( string $last_name = 'Player' ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Consent',
            'last_name'  => $last_name,
            'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }
}
