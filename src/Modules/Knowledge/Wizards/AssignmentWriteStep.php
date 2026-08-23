<?php
namespace TT\Modules\Knowledge\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\CourseAccessResolver;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\SubmissionQueue;
use TT\Modules\Knowledge\SubmissionService;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — the answer (#2648).
 *
 * Shows the assignment as written in the corpus and takes the coach's
 * response. The draft row is opened by `validate()` rather than `render()`,
 * because a render is a GET: opening a row on one would leave a draft
 * behind every time somebody looked at the wizard and closed the tab.
 */
final class AssignmentWriteStep implements WizardStepInterface {

    public const FIELD = 'assignment_body';

    public function slug(): string { return 'write'; }

    public function label(): string { return __( 'Your answer', 'talenttrack' ); }

    public function render( array $state ): void {
        $course = self::courseSlug( $state );
        $lesson_slug = self::lessonSlug( $state );
        $lesson = $course !== '' ? CourseRegistry::lesson( $course, $lesson_slug ) : null;

        if ( $lesson === null || ! $lesson->hasAssignment() ) {
            echo '<p>' . esc_html__( 'That lesson has no assignment to hand in.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<h3 class="tt-wizard__subhead">' . esc_html( $lesson->title() ) . '</h3>';
        echo SubmissionQueue::assignmentHtml( $lesson ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes; see LessonMarkdown.

        $draft = (string) ( $state[ self::FIELD ] ?? '' );

        echo '<label class="tt-field" for="tt-wizard-assignment-body">';
        echo '<span class="tt-field__label">' . esc_html__( 'Your answer', 'talenttrack' ) . '</span>';
        echo '<textarea id="tt-wizard-assignment-body" name="' . esc_attr( self::FIELD ) . '"'
            . ' class="tt-input" rows="12" required'
            . ' placeholder="' . esc_attr__( 'Describe what you did with your own team, and what you found.', 'talenttrack' ) . '">'
            . esc_textarea( $draft )
            . '</textarea>';
        echo '</label>';
    }

    public function validate( array $post, array $state ) {
        $body = isset( $post[ self::FIELD ] ) ? (string) $post[ self::FIELD ] : '';

        if ( trim( wp_strip_all_tags( $body ) ) === '' ) {
            return new \WP_Error(
                'empty_body',
                __( 'Write your answer before continuing.', 'talenttrack' )
            );
        }

        $course = self::courseSlug( $state );
        $lesson = self::lessonSlug( $state );

        $person_id = KnowledgePerson::current();
        if ( $person_id <= 0 ) {
            return new \WP_Error(
                'no_person',
                __( 'Your login is not linked to a staff record, so coursework cannot be handed in.', 'talenttrack' )
            );
        }

        // The lesson has to actually be open to this reader. Without this a
        // hand-crafted URL would let somebody submit against a lesson the
        // sequential gate has not unlocked, and collect the approval for it.
        $verdict = ( new CourseAccessResolver() )->forLesson( $course, $lesson, $person_id );
        if ( ! $verdict->isAvailable() ) {
            return new \WP_Error(
                'locked',
                __( 'That lesson is not open to you yet.', 'talenttrack' )
            );
        }

        $enrolment = ( new EnrolmentRepository() )->findFor( $person_id, $course );
        if ( $enrolment === null ) {
            return new \WP_Error(
                'not_enrolled',
                __( 'You are not on that course.', 'talenttrack' )
            );
        }

        $service = new SubmissionService();
        $started = $service->startDraft( (int) $enrolment->id, $course, $lesson );

        if ( $started['status'] === SubmissionService::ERR_ALREADY ) {
            return new \WP_Error(
                'already_submitted',
                __( 'This assignment is already with a reviewer.', 'talenttrack' )
            );
        }

        if ( $started['status'] !== SubmissionService::OK ) {
            return new \WP_Error(
                'no_draft',
                __( 'That assignment could not be started.', 'talenttrack' )
            );
        }

        $saved = $service->saveDraft( $started['id'], sanitize_textarea_field( $body ) );
        if ( $saved['status'] !== SubmissionService::OK ) {
            return new \WP_Error(
                'not_saved',
                __( 'Your answer could not be saved.', 'talenttrack' )
            );
        }

        return [
            self::FIELD     => sanitize_textarea_field( $body ),
            'submission_id' => $started['id'],
            'course_slug'   => $course,
            'lesson_slug'   => $lesson,
        ];
    }

    public function nextStep( array $state ): ?string { return 'attach'; }

    public function submit( array $state ) { return null; }

    /**
     * The course, from state or from the URL the wizard was opened with.
     *
     * `slug` is what every other knowledge URL calls the course, so the
     * entry point carries the same name rather than inventing one.
     */
    public static function courseSlug( array $state ): string {
        $from_state = (string) ( $state['course_slug'] ?? '' );
        if ( $from_state !== '' ) return $from_state;

        return isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( (string) $_GET['slug'] ) ) : '';
    }

    public static function lessonSlug( array $state ): string {
        $from_state = (string) ( $state['lesson_slug'] ?? '' );
        if ( $from_state !== '' ) return $from_state;

        return isset( $_GET['lesson'] ) ? sanitize_key( wp_unslash( (string) $_GET['lesson'] ) ) : '';
    }
}
