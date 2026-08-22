<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgeModule;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;
use TT\Shared\CoreSurfaceRegistration;
use TT\Shared\Frontend\Components\CrossViewLinkRegistry;
use TT\Shared\Tiles\TileRegistry;

/**
 * #2646 — the reader surfaces.
 *
 * Rendering a view needs the whole dashboard shortcode, so what is
 * asserted here is the contract around the views rather than their
 * markup: the routes exist and are switchable, the cross-view gates are
 * registered, the person lookup behaves, and the lesson-body endpoint
 * enforces the gate it inherited from #2645.
 *
 * The §5 navigation contract is checked by reading the sources — every
 * `return` path carrying a breadcrumb call, no hand-rolled back button,
 * no hand-rolled tab strip. A rendering test would need the shortcode and
 * would still not prove the permission-denied path had one.
 */
final class KnowledgeReaderTest extends WP_UnitTestCase {

    private const COURSE = 'voetbalperiodisering';

    /** The four routable surfaces this wave adds. */
    private const SLUGS = [ 'knowledge', 'course', 'lesson', 'my-learning' ];

    private int $person_id = 0;
    private int $user_id   = 0;

    public function set_up(): void {
        parent::set_up();
        CourseRegistry::flushCache();
        KnowledgePerson::flush();
        KnowledgeModule::ensureCapabilities();

        // Tiles and cross-view gates are registered on a hook the test
        // bootstrap does not fire. Calling it directly is the convention
        // CrossViewLinkRegistryTest already uses, and it is idempotent.
        CoreSurfaceRegistration::register();

        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Reader',
            'last_name'  => 'Tester',
            'wp_user_id' => $this->user_id,
        ] );
        $this->person_id = (int) $wpdb->insert_id;
        KnowledgePerson::flush();
    }

    public function tear_down(): void {
        global $wpdb;
        foreach ( [ 'tt_course_submissions', 'tt_course_quiz_attempts', 'tt_course_progress', 'tt_course_enrolments' ] as $t ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" );
        }
        $wpdb->delete( $wpdb->prefix . 'tt_people', [ 'id' => $this->person_id ] );

        FeatureRegistry::setEnabled( 'knowledge_courses', true );
        KnowledgePerson::flush();
        CourseRegistry::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── switchability (#2599) ──────────────────────────────────────────

    public function test_turning_the_feature_off_disables_every_route(): void {
        FeatureRegistry::setEnabled( 'knowledge_courses', false );

        foreach ( self::SLUGS as $slug ) {
            $this->assertTrue(
                FeatureRegistry::viewSlugDisabled( $slug ),
                "Slug {$slug} still routes with the feature off."
            );
        }

        FeatureRegistry::setEnabled( 'knowledge_courses', true );

        foreach ( self::SLUGS as $slug ) {
            $this->assertFalse(
                FeatureRegistry::viewSlugDisabled( $slug ),
                "Slug {$slug} did not come back when the feature was re-enabled."
            );
        }
    }

    // ── cross-view gates (§7 / #2304) ──────────────────────────────────

    /**
     * A link to a view the reader cannot open has to disappear, and the
     * mechanism for that is a registered gate.
     */
    public function test_every_reader_surface_has_a_cross_view_gate(): void {
        foreach ( self::SLUGS as $slug ) {
            $this->assertTrue(
                CrossViewLinkRegistry::isRegistered( $slug ),
                "No cross-view gate registered for {$slug}."
            );
        }
    }

    public function test_the_cross_view_gate_follows_the_capability(): void {
        $stranger = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertTrue( CrossViewLinkRegistry::allows( 'course', $this->user_id ) );
        $this->assertFalse( CrossViewLinkRegistry::allows( 'course', $stranger ) );
        $this->assertFalse( CrossViewLinkRegistry::allows( 'course', 0 ) );
    }

    // ── tiles ──────────────────────────────────────────────────────────

    public function test_the_library_and_my_learning_tiles_are_registered(): void {
        $slugs = [];
        foreach ( TileRegistry::allRegistered() as $tile ) {
            $slugs[] = (string) ( $tile['view_slug'] ?? '' );
        }

        $this->assertContains( 'knowledge', $slugs );
        $this->assertContains( 'my-learning', $slugs );
    }

    // ── person lookup ──────────────────────────────────────────────────

    public function test_person_lookup_resolves_and_memoises(): void {
        $this->assertSame( $this->person_id, KnowledgePerson::forUser( $this->user_id ) );
        $this->assertSame( $this->person_id, KnowledgePerson::forUser( $this->user_id ) );
    }

    public function test_a_login_with_no_person_record_is_zero_not_an_error(): void {
        $stranger = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertSame( 0, KnowledgePerson::forUser( $stranger ) );
        $this->assertSame( 0, KnowledgePerson::forUser( 0 ) );
    }

    /**
     * An archived person is not a current staff member; enrolling them
     * would put a leaver in the completion statistics.
     */
    public function test_an_archived_person_does_not_resolve(): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'tt_people',
            [ 'archived_at' => current_time( 'mysql' ) ],
            [ 'id' => $this->person_id ]
        );
        KnowledgePerson::flush();

        $this->assertSame( 0, KnowledgePerson::forUser( $this->user_id ) );
    }

    // ── the lesson-body endpoint ───────────────────────────────────────

    public function test_lesson_route_is_registered(): void {
        $this->assertArrayHasKey(
            '/talenttrack/v1/courses/(?P<slug>[a-z0-9-]+)/lessons/(?P<lesson>[a-z0-9-]+)',
            rest_get_server()->get_routes()
        );
    }

    public function test_lesson_endpoint_returns_rendered_html(): void {
        wp_set_current_user( $this->user_id );

        $first = array_keys( CourseRegistry::lessons( self::COURSE ) )[0];

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/courses/' . self::COURSE . '/lessons/' . $first )
        );

        $this->assertSame( 200, $response->get_status() );

        $payload = $response->get_data()['data'] ?? $response->get_data();

        $this->assertNotSame( '', $payload['html'] );
        $this->assertStringNotContainsString( '```', $payload['html'], 'Raw fences leaked into the body.' );
        $this->assertSame( 'available', $payload['access']['kind'] );
    }

    /**
     * Locked is not absent: the reader may know the lesson exists and what
     * opens it, which is why this is 403 rather than the 404 an
     * unavailable course gets.
     */
    public function test_lesson_endpoint_refuses_a_locked_lesson_with_403(): void {
        wp_set_current_user( $this->user_id );

        $slugs = array_keys( CourseRegistry::lessons( self::COURSE ) );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/courses/' . self::COURSE . '/lessons/' . $slugs[3] )
        );

        $this->assertSame( 403, $response->get_status() );
    }

    public function test_lesson_endpoint_404s_for_an_unknown_lesson(): void {
        wp_set_current_user( $this->user_id );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/courses/' . self::COURSE . '/lessons/no-such-lesson' )
        );

        $this->assertSame( 404, $response->get_status() );
    }

    public function test_lesson_endpoint_requires_the_view_capability(): void {
        wp_set_current_user( 0 );

        $first = array_keys( CourseRegistry::lessons( self::COURSE ) )[0];

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/courses/' . self::COURSE . '/lessons/' . $first )
        );

        $this->assertSame( 401, $response->get_status() );
    }

    /**
     * The zero-point measurement taken in module 4 has to be there in
     * module 11. This is that round trip through the API the reader uses.
     */
    public function test_tool_state_round_trips_through_the_endpoint(): void {
        wp_set_current_user( $this->user_id );

        $first = array_keys( CourseRegistry::lessons( self::COURSE ) )[0];

        $save = new WP_REST_Request( 'PATCH', '/talenttrack/v1/courses/' . self::COURSE . '/progress/' . $first );
        $save->set_param( 'tool_state', [ 'zeropoint' => [ 'method' => 'extensive_endurance', 'minutes' => 24, 'step' => 3 ] ] );

        $this->assertSame( 200, rest_get_server()->dispatch( $save )->get_status() );

        $read = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/courses/' . self::COURSE . '/lessons/' . $first )
        );

        $payload = $read->get_data()['data'] ?? $read->get_data();

        $this->assertSame( 3, $payload['tool_state']['zeropoint']['step'] );
        $this->assertSame( 24, $payload['tool_state']['zeropoint']['minutes'] );
    }

    /**
     * Opening a lesson is what starts a course. An explicit enrol step
     * before you can read lesson one is a step nobody would understand.
     */
    public function test_reading_the_first_lesson_creates_the_enrolment(): void {
        wp_set_current_user( $this->user_id );

        $first = array_keys( CourseRegistry::lessons( self::COURSE ) )[0];

        $request = new WP_REST_Request( 'PATCH', '/talenttrack/v1/courses/' . self::COURSE . '/progress/' . $first );
        $request->set_param( 'read', true );
        rest_get_server()->dispatch( $request );

        $enrolment = ( new EnrolmentRepository() )->findFor( $this->person_id, self::COURSE );

        $this->assertNotNull( $enrolment );
        $this->assertSame( EnrolmentRepository::STATUS_IN_PROGRESS, (string) $enrolment->status );

        $row = ( new ProgressRepository() )->find( (int) $enrolment->id, $first );
        $this->assertNotNull( $row );
        $this->assertNotNull( $row->read_at );
    }

    // ── the §5 navigation contract ─────────────────────────────────────

    /**
     * Read from the sources, because the contract is about paths a
     * rendering test would not take — chiefly the permission-denied
     * early return, which must still draw a breadcrumb chain.
     */
    public function test_views_follow_the_navigation_contract(): void {
        $dir   = dirname( __DIR__, 2 ) . '/src/Modules/Knowledge/Frontend';
        $views = glob( $dir . '/Frontend*View.php' );

        $this->assertNotEmpty( $views, 'No reader views found to audit.' );

        foreach ( $views as $file ) {
            $name = basename( $file );
            $src  = (string) file_get_contents( $file );

            $this->assertStringContainsString(
                'FrontendBreadcrumbs::fromDashboard(',
                $src,
                "{$name} draws no breadcrumb chain."
            );

            foreach ( [ 'FrontendBackButton', 'tt-tabs', 'role="tablist"' ] as $forbidden ) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $src,
                    "{$name} uses {$forbidden}, which §5 forbids on a new surface."
                );
            }

            // Every early return in render() must be preceded by a
            // breadcrumb call. Checked by requiring the first chunk before
            // any `return;` to contain one.
            $chunks = preg_split( '/^\s+return;\s*$/m', $src );
            if ( is_array( $chunks ) && count( $chunks ) > 1 ) {
                $this->assertTrue(
                    strpos( $chunks[0], 'fromDashboard(' ) !== false
                    || strpos( $chunks[0], 'renderMissing(' ) !== false,
                    "{$name} has a return path before any breadcrumb call."
                );
            }
        }
    }
}
