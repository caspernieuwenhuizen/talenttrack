<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Modules\Media\Wizard\MediaConfirmStep;
use TT\Modules\Media\Wizard\MediaDetailsStep;
use TT\Modules\Media\Wizard\MediaSourceStep;
use TT\Modules\Media\Wizard\MediaTargetStep;
use TT\Modules\Media\Wizard\NewMediaWizard;
use TT\Shared\Wizards\WizardRegistry;

/**
 * #2593 (epic #2589) — the add-media wizard.
 *
 * The steps are mostly markup, which a unit test has little to say about.
 * What it does have something to say about is the seam this wizard is
 * built on: the uploads commit in step 2 and travel to step 4 as a list
 * of uuids **in a client-supplied form field**. Everything downstream of
 * that field has to treat it as untrusted, and that is what is asserted
 * here.
 */
final class MediaWizardTest extends WP_UnitTestCase {

    /** @var list<array{0:string,1:string,2:string}> */
    private $granted = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();
        do_action( 'init' );
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

    // ── registration ───────────────────────────────────────────────────

    public function test_the_wizard_is_registered_and_shaped_as_specified(): void {
        $wizard = WizardRegistry::find( 'new-media' );

        $this->assertInstanceOf( NewMediaWizard::class, $wizard );
        $this->assertSame( 'tt_manage_media', $wizard->requiredCap(), 'adding media is a create' );
        $this->assertSame( 'target', $wizard->firstStepSlug() );

        $slugs = array_map( static function ( $step ) { return $step->slug(); }, $wizard->steps() );
        $this->assertSame(
            [ 'target', 'source', 'details', 'confirm' ],
            $slugs,
            'the target comes first because a file cannot cross a step boundary — see NewMediaWizard'
        );
    }

    /**
     * The wizard chain must actually terminate. A step returning a slug
     * no other step answers to would loop or dead-end.
     */
    public function test_the_step_chain_reaches_an_end(): void {
        $wizard = new NewMediaWizard();
        $known  = array_map( static function ( $step ) { return $step->slug(); }, $wizard->steps() );

        $seen = [];
        $slug = $wizard->firstStepSlug();

        while ( $slug !== null ) {
            $this->assertContains( $slug, $known, "step '{$slug}' is referenced but not registered" );
            $this->assertNotContains( $slug, $seen, "step '{$slug}' repeats — the chain loops" );
            $seen[] = $slug;

            $step = null;
            foreach ( $wizard->steps() as $candidate ) {
                if ( $candidate->slug() === $slug ) $step = $candidate;
            }
            $slug = $step->nextStep( [] );
        }

        $this->assertSame( $known, $seen, 'every registered step is reachable' );
    }

    // ── target ─────────────────────────────────────────────────────────

    public function test_a_target_the_user_cannot_write_to_is_refused_up_front(): void {
        $this->grant( 'head_coach', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM );
        wp_set_current_user( $this->makeCoachScopedToTeam( 8101 ) );

        $result = ( new MediaTargetStep() )->validate(
            [ 'entity_type' => MediaEntityType::TEAM, 'entity_id' => 8102 ],
            []
        );

        $this->assertWPError( $result );
        $this->assertSame( 'not_allowed', $result->get_error_code() );
    }

    public function test_a_missing_target_is_refused(): void {
        $this->assertWPError( ( new MediaTargetStep() )->validate( [], [] ) );
        $this->assertWPError( ( new MediaTargetStep() )->validate( [ 'entity_type' => 'coach', 'entity_id' => 3 ], [] ) );
    }

    // ── source: the untrusted field ────────────────────────────────────

    public function test_continuing_with_nothing_uploaded_is_refused(): void {
        $result = ( new MediaSourceStep() )->validate( [ MediaSourceStep::FIELD => '' ], [] );

        $this->assertWPError( $result );
        $this->assertSame( 'nothing_added', $result->get_error_code() );
    }

    /**
     * The uuid list arrives in a hidden form field, so anyone can put
     * anything in it. Unknown values must be dropped rather than carried
     * forward.
     */
    public function test_uuids_that_do_not_resolve_are_dropped(): void {
        $real = $this->makeMedia();

        $result = ( new MediaSourceStep() )->validate(
            [ MediaSourceStep::FIELD => $real->uuid . ',00000000-0000-4000-8000-000000000000,nonsense' ],
            []
        );

        $this->assertIsArray( $result );
        $this->assertSame( [ $real->uuid ], $result['media_uuids'] );
    }

    public function test_a_field_of_only_invented_uuids_is_refused(): void {
        $result = ( new MediaSourceStep() )->validate(
            [ MediaSourceStep::FIELD => '00000000-0000-4000-8000-000000000000' ],
            []
        );

        $this->assertWPError( $result );
    }

    // ── details ────────────────────────────────────────────────────────

    public function test_a_malformed_capture_date_is_refused(): void {
        $this->assertWPError( ( new MediaDetailsStep() )->validate( [ 'media_captured_at' => 'yesterday' ], [] ) );
        $this->assertWPError( ( new MediaDetailsStep() )->validate( [ 'media_captured_at' => '14-08-2026' ], [] ) );
    }

    public function test_an_empty_capture_date_is_allowed(): void {
        $result = ( new MediaDetailsStep() )->validate( [ 'media_captured_at' => '' ], [] );

        $this->assertIsArray( $result );
        $this->assertSame( '', $result['media_captured_at'] );
    }

