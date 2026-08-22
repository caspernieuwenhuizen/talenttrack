<?php
namespace TT\Modules\Knowledge\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\CourseAccessResolver;
use TT\Modules\Knowledge\CourseCompletionService;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\LessonRenderer;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordSpine;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendLessonView — the reader.
 *
 * The spine pins the course name, because a coach eleven lessons into a
 * long course should never have to scroll up to remember which one they
 * are in.
 *
 * Marking a lesson read is an explicit control, not a scroll heuristic. A
 * coach who skims and clicks it has made a claim; a scroll listener has
 * only measured a thumb. It posts a real form, so the lesson completes
 * with JavaScript unavailable; the reader script upgrades that to an
 * in-place save.
 */
class FrontendLessonView extends FrontendViewBase {

    /** Hidden field marking our own POST. */
    private const ACTION = 'tt_knowledge_mark_read';

    public static function render( int $user_id, bool $is_admin ): void {
        $library = __( 'Knowledge library', 'talenttrack' );

        if ( ! current_user_can( 'tt_view_knowledge' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( $library );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }

        $course_slug = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
        $lesson_slug = isset( $_GET['lesson'] ) ? sanitize_key( wp_unslash( $_GET['lesson'] ) ) : '';

        $manifest = CourseRegistry::get( $course_slug );
        $lesson   = CourseRegistry::lesson( $course_slug, $lesson_slug );

        $person_id = KnowledgePerson::forUser( $user_id );
        $access    = new CourseAccessResolver();
        $gate      = $access->forLesson( $course_slug, $lesson_slug, $person_id, $user_id );

        if ( $manifest === null || $lesson === null || $gate->isUnavailable() || $gate->isDenied() ) {
            self::renderMissing( $library );
            return;
        }

        // A locked lesson is reachable by URL and must not render its
        // body. The reader is told which lesson opens it, not just that
        // this one is shut.
        if ( $gate->isLocked() ) {
            FrontendBreadcrumbs::fromDashboard( $lesson->title(), self::crumbs( $library, $course_slug, $manifest->title() ) );
            KnowledgeAssets::enqueue();
            self::renderHeader( $lesson->title() );
            echo '<p class="tt-notice tt-notice--locked">' . esc_html( KnowledgeChrome::lockReason( $gate ) ) . '</p>';
            return;
        }

        $enrolments = new EnrolmentRepository();
        $progress   = new ProgressRepository();
        $completion = new CourseCompletionService();

        $enrolment_id = self::ensureEnrolment( $enrolments, $person_id, $course_slug );
        $notice       = self::handlePost( $enrolment_id, $course_slug, $lesson_slug, $progress, $completion );

        $row        = $enrolment_id > 0 ? $progress->find( $enrolment_id, $lesson_slug ) : null;
        $tool_state = $progress->toolState( $row );
        $is_read    = $row !== null && $row->read_at !== null;

        KnowledgeAssets::enqueueReader( $course_slug, $lesson_slug, $tool_state );

        FrontendBreadcrumbs::fromDashboard( $lesson->title(), self::crumbs( $library, $course_slug, $manifest->title() ) );

        RecordSpine::render( [
            'name' => $manifest->title(),
            'meta' => self::position( $manifest->lessonSlugs(), $lesson_slug ),
        ] );

        self::renderHeader( $lesson->title() );

        if ( $notice !== '' ) {
            echo '<p class="tt-notice tt-notice--success">' . esc_html( $notice ) . '</p>';
        }

        echo '<p class="tt-knowledge-lesson__position">'
            . esc_html( self::position( $manifest->lessonSlugs(), $lesson_slug ) )
            . '</p>';

        self::renderObjectives( $lesson->objectives() );

        echo '<article class="tt-lesson-body">';
        echo LessonRenderer::renderAndEnqueue( $lesson->body() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes; see LessonMarkdown.
        echo '</article>';

        self::renderCompletion( $enrolment_id, $course_slug, $lesson_slug, $is_read, $lesson->hasQuiz(), $lesson->hasAssignment(), $completion );
    }

    /** Learning objectives, when the lesson declares them. */
    private static function renderObjectives( array $objectives ): void {
        if ( $objectives === [] ) {
            return;
        }

        echo '<section class="tt-knowledge-lesson__objectives">';
        echo '<h2>' . esc_html__( 'What you will learn', 'talenttrack' ) . '</h2>';
        echo '<ul>';
        foreach ( $objectives as $objective ) {
            echo '<li>' . esc_html( (string) $objective ) . '</li>';
        }
        echo '</ul>';
        echo '</section>';
    }

    /**
     * The end-of-lesson block: mark it read, and say what else this lesson
     * still wants before it counts as finished.
     *
     * Naming the outstanding requirements matters. A coach who marks a
     * lesson read and sees the course percentage not move needs to know it
     * is the quiz, not a bug.
     */
    private static function renderCompletion(
        int $enrolment_id,
        string $course_slug,
        string $lesson_slug,
        bool $is_read,
        bool $has_quiz,
        bool $has_assignment,
        CourseCompletionService $completion
    ): void {
        echo '<section class="tt-knowledge-lesson__completion" data-tt-lesson-completion>';

        if ( $enrolment_id <= 0 ) {
            echo '<p class="tt-knowledge-lesson__note">'
                . esc_html__( 'Your login is not linked to a staff record, so progress is not saved for this lesson.', 'talenttrack' )
                . '</p>';
            echo '</section>';
            return;
        }

        if ( $is_read ) {
            echo '<p class="tt-knowledge-lesson__read" data-tt-read-state>'
                . esc_html__( 'You have marked this lesson as read.', 'talenttrack' )
                . '</p>';
        } else {
            echo '<form method="post" class="tt-knowledge-lesson__form">';
            wp_nonce_field( self::ACTION, '_tt_knowledge_nonce' );
            echo '<input type="hidden" name="tt_knowledge_action" value="' . esc_attr( self::ACTION ) . '" />';
            echo '<button type="submit" class="tt-btn tt-btn-primary" data-tt-mark-read>'
                . esc_html__( 'Mark as read', 'talenttrack' )
                . '</button>';
            echo '</form>';
        }

        $outstanding = [];
        if ( $has_quiz ) {
            $outstanding[] = __( 'passing the check for this module', 'talenttrack' );
        }
        if ( $has_assignment ) {
            $outstanding[] = __( 'having its assignment approved', 'talenttrack' );
        }

        if ( $outstanding !== [] ) {
            echo '<p class="tt-knowledge-lesson__note">' . esc_html( sprintf(
                /* translators: %s: a list of outstanding requirements */
                __( 'This lesson also needs %s before it counts as finished.', 'talenttrack' ),
                self::joinList( $outstanding )
            ) ) . '</p>';
        }

        // Forward motion after finishing, not a navigation strip: the
        // breadcrumb chain remains the way back out (§5a).
        $next = $completion->nextLesson( $enrolment_id, $course_slug );
        if ( $next !== null && $next !== $lesson_slug ) {
            $next_lesson = CourseRegistry::lesson( $course_slug, $next );
            if ( $next_lesson !== null ) {
                $url   = KnowledgeLinks::lesson( $course_slug, $next );
                $label = sprintf(
                    /* translators: %s: the title of the next lesson */
                    __( 'Next: %s', 'talenttrack' ),
                    $next_lesson->title()
                );

                CrossViewLink::render( 'lesson', static function () use ( $url, $label ): void {
                    echo '<p class="tt-knowledge-lesson__next"><a class="tt-btn tt-btn-secondary" href="' . esc_url( $url ) . '">'
                        . esc_html( $label )
                        . '</a></p>';
                } );
            }
        }

        echo '</section>';
    }

    /**
     * Handle the mark-as-read POST.
     *
     * Returns the confirmation to show. The gate has already run above, so
     * a locked lesson never reaches this.
     */
    private static function handlePost(
        int $enrolment_id,
        string $course_slug,
        string $lesson_slug,
        ProgressRepository $progress,
        CourseCompletionService $completion
    ): string {
        if ( $enrolment_id <= 0 ) {
            return '';
        }

        if ( ( $_POST['tt_knowledge_action'] ?? '' ) !== self::ACTION ) {
            return '';
        }

        if ( ! isset( $_POST['_tt_knowledge_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tt_knowledge_nonce'] ) ), self::ACTION )
        ) {
            return '';
        }

        $progress->markRead( $enrolment_id, $lesson_slug );
        $completion->recalculate( $enrolment_id );

        return __( 'Marked as read.', 'talenttrack' );
    }

