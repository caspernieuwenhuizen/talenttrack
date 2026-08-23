<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgeModule;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\LessonContext;
use TT\Modules\Knowledge\LessonRenderer;
use TT\Modules\Knowledge\Quiz\QuizPayload;
use TT\Modules\Knowledge\Quiz\QuizScorer;
use TT\Modules\Knowledge\Quiz\QuizSubmission;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;
use TT\Modules\Knowledge\Repositories\QuizAttemptRepository;

/**
 * #2647 — the lesson check.
 *
 * Two things here are load-bearing beyond "does it add up".
 *
 * The answer key must not reach the browser, because scoring on the
 * client would require handing it over. Several assertions below check
 * the rendered form for it directly rather than trusting the design.
 *
 * And the options must be shuffled. Every `order` and `match` answer in
 * the shipped corpus is the identity permutation, so rendering options in
 * stored order hands the reader the answer to every sequencing question in
 * the course. That is a content fact, so it is asserted against the
 * corpus, not against a fixture.
 */
final class KnowledgeQuizTest extends WP_UnitTestCase {

    private const COURSE = 'voetbalperiodisering';

    private int $person_id = 0;
    private int $user_id   = 0;
    private string $lesson = '';

    public function set_up(): void {
        parent::set_up();
        CourseRegistry::flushCache();
        KnowledgePerson::flush();
        KnowledgeModule::ensureCapabilities();

        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Quiz',
            'last_name'  => 'Tester',
            'wp_user_id' => $this->user_id,
        ] );
        $this->person_id = (int) $wpdb->insert_id;
        KnowledgePerson::flush();

        $this->lesson = array_keys( CourseRegistry::lessons( self::COURSE ) )[0];
    }

    public function tear_down(): void {
        global $wpdb;
        foreach ( [ 'tt_course_submissions', 'tt_course_quiz_attempts', 'tt_course_progress', 'tt_course_enrolments' ] as $t ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" );
        }
        $wpdb->delete( $wpdb->prefix . 'tt_people', [ 'id' => $this->person_id ] );

        LessonContext::clear();
        KnowledgePerson::flush();
        CourseRegistry::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the corpus ─────────────────────────────────────────────────────

    /**
     * The gap #2647 found: ten lessons declared `quiz: true`, had a valid
     * payload, and rendered no quiz anywhere. The corpus lint now fails
     * for it; this fails too, because the lint only runs on PRs that touch
     * `courses/`.
     */
    public function test_every_lesson_declaring_a_quiz_renders_one(): void {
        foreach ( CourseRegistry::lessons( self::COURSE ) as $slug => $lesson ) {
            if ( ! $lesson->hasQuiz() ) {
                continue;
            }

            $this->assertStringContainsString(
                '```tt-quiz',
                $lesson->body(),
                "Lesson {$slug} declares quiz: true but renders no quiz."
            );

            $this->assertNotNull(
                QuizPayload::forLesson( self::COURSE, $slug ),
                "Lesson {$slug} declares quiz: true but has no usable payload."
            );
        }
    }

    // ── the answer key stays on the server ─────────────────────────────

    public function test_the_display_form_carries_no_answers_or_explanations(): void {
        $payload = QuizPayload::forLesson( self::COURSE, $this->lesson );

        foreach ( $payload->forDisplay() as $question ) {
            $this->assertArrayNotHasKey( 'answer', $question );
            $this->assertArrayNotHasKey( 'explanation', $question );
        }
    }

    public function test_the_rendered_quiz_contains_no_explanation_text(): void {
        $lesson  = CourseRegistry::lesson( self::COURSE, $this->lesson );
        $payload = QuizPayload::forLesson( self::COURSE, $this->lesson );

        $html = LessonRenderer::render( $lesson->body(), self::COURSE, $this->lesson )['html'];

        $start = strpos( $html, '<section class="tt-quiz"' );
        $this->assertNotFalse( $start, 'The quiz section did not render.' );

        $end  = strpos( $html, '</section>', $start );
        $form = substr( $html, $start, $end - $start );

        foreach ( $payload->questions() as $question ) {
            $why = trim( (string) ( $question['explanation'] ?? '' ) );
            if ( $why !== '' ) {
                $this->assertStringNotContainsString( esc_html( $why ), $form );
            }
        }
    }

    /**
     * The stored order is the answer for every sequencing question in the
     * corpus, so an unshuffled render is a leak. Twenty draws: a shuffle
     * that never moves a four-option list in twenty tries is not a
     * shuffle.
     */
    public function test_sequencing_options_are_shuffled(): void {
        $checked = 0;

        foreach ( CourseRegistry::lessons( self::COURSE ) as $slug => $lesson ) {
            if ( ! $lesson->hasQuiz() ) {
                continue;
            }

            $payload = QuizPayload::forLesson( self::COURSE, $slug );

            foreach ( $payload->questions() as $question ) {
                if ( ! in_array( $question['type'], [ 'order', 'match' ], true ) ) {
                    continue;
                }

                $checked++;
                $stored = array_values( array_map( 'strval', $question['options'] ) );
                $moved  = false;

                for ( $i = 0; $i < 20 && ! $moved; $i++ ) {
                    foreach ( $payload->forDisplay() as $shown ) {
                        if ( $shown['id'] === $question['id'] && $shown['options'] !== $stored ) {
                            $moved = true;
                        }
                    }
                }

                $this->assertTrue( $moved, "Options for {$slug}/{$question['id']} never move." );
            }
        }

        $this->assertGreaterThan( 0, $checked, 'No sequencing questions found to check.' );
    }

    // ── scoring ────────────────────────────────────────────────────────

    public function test_a_perfect_submission_passes_every_quiz_in_the_corpus(): void {
        foreach ( CourseRegistry::lessons( self::COURSE ) as $slug => $lesson ) {
            if ( ! $lesson->hasQuiz() ) {
                continue;
            }

            $payload = QuizPayload::forLesson( self::COURSE, $slug );
            $result  = QuizScorer::score(
                $payload,
                QuizSubmission::normalise( $payload, self::perfectAnswers( $payload ) )
            );

            $this->assertSame( $payload->count(), $result['score'], "Perfect run on {$slug} lost marks." );
            $this->assertTrue( $result['passed'] );
        }
    }

    public function test_an_empty_submission_scores_nothing(): void {
        $payload = QuizPayload::forLesson( self::COURSE, $this->lesson );
        $result  = QuizScorer::score( $payload, [] );

        $this->assertSame( 0, $result['score'] );
        $this->assertFalse( $result['passed'] );
    }

    /**
     * A quiz where skipping cannot hurt you is one a reader passes by
     * answering only what they are sure of.
     */
    public function test_a_skipped_question_is_wrong_not_ignored(): void {
        $payload   = QuizPayload::forLesson( self::COURSE, $this->lesson );
        $answers   = self::perfectAnswers( $payload );
        $questions = $payload->questions();

        unset( $answers[ $questions[0]['id'] ] );

        $result = QuizScorer::score( $payload, QuizSubmission::normalise( $payload, $answers ) );

        $this->assertSame( $payload->count() - 1, $result['score'] );
    }

    public function test_a_reversed_ordering_is_wrong(): void {
        [ $slug, $question ] = $this->firstQuestionOfType( 'order' );
        $payload = QuizPayload::forLesson( self::COURSE, $slug );

        $options   = array_values( $question['options'] );
        $positions = [];
        foreach ( array_reverse( (array) $question['answer'] ) as $slot => $index ) {
            $positions[ $options[ (int) $index ] ] = (string) ( $slot + 1 );
        }

        $result = QuizScorer::score(
            $payload,
            QuizSubmission::normalise( $payload, [ $question['id'] => $positions ] )
        );

        $this->assertFalse( $this->verdictFor( $result, $question['id'] ) );
    }

    /** Half an ordering is not half an understanding of the sequence. */
    public function test_a_partial_multiple_choice_answer_gets_no_credit(): void {
        [ $slug, $question ] = $this->firstQuestionOfType( 'multiple' );
        $payload = QuizPayload::forLesson( self::COURSE, $slug );

        $options = array_values( $question['options'] );
        $partial = [ $options[ (int) $question['answer'][0] ] ];

        $result = QuizScorer::score(
            $payload,
            QuizSubmission::normalise( $payload, [ $question['id'] => $partial ] )
        );

        $this->assertFalse( $this->verdictFor( $result, $question['id'] ) );
    }

    public function test_an_unknown_option_label_scores_wrong_rather_than_throwing(): void {
        $payload = QuizPayload::forLesson( self::COURSE, $this->lesson );
        $first   = $payload->questions()[0]['id'];

        $result = QuizScorer::score( $payload, [ $first => 'nothing anyone offered' ] );

        $this->assertFalse( $this->verdictFor( $result, $first ) );
    }

    /** Explanations come back for right answers too. */
    public function test_the_result_carries_explanations_for_every_question(): void {
        $payload = QuizPayload::forLesson( self::COURSE, $this->lesson );
        $result  = QuizScorer::score(
            $payload,
            QuizSubmission::normalise( $payload, self::perfectAnswers( $payload ) )
        );

        foreach ( $result['questions'] as $question ) {
            $source = $payload->question( $question['id'] );
            if ( trim( (string) ( $source['explanation'] ?? '' ) ) !== '' ) {
                $this->assertNotSame( '', $question['explanation'] );
            }
        }
    }

    // ── context ────────────────────────────────────────────────────────

    public function test_a_quiz_outside_a_lesson_renders_a_placeholder_not_a_form(): void {
        $html = LessonRenderer::render( "```tt-quiz\n```\n" )['html'];

        $this->assertStringContainsString( 'tt-quiz--pending', $html );
        $this->assertStringNotContainsString( '<form', $html );
    }

    /** A block that throws must not leave the next lesson under this identity. */
    public function test_lesson_context_does_not_survive_a_render(): void {
        LessonRenderer::render( "```tt-quiz\n```\n", self::COURSE, $this->lesson );

        $this->assertFalse( LessonContext::isSet() );
    }

    // ── REST ───────────────────────────────────────────────────────────

    public function test_quiz_routes_are_registered(): void {
        $this->assertArrayHasKey(
            '/talenttrack/v1/courses/(?P<slug>[a-z0-9-]+)/quiz/(?P<lesson>[a-z0-9-]+)',
            rest_get_server()->get_routes()
        );
    }

    public function test_submitting_a_pass_records_it_and_unlocks_the_lesson(): void {
        wp_set_current_user( $this->user_id );

        $payload  = QuizPayload::forLesson( self::COURSE, $this->lesson );
        $request  = new WP_REST_Request( 'POST', '/talenttrack/v1/courses/' . self::COURSE . '/quiz/' . $this->lesson );
        $request->set_param( 'q', self::perfectAnswers( $payload ) );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data()['data'] ?? $response->get_data();
        $this->assertTrue( $data['passed'] );
        $this->assertSame( $payload->count(), $data['score'] );

        $enrolment = ( new EnrolmentRepository() )->findFor( $this->person_id, self::COURSE );
        $this->assertNotNull( $enrolment );

        $row = ( new ProgressRepository() )->find( (int) $enrolment->id, $this->lesson );
        $this->assertNotNull( $row->quiz_passed_at, 'Passing must record the pass on the lesson.' );
    }

    /**
     * Every attempt is kept, passed or not: a coach who passed on the
     * fourth try has a different record than one who passed first time.
     */
    public function test_failed_attempts_are_recorded_too(): void {
        wp_set_current_user( $this->user_id );

        $fail = new WP_REST_Request( 'POST', '/talenttrack/v1/courses/' . self::COURSE . '/quiz/' . $this->lesson );
        $fail->set_param( 'q', [] );
        $response = rest_get_server()->dispatch( $fail );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data()['data'] ?? $response->get_data();
        $this->assertFalse( $data['passed'] );

        $enrolment = ( new EnrolmentRepository() )->findFor( $this->person_id, self::COURSE );
        $this->assertSame( 1, ( new QuizAttemptRepository() )->countFor( (int) $enrolment->id, $this->lesson ) );

        $row = ( new ProgressRepository() )->find( (int) $enrolment->id, $this->lesson );
        $this->assertTrue( $row === null || $row->quiz_passed_at === null, 'A failed attempt must not mark the quiz passed.' );
    }

    public function test_attempts_endpoint_returns_the_readers_own_history(): void {
        wp_set_current_user( $this->user_id );

        $payload = QuizPayload::forLesson( self::COURSE, $this->lesson );

        $miss = new WP_REST_Request( 'POST', '/talenttrack/v1/courses/' . self::COURSE . '/quiz/' . $this->lesson );
        $miss->set_param( 'q', [] );
        rest_get_server()->dispatch( $miss );

        $hit = new WP_REST_Request( 'POST', '/talenttrack/v1/courses/' . self::COURSE . '/quiz/' . $this->lesson );
        $hit->set_param( 'q', self::perfectAnswers( $payload ) );
        rest_get_server()->dispatch( $hit );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/courses/' . self::COURSE . '/quiz/' . $this->lesson )
        );

        $data = $response->get_data()['data'] ?? $response->get_data();

        $this->assertCount( 2, $data['attempts'] );
        // Newest first, and the reader's own answers are not echoed back.
        $this->assertTrue( $data['attempts'][0]['passed'] );
        $this->assertArrayNotHasKey( 'answers', $data['attempts'][0] );
    }

    public function test_a_locked_lesson_refuses_a_submission(): void {
        wp_set_current_user( $this->user_id );

        $slugs  = array_keys( CourseRegistry::lessons( self::COURSE ) );
        $locked = $slugs[3];

        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/courses/' . self::COURSE . '/quiz/' . $locked );
        $request->set_param( 'q', [] );

        $this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
    }

    public function test_submitting_requires_the_view_capability(): void {
        wp_set_current_user( 0 );

        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/courses/' . self::COURSE . '/quiz/' . $this->lesson );
        $request->set_param( 'q', [] );

        $this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * The submission a reader would send having answered everything
     * correctly, in the shapes the four form controls produce.
     *
     * @return array<string, mixed>
     */
    private static function perfectAnswers( QuizPayload $payload ): array {
        $out = [];

        foreach ( $payload->questions() as $question ) {
            $options = array_values( array_map( 'strval', $question['options'] ) );
            $answer  = $question['answer'];

            if ( $question['type'] === 'single' ) {
                $out[ $question['id'] ] = $options[ (int) $answer ];
                continue;
            }

            if ( $question['type'] === 'order' ) {
                $positions = [];
                foreach ( (array) $answer as $slot => $index ) {
                    $positions[ $options[ (int) $index ] ] = (string) ( $slot + 1 );
                }
                $out[ $question['id'] ] = $positions;
                continue;
            }

            $labels = [];
            foreach ( (array) $answer as $index ) {
                $labels[] = $options[ (int) $index ];
            }
            $out[ $question['id'] ] = $labels;
        }

        return $out;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function firstQuestionOfType( string $type ): array {
        foreach ( CourseRegistry::lessons( self::COURSE ) as $slug => $lesson ) {
            if ( ! $lesson->hasQuiz() ) {
                continue;
            }
            $payload = QuizPayload::forLesson( self::COURSE, $slug );
            foreach ( $payload->questions() as $question ) {
                if ( $question['type'] === $type ) {
                    return [ $slug, $question ];
                }
            }
        }

        $this->fail( "The corpus has no {$type} question to exercise." );
    }

    /** @param array<string, mixed> $result */
    private function verdictFor( array $result, string $question_id ): bool {
        foreach ( $result['questions'] as $question ) {
            if ( $question['id'] === $question_id ) {
                return (bool) $question['correct'];
            }
        }

        $this->fail( "No verdict returned for {$question_id}." );
    }
}
