<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgeModule;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;

/**
 * #3852 — `PATCH /courses/{slug}/progress/{lesson}` accepted exactly two body
 * fields and declared none of them.
 *
 * A body using any other name — `{"completed": true}` is the obvious guess,
 * and it is what an assistant coach sent — passed every guard, matched
 * neither branch and fell through to the response builder. The call answered
 * `200 {"success": true}` with the *unchanged* progress record embedded, so
 * the only way to tell a recorded lesson from a discarded one was to read the
 * echo closely. An hour of study went unrecorded and the enrolment sat at
 * 0/11 past its due date.
 *
 * Worse than a no-op: `markStarted()` ran before the branches, so the
 * discarded call still moved `started_at` and left the enrolment reading
 * "started, nothing read".
 */
final class LessonProgressBodyContractTest extends WP_UnitTestCase {

    private const COURSE = 'voetbalperiodisering';
    private const ROUTE  = '/talenttrack/v1/courses/(?P<slug>[a-z0-9-]+)/progress/(?P<lesson>[a-z0-9-]+)';

    private int $person_id = 0;
    private int $user_id   = 0;
    private string $lesson = '';

    public function set_up(): void {
        parent::set_up();
        CourseRegistry::flushCache();
        KnowledgeModule::ensureCapabilities();

        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Test',
            'last_name'  => 'Coach',
            'wp_user_id' => $this->user_id,
        ] );
        $this->person_id = (int) $wpdb->insert_id;

        $this->lesson = (string) array_keys( CourseRegistry::lessons( self::COURSE ) )[0];
    }

    public function tear_down(): void {
        global $wpdb;
        foreach ( [ 'tt_course_submissions', 'tt_course_quiz_attempts', 'tt_course_progress', 'tt_course_enrolments' ] as $t ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" );
        }
        $wpdb->delete( $wpdb->prefix . 'tt_people', [ 'id' => $this->person_id ] );

        wp_set_current_user( 0 );
        CourseRegistry::flushCache();
        parent::tear_down();
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @param array<string,mixed> $body */
    private function patch( array $body ): \WP_REST_Response {
        $request = new WP_REST_Request(
            'PATCH',
            '/talenttrack/v1/courses/' . self::COURSE . '/progress/' . $this->lesson
        );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );

        return rest_get_server()->dispatch( $request );
    }

    /** @return array<string,mixed> the first error in the envelope, or [] */
    private function firstError( \WP_REST_Response $response ): array {
        $data   = (array) $response->get_data();
        $errors = isset( $data['errors'] ) && is_array( $data['errors'] ) ? $data['errors'] : [];
        if ( $errors === [] ) return [];

        return (array) reset( $errors );
    }

    private function errorCode( \WP_REST_Response $response ): string {
        return (string) ( $this->firstError( $response )['code'] ?? '' );
    }

    private function errorMessage( \WP_REST_Response $response ): string {
        return (string) ( $this->firstError( $response )['message'] ?? '' );
    }

    private function enrolment(): ?object {
        return ( new EnrolmentRepository() )->findFor( $this->person_id, self::COURSE );
    }

    // ── the route says what it takes ───────────────────────────────────

    public function test_the_route_declares_both_fields_with_a_type_and_a_description(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( self::ROUTE, $routes );

        $args = (array) ( $routes[ self::ROUTE ][0]['args'] ?? [] );

        foreach ( [ 'read' => 'boolean', 'tool_state' => 'object' ] as $field => $type ) {
            $this->assertArrayHasKey( $field, $args, "the route does not declare {$field}" );
            $this->assertSame( $type, $args[ $field ]['type'] ?? '' );
            $this->assertNotSame( '', (string) ( $args[ $field ]['description'] ?? '' ) );
        }
    }

    // ── a body it cannot act on ────────────────────────────────────────

    /** The reported call, verbatim. */
    public function test_an_unrecognised_field_is_refused_rather_than_discarded(): void {
        $response = $this->patch( [ 'completed' => true ] );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'no_writable_field', $this->errorCode( $response ) );
    }

    public function test_the_refusal_names_both_fields_it_would_accept(): void {
        $message = $this->errorMessage( $this->patch( [ 'completed' => true ] ) );

        $this->assertStringContainsString( 'read', $message );
        $this->assertStringContainsString( 'tool_state', $message );
    }

    public function test_an_empty_body_is_refused_too(): void {
        $response = $this->patch( [] );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'no_writable_field', $this->errorCode( $response ) );
    }

    /**
     * The half of the bug that outlived the response: the discarded call
     * still enrolled the reader and stamped `started_at`, so the learning
     * roll-up showed a course begun and never touched.
     */
    public function test_a_refused_call_writes_nothing_at_all(): void {
        $this->patch( [ 'completed' => true ] );

        $this->assertNull( $this->enrolment(), 'a refused call enrolled the reader' );

        global $wpdb;
        $this->assertSame( 0, (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_course_progress"
        ), 'a refused call wrote a progress row' );
    }

    public function test_a_refused_call_does_not_move_an_existing_enrolment(): void {
        // Enrol first, so `started_at` has something to be moved from.
        $enrolment_id = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );
        $this->assertGreaterThan( 0, $enrolment_id );

        $before = $this->enrolment();
        $this->assertNotNull( $before );
        $this->assertNull( $before->started_at ?? null, 'the fixture already counted as started' );

        $this->patch( [ 'completed' => true ] );

        $after = $this->enrolment();
        $this->assertNotNull( $after );
        $this->assertNull( $after->started_at ?? null, 'a refused call stamped started_at' );
    }

    /**
     * A body that mixes a writable field with an unrecognised one gets the
     * standard `unknown_field` (#3689), naming the key that was wrong —
     * there is no ambiguity about what this route takes when the caller has
     * already used one of its fields.
     */
    public function test_a_writable_field_alongside_an_unknown_one_names_the_unknown_one(): void {
        $response = $this->patch( [ 'read' => true, 'completed' => true ] );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'unknown_field', $this->errorCode( $response ) );
        $this->assertStringContainsString( 'completed', $this->errorMessage( $response ) );
    }

    // ── no regression on the two shapes that do work ───────────────────

    public function test_marking_a_lesson_read_still_works(): void {
        $response = $this->patch( [ 'read' => true ] );

        $this->assertSame( 200, $response->get_status() );

        $enrolment = $this->enrolment();
        $this->assertNotNull( $enrolment );

        $row = ( new ProgressRepository() )->find( (int) $enrolment->id, $this->lesson );
        $this->assertNotNull( $row );
        $this->assertNotNull( $row->read_at, 'read_at was not stamped' );
    }

    public function test_saving_tool_state_still_works(): void {
        $response = $this->patch( [ 'tool_state' => [ 'zeropoint' => [ 'step' => 3 ] ] ] );

        $this->assertSame( 200, $response->get_status() );

        $enrolment = $this->enrolment();
        $this->assertNotNull( $enrolment );

        $repo  = new ProgressRepository();
        $state = $repo->toolState( $repo->find( (int) $enrolment->id, $this->lesson ) );

        $this->assertSame( 3, (int) ( $state['zeropoint']['step'] ?? 0 ) );
    }

    /**
     * `read: false` is a caller saying something about `read`, so it is a
     * body this route can act on — it simply does not mark the lesson.
     * Refusing it would make "unmark" impossible to ask about later.
     */
    public function test_read_false_is_an_answer_not_an_absence(): void {
        $response = $this->patch( [ 'read' => false ] );

        $this->assertSame( 200, $response->get_status() );

        $enrolment = $this->enrolment();
        $this->assertNotNull( $enrolment );

        $row = ( new ProgressRepository() )->find( (int) $enrolment->id, $this->lesson );
        $this->assertNull( $row->read_at ?? null );
    }
}
