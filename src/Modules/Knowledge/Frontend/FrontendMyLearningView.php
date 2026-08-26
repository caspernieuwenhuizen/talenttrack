<?php
namespace TT\Modules\Knowledge\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\CourseCompletionService;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMyLearningView — one coach's own learning record.
 *
 * Sits beside My PDP and My certifications in the "Me" group, because
 * that is what it is: the training half of a staff member's own
 * development, which is otherwise spread across whichever courses they
 * happen to remember starting.
 *
 * Own record only. Seeing anybody else's needs
 * `tt_view_knowledge_statistics`, and that is the roll-up report in #2650
 * rather than this page.
 */
class FrontendMyLearningView extends FrontendViewBase {

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        KnowledgeAssets::enqueue();
    }

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'My learning', 'talenttrack' );

        if ( ! current_user_can( 'tt_view_knowledge' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        $person_id = KnowledgePerson::forUser( $user_id );

        if ( $person_id <= 0 ) {
            // #2875 — this used to state a condition and stop: "only
            // available for people linked to a staff record". It named
            // something that appears nowhere in the interface under that
            // name, and left the reader unable to tell whether they had done
            // something wrong, whether their role was excluded, or whether
            // somebody else had to act.
            //
            // The course library, in this same feature, already degrades the
            // right way — it explains the consequence and leaves the door
            // open. Matching it, rather than inventing a third wording for
            // the same condition.
            echo '<p class="tt-notice">'
                . esc_html__( 'Your login is not linked to a staff record, so course progress cannot be saved and you are not enrolled on anything yet.', 'talenttrack' )
                . ' '
                . esc_html__( 'An academy administrator can link your login to your staff record under Access control. Until then you can still read every course.', 'talenttrack' )
                . '</p>';

            $library_url = KnowledgeLinks::library();
            CrossViewLink::render( 'knowledge', static function () use ( $library_url ): void {
                echo '<p><a class="tt-btn tt-btn-primary" href="' . esc_url( $library_url ) . '">'
                    . esc_html__( 'Browse the library', 'talenttrack' ) . '</a></p>';
            } );
            return;
        }

        $enrolments = ( new EnrolmentRepository() )->listForPerson( $person_id );

        if ( $enrolments === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'You are not on any course yet.', 'talenttrack' ) . '</p>';
            $library_url = KnowledgeLinks::library();
            CrossViewLink::render( 'courses', static function () use ( $library_url ): void {
                echo '<p><a class="tt-btn tt-btn-primary" href="' . esc_url( $library_url ) . '">'
                    . esc_html__( 'Browse the library', 'talenttrack' ) . '</a></p>';
            } );
            return;
        }

        $completion = new CourseCompletionService();

        echo '<div class="tt-knowledge-table-scroll">';
        echo '<table class="tt-knowledge-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Course', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'State', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Progress', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Due', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $enrolments as $enrolment ) {
            self::renderRow( $enrolment, $completion );
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /** @param object $enrolment */
    private static function renderRow( object $enrolment, CourseCompletionService $completion ): void {
        $slug     = (string) $enrolment->course_slug;
        $manifest = CourseRegistry::get( $slug );
        $retired  = $manifest === null;

        echo '<tr' . ( $retired ? ' class="tt-knowledge-row--retired"' : '' ) . '>';

        echo '<th scope="row">';
        if ( $retired ) {
            // A course withdrawn from the corpus keeps its row: the
            // completion history has to outlive the course that produced
            // it. Named as retired rather than shown as a broken link.
            echo esc_html( $slug ) . ' <span class="tt-knowledge-chip tt-knowledge-chip--retired">'
                . esc_html__( 'Retired', 'talenttrack' ) . '</span>';
        } else {
            $url   = KnowledgeLinks::course( $slug );
            $title = $manifest->title();
            CrossViewLink::render( 'course', static function () use ( $url, $title ): void {
                echo '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
            } );
        }
        echo '</th>';

        echo '<td>';
        KnowledgeChrome::renderStateChip( \TT\Shared\Content\GateVerdict::available(), $enrolment );
        echo '</td>';

        echo '<td>';
        if ( $retired ) {
            echo '<span class="tt-knowledge-muted">' . esc_html__( 'Not counted', 'talenttrack' ) . '</span>';
        } else {
            KnowledgeChrome::renderProgressBar( $completion->progressFor( (int) $enrolment->id, $slug ) );
        }
        echo '</td>';

        echo '<td>';
        if ( ! empty( $enrolment->due_at ) ) {
            $timestamp = strtotime( (string) $enrolment->due_at );
            echo esc_html( $timestamp !== false ? date_i18n( get_option( 'date_format' ), $timestamp ) : '' );
        } else {
            echo '<span class="tt-knowledge-muted">' . esc_html__( '—', 'talenttrack' ) . '</span>';
        }
        echo '</td>';

        echo '</tr>';
    }
}
