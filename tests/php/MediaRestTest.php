<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;

/**
 * #2592 (epic #2589) — the media REST surface.
 *
 * `RestSmokeTest` already proves an unauthenticated caller is denied on
 * every media route. What this covers is the harder case: a caller who is
 * legitimately signed in and legitimately holds media access, asking
 * about a record they have no business seeing.
 */
final class MediaRestTest extends WP_UnitTestCase {

    /** @var list<array{0:string,1:string,2:string}> */
    private $granted = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();
        do_action( 'rest_api_init' );
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

    /**
     * The interesting refusal. A coach who holds media access, asking for
     * a photograph attached to another team's player, is told the item
     * does not exist — not that they are forbidden.
     *
     * 403 would confirm the uuid names a real item in this academy, which
     * is precisely what someone probing for other people's photographs
     * wants to learn. 404 tells them nothing.
     */
    public function test_media_out_of_scope_answers_not_found_rather_than_forbidden(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $coach = $this->makeCoachScopedToTeam( 7101 );
        $media = $this->makeMediaOn( MediaEntityType::TEAM, 7102 );

        wp_set_current_user( $coach );
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/media/' . $media->uuid ) );

        $this->assertSame(
            404,
            $response->get_status(),
            'a 403 here would confirm the item exists, which is the thing worth hiding'
        );
    }

    public function test_the_byte_endpoint_refuses_out_of_scope_media(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $coach = $this->makeCoachScopedToTeam( 7201 );
        $media = $this->makeMediaOn( MediaEntityType::TEAM, 7202 );

        wp_set_current_user( $coach );
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/media/' . $media->uuid . '/file' ) );

        $this->assertSame( 404, $response->get_status() );
    }

    public function test_a_coach_reads_their_own_team_media_in_the_standard_envelope(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team  = 7301;
        $coach = $this->makeCoachScopedToTeam( $team );
        $media = $this->makeMediaOn( MediaEntityType::TEAM, $team );

        wp_set_current_user( $coach );
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/media/' . $media->uuid ) );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertTrue( (bool) $data['success'] );
        $this->assertSame( $media->uuid, $data['data']['uuid'] );
        $this->assertSame( MediaKind::VIDEO_LINK, $data['data']['kind'] );
    }

    /**
     * The payload must never disclose where the bytes actually live —
     * that is what keeps an object-storage swap invisible to consumers,
     * and it is also one less path to leak (CLAUDE.md §4).
     */
    public function test_the_payload_carries_rest_urls_and_no_filesystem_detail(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team  = 7401;
        $coach = $this->makeCoachScopedToTeam( $team );
        $media = $this->makeMediaOn( MediaEntityType::TEAM, $team );

        wp_set_current_user( $coach );
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/media/' . $media->uuid ) );

        $encoded = wp_json_encode( $response->get_data() );

        $this->assertStringNotContainsString( 'wp-content/uploads', $encoded );
        $this->assertStringNotContainsString( 'tt-media', $encoded );
        $this->assertStringNotContainsString( 'storage_key', $encoded );
        $this->assertStringNotContainsString( 'storage_adapter', $encoded );
    }

    public function test_listing_requires_a_target_record(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        wp_set_current_user( $this->makeCoachScopedToTeam( 7501 ) );

        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/media' ) );

        $this->assertSame( 400, $response->get_status() );
    }

    /**
     * Upload authority alone is not permission to publish into any record
     * — the destination is what has to be authorised, or reach over one
     * team would become reach over all of them.
     */
    public function test_upload_into_an_out_of_scope_record_is_refused(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        $this->grant( 'head_coach', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM );

        wp_set_current_user( $this->makeCoachScopedToTeam( 7601 ) );

        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/media' );
        $request->set_param( 'entity_type', MediaEntityType::TEAM );
        $request->set_param( 'entity_id', 7602 );
        $request->set_param( 'external_url', 'https://app.veo.co/matches/x/' );

        $this->assertSame( 403, rest_do_request( $request )->get_status() );
    }

    public function test_a_bad_video_url_is_rejected_before_anything_is_stored(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        $this->grant( 'head_coach', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM );

        $team = 7701;
        wp_set_current_user( $this->makeCoachScopedToTeam( $team ) );

        $before = ( new MediaRepository() )->listForEntity( MediaEntityType::TEAM, $team );

        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/media' );
        $request->set_param( 'entity_type', MediaEntityType::TEAM );
        $request->set_param( 'entity_id', $team );
        $request->set_param( 'external_url', 'http://169.254.169.254/latest/meta-data/' );

        $response = rest_do_request( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertCount(
            count( $before ),
            ( new MediaRepository() )->listForEntity( MediaEntityType::TEAM, $team ),
            'a refused URL must leave no row behind'
        );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function grant( string $persona, string $activity, string $scope ): void {
        ( new MatrixRepository() )->setRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope, '' );
        $this->granted[] = [ $persona, $activity, $scope ];
        MatrixRepository::clearCache();
    }

    private function makeMediaOn( string $entity_type, int $entity_id ): object {
        $repo = new MediaRepository();
        $id   = $repo->insert( [
            'kind'         => MediaKind::VIDEO_LINK,
            'title'        => 'Clip',
            'provider'     => 'veo',
            'external_url' => 'https://app.veo.co/matches/test/',
        ] );
        ( new MediaLinksRepository() )->link( $id, $entity_type, $entity_id );
        return $repo->find( $id );
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