    public function test_details_are_sanitised(): void {
        $result = ( new MediaDetailsStep() )->validate(
            [ 'media_title' => '  <script>alert(1)</script>Tom  ', 'media_captured_at' => '2026-08-14' ],
            []
        );

        $this->assertIsArray( $result );
        $this->assertStringNotContainsString( '<script', $result['media_title'] );
    }

    // ── confirm: a step boundary is not an authorization boundary ──────

    /**
     * The uuids reached this step through a form field. Even having got
     * this far, each write is re-checked — otherwise a crafted field would
     * let someone retitle a photograph on another team's record.
     */
    public function test_items_the_user_cannot_edit_are_left_alone(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        $this->grant( 'head_coach', MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM );

        $team  = 8201;
        $coach = $this->makeCoachScopedToTeam( $team );

        $mine   = $this->makeMedia( MediaEntityType::TEAM, $team );
        $theirs = $this->makeMedia( MediaEntityType::TEAM, 8202 );

        wp_set_current_user( $coach );

        $result = ( new MediaConfirmStep() )->submit( [
            'entity_type' => MediaEntityType::TEAM,
            'entity_id'   => $team,
            'media_uuids' => [ $mine->uuid, $theirs->uuid ],
            'media_title' => 'Renamed',
        ] );

        $this->assertIsArray( $result );

        $repo = new MediaRepository();
        $this->assertSame( 'Renamed', $repo->findByUuid( $mine->uuid )->title );
        $this->assertSame(
            'Clip',
            $repo->findByUuid( $theirs->uuid )->title,
            'a uuid smuggled into the field must not become a write on another team\'s record'
        );
    }

    public function test_submitting_with_nothing_added_is_an_error(): void {
        $this->assertWPError( ( new MediaConfirmStep() )->submit( [ 'media_uuids' => [] ] ) );
    }

    public function test_submit_returns_the_record_the_media_was_added_to(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        $this->grant( 'head_coach', MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM );

        $team = 8301;
        wp_set_current_user( $this->makeCoachScopedToTeam( $team ) );
        $media = $this->makeMedia( MediaEntityType::TEAM, $team );

        $result = ( new MediaConfirmStep() )->submit( [
            'entity_type' => MediaEntityType::TEAM,
            'entity_id'   => $team,
            'media_uuids' => [ $media->uuid ],
        ] );

        $this->assertIsArray( $result );
        $this->assertStringContainsString( 'tt_view=teams', $result['redirect_url'] );
        $this->assertStringContainsString( 'id=' . $team, $result['redirect_url'] );
    }

    /**
     * #2716 — the finished wizard has to land on the page that hosts the
     * dashboard shortcode. Built on home_url() it lands on the site's front
     * page instead, where tt_view is never read, and the coach is left
     * staring at the theme's blog roll wondering where the photo went.
     *
     * Asserting the query args alone is not enough: those were already
     * correct while the bug was live. The base is the whole defect.
     */
    public function test_the_redirect_targets_the_dashboard_page_not_the_site_root(): void {
        $page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'Dashboard' ] );
        QueryHelpers::set_config( 'dashboard_page_id', (string) $page );

        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        $this->grant( 'head_coach', MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM );

        $team = 8302;
        wp_set_current_user( $this->makeCoachScopedToTeam( $team ) );
        $media = $this->makeMedia( MediaEntityType::TEAM, $team );

        $result = ( new MediaConfirmStep() )->submit( [
            'entity_type' => MediaEntityType::TEAM,
            'entity_id'   => $team,
            'media_uuids' => [ $media->uuid ],
        ] );

        $this->assertStringStartsWith( get_permalink( $page ), $result['redirect_url'] );
        $this->assertNotSame( home_url( '/' ), get_permalink( $page ), 'fixture must make the two differ' );
    }

    /**
     * #2716 — activity media used `tt_view=activities-manage`, which is not
     * a route. The dispatcher case is `activities`.
     */
    public function test_every_entity_type_maps_to_a_slug_the_dispatcher_answers(): void {
        // The mapping is a pure function; reaching it through submit() would
        // need a full authority fixture per entity type to assert one string.
        $method = new \ReflectionMethod( MediaConfirmStep::class, 'recordUrl' );
        $method->setAccessible( true );

        $expected = [
            MediaEntityType::PLAYER   => 'tt_view=players',
            MediaEntityType::TEAM     => 'tt_view=teams',
            MediaEntityType::ACTIVITY => 'tt_view=activities',
        ];

        foreach ( $expected as $type => $needle ) {
            $url = (string) $method->invoke( null, $type, 42 );

            $this->assertStringContainsString( $needle, $url, $type . ' routes to the wrong view' );
            $this->assertStringContainsString( 'id=42', $url );
        }

        // `activities-manage` is not a dispatcher case — it was a dead slug.
        $this->assertStringNotContainsString(
            'activities-manage',
            (string) $method->invoke( null, MediaEntityType::ACTIVITY, 42 )
        );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function grant( string $persona, string $activity, string $scope ): void {
        ( new MatrixRepository() )->setRow( $persona, MediaVisibilityService::ENTITY, $activity, $scope, '' );
        $this->granted[] = [ $persona, $activity, $scope ];
        MatrixRepository::clearCache();
    }

    private function makeMedia( string $entity_type = '', int $entity_id = 0 ): object {
        $repo = new MediaRepository();
        $id   = $repo->insert( [
            'kind'         => MediaKind::VIDEO_LINK,
            'title'        => 'Clip',
            'provider'     => 'veo',
            'external_url' => 'https://app.veo.co/matches/test/',
        ] );

        if ( $entity_type !== '' && $entity_id > 0 ) {
            ( new MediaLinksRepository() )->link( $id, $entity_type, $entity_id );
        }

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
