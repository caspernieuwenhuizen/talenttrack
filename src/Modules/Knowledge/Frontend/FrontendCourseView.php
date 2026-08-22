<?php
namespace TT\Modules\Knowledge\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\CourseAccessResolver;
use TT\Modules\Knowledge\CourseCompletionService;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Shared\Content\GateVerdict;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordSpine;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendCourseView — one course: what it is, and where you are in it.
 *
 * The lesson list is the navigation into the course, and the breadcrumb
 * crumb back to the library is the way out (§5a). No tab strip: a course
 * has one view, not alternative views of one record, and adding tabs for
 * the sake of using `RecordSpine`'s tab slot would be decoration.
 *
 * Locked lessons are listed with their reason rather than hidden. Hiding
 * them would make the course look shorter than it is, and nobody can work
 * towards something they cannot see.
 */
class FrontendCourseView extends FrontendViewBase {

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        KnowledgeAssets::enqueue();
    }

    public static function render( int $user_id, bool $is_admin ): void {
        $library = __( 'Knowledge library', 'talenttrack' );

        if ( ! current_user_can( 'tt_view_knowledge' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( $library );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }

        $slug      = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
        $person_id = KnowledgePerson::forUser( $user_id );
        $access    = new CourseAccessResolver();
        $manifest  = CourseRegistry::get( $slug );
        $verdict   = $access->forCourse( $slug, $person_id, $user_id );

        // Absent rather than forbidden: a "you may not see this" page
        // confirms the course exists here, which is what hiding it was for.
        if ( $manifest === null || ! $verdict->isListable() ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Course not found', 'talenttrack' ), [
                FrontendBreadcrumbs::viewCrumb( 'knowledge', $library ),
            ] );
            self::renderHeader( __( 'Course not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'That course is not in this library.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        FrontendBreadcrumbs::fromDashboard( $manifest->title(), [
            FrontendBreadcrumbs::viewCrumb( 'knowledge', $library ),
        ] );

        RecordSpine::render( [
            'name' => $manifest->title(),
            'meta' => self::spineMeta( $manifest->lessonSlugs() ),
        ] );

        self::renderHeader( $manifest->title() );

        $enrolments = new EnrolmentRepository();
        $completion = new CourseCompletionService();
        $enrolment  = $person_id > 0 ? $enrolments->findFor( $person_id, $slug ) : null;

        $progress = $enrolment !== null
            ? $completion->progressFor( (int) $enrolment->id, $slug )
            : [ 'completed' => 0, 'total' => count( $manifest->lessonSlugs() ), 'percent' => 0 ];

        echo '<p class="tt-knowledge-lede">' . esc_html( $manifest->summary() ) . '</p>';

        if ( $verdict->isLocked() ) {
            echo '<p class="tt-notice tt-notice--locked">' . esc_html( KnowledgeChrome::lockReason( $verdict ) ) . '</p>';
            return;
        }

        echo '<div class="tt-knowledge-course__state">';
        KnowledgeChrome::renderStateChip( $verdict, $enrolment );
        KnowledgeChrome::renderProgressBar( $progress );
        echo '</div>';

        self::renderResume( $slug, $enrolment, $completion );
        self::renderRequirements( $manifest->isSequential() );

        $states  = $enrolment !== null ? $completion->lessonStates( (int) $enrolment->id, $slug ) : [];
        $gates   = $access->forLessons( $slug, $person_id, $user_id );

        echo '<ol class="tt-knowledge-lessons">';
        foreach ( CourseRegistry::lessons( $slug ) as $lesson_slug => $lesson ) {
            self::renderLessonRow(
                $slug,
                $lesson_slug,
                $lesson->title(),
                $lesson->estimatedMinutes(),
                $states[ $lesson_slug ] ?? false,
                $gates[ $lesson_slug ] ?? GateVerdict::available()
            );
        }
        echo '</ol>';
    }

    /**
     * The resume affordance: one primary action pointing at the first
     * incomplete lesson.
     *
     * Not a second navigation affordance under §5 — it is the course's
     * primary action, the same way a form's Save is. It moves forward into
     * the record rather than back out of it, which is what §5a's
     * two-affordance rule is about.
     *
     * @param object|null $enrolment
     */
    private static function renderResume( string $course_slug, ?object $enrolment, CourseCompletionService $completion ): void {
        $next = $enrolment !== null
            ? $completion->nextLesson( (int) $enrolment->id, $course_slug )
            : self::firstLesson( $course_slug );

        if ( $next === null ) {
            echo '<p class="tt-knowledge-course__resume tt-knowledge-course__resume--done">'
                . esc_html__( 'You have completed every lesson in this course.', 'talenttrack' )
                . '</p>';
            return;
        }

        $lesson = CourseRegistry::lesson( $course_slug, $next );
        if ( $lesson === null ) {
            return;
        }

        $url   = KnowledgeLinks::lesson( $course_slug, $next );
        $label = $enrolment === null
            ? __( 'Start the course', 'talenttrack' )
            : __( 'Continue where you left off', 'talenttrack' );
        $title = $lesson->title();

        CrossViewLink::render( 'lesson', static function () use ( $url, $label, $title ): void {
            echo '<p class="tt-knowledge-course__resume">';
            echo '<a class="tt-btn tt-btn-primary" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
            echo ' <span class="tt-knowledge-course__resume-target">' . esc_html( $title ) . '</span>';
            echo '</p>';
        } );
    }

    /** What finishing this course asks of the reader. */
    private static function renderRequirements( bool $sequential ): void {
        echo '<p class="tt-knowledge-course__requirements">';
        echo esc_html( $sequential
            ? __( 'Lessons open one at a time. A lesson counts as finished when you have read it, passed its check and had its assignment approved.', 'talenttrack' )
            : __( 'A lesson counts as finished when you have read it, passed its check and had its assignment approved.', 'talenttrack' ) );
        echo '</p>';
    }

    /** One row of the lesson list. */
    private static function renderLessonRow(
        string $course_slug,
        string $lesson_slug,
        string $title,
        int $minutes,
        bool $complete,
        GateVerdict $gate
    ): void {
        $locked = $gate->isLocked();

        $classes = 'tt-knowledge-lesson-row';
        if ( $complete ) {
            $classes .= ' tt-knowledge-lesson-row--done';
        }
        if ( $locked ) {
            $classes .= ' tt-knowledge-lesson-row--locked';
        }

        echo '<li class="' . esc_attr( $classes ) . '">';

        // State as a glyph AND a word: the tick alone is colour-and-shape
        // only, and the row has to read the same to a screen reader.
        echo '<span class="tt-knowledge-lesson-row__state">';
        if ( $complete ) {
            echo '<span aria-hidden="true">✓</span><span class="tt-visually-hidden">'
                . esc_html__( 'Completed', 'talenttrack' ) . '</span>';
        } elseif ( $locked ) {
            echo '<span aria-hidden="true">•</span><span class="tt-visually-hidden">'
                . esc_html__( 'Locked', 'talenttrack' ) . '</span>';
        } else {
            echo '<span aria-hidden="true">›</span><span class="tt-visually-hidden">'
                . esc_html__( 'Available', 'talenttrack' ) . '</span>';
        }
        echo '</span>';

        echo '<span class="tt-knowledge-lesson-row__title">';
        if ( $locked ) {
            echo esc_html( $title );
        } else {
            $url = KnowledgeLinks::lesson( $course_slug, $lesson_slug );
            CrossViewLink::render( 'lesson', static function () use ( $url, $title ): void {
                echo '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
            } );
        }
        echo '</span>';

        echo '<span class="tt-knowledge-lesson-row__meta">';
        if ( $locked ) {
            echo esc_html( KnowledgeChrome::lockReason( $gate ) );
        } elseif ( $minutes > 0 ) {
            echo esc_html( sprintf(
                /* translators: %d: estimated minutes for one lesson */
                __( '%d min', 'talenttrack' ),
                $minutes
            ) );
        }
        echo '</span>';

        echo '</li>';
    }

    private static function firstLesson( string $course_slug ): ?string {
        $manifest = CourseRegistry::get( $course_slug );
        if ( $manifest === null ) {
            return null;
        }

        $slugs = $manifest->lessonSlugs();

        return $slugs === [] ? null : $slugs[0];
    }

    /** @param list<string> $lessons */
    private static function spineMeta( array $lessons ): string {
        return sprintf(
            /* translators: %d: number of lessons */
            _n( '%d lesson', '%d lessons', count( $lessons ), 'talenttrack' ),
            count( $lessons )
        );
    }
}