    /**
     * Find or create the enrolment.
     *
     * Opening a lesson is what starts a course; an explicit enrol step
     * before you can read lesson one is a step nobody would understand.
     */
    private static function ensureEnrolment( EnrolmentRepository $enrolments, int $person_id, string $course_slug ): int {
        if ( $person_id <= 0 ) {
            return 0;
        }

        $enrolment = $enrolments->findFor( $person_id, $course_slug );
        if ( $enrolment === null ) {
            $enrolments->enrol( $person_id, $course_slug );
            $enrolment = $enrolments->findFor( $person_id, $course_slug );
        }

        if ( $enrolment === null ) {
            return 0;
        }

        $enrolments->markStarted( (int) $enrolment->id );

        return (int) $enrolment->id;
    }

    private static function renderMissing( string $library ): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Lesson not found', 'talenttrack' ), [
            FrontendBreadcrumbs::viewCrumb( 'knowledge', $library ),
        ] );
        self::renderHeader( __( 'Lesson not found', 'talenttrack' ) );
        echo '<p class="tt-notice">' . esc_html__( 'That lesson is not part of this course.', 'talenttrack' ) . '</p>';
    }

    /**
     * The §5a chain: Dashboard › Knowledge library › Course › Lesson.
     *
     * The course crumb is itself the way back up to the course; a separate
     * button pointing at the same place would be the third affordance §5a
     * forbids.
     *
     * @return list<array{label:string,url:string}>
     */
    private static function crumbs( string $library, string $course_slug, string $course_title ): array {
        return [
            FrontendBreadcrumbs::viewCrumb( 'knowledge', $library ),
            FrontendBreadcrumbs::viewCrumb( 'course', $course_title, [ 'slug' => $course_slug ] ),
        ];
    }

    /** "Lesson 4 of 11". @param list<string> $lessons */
    private static function position( array $lessons, string $lesson_slug ): string {
        $index = array_search( $lesson_slug, $lessons, true );
        if ( $index === false ) {
            return '';
        }

        return sprintf(
            /* translators: 1: this lesson's number, 2: total lessons */
            __( 'Lesson %1$d of %2$d', 'talenttrack' ),
            (int) $index + 1,
            count( $lessons )
        );
    }

    /**
     * "a, b and c".
     *
     * The conjunction is translated because it genuinely differs; the
     * comma is not, because a bare `", "` msgid is a puzzle for a
     * translator and reads the same in every language this ships in.
     *
     * @param list<string> $items
     */
    private static function joinList( array $items ): string {
        if ( count( $items ) === 1 ) {
            return $items[0];
        }

        $last = array_pop( $items );

        return sprintf(
            /* translators: 1: comma-separated list of items, 2: the final item */
            __( '%1$s and %2$s', 'talenttrack' ),
            implode( ', ', $items ),
            $last
        );
    }
}
