<?php
namespace TT\Modules\Knowledge\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\CourseAccessResolver;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Shared\Content\GateVerdict;

/**
 * KnowledgeChrome — the small pieces every knowledge surface repeats.
 *
 * A progress bar, a state chip, and the sentence that explains a lock.
 * Three views need all three, and three copies would drift in wording
 * long before they drifted in markup.
 *
 * Composes only. Which verdict a reader gets is the resolver's decision
 * (#2645); this turns a decision into markup (CLAUDE.md §4).
 */
final class KnowledgeChrome {

    /**
     * A progress bar that reads as a number too.
     *
     * The percentage is in the text, not only in the bar's width: a bar
     * alone is unreadable to a screen reader and imprecise to everyone
     * else. `aria-hidden` on the bar because the figure beside it already
     * says the same thing.
     *
     * @param array{completed:int,total:int,percent:int} $progress
     */
    public static function renderProgressBar( array $progress ): void {
        $percent = max( 0, min( 100, (int) $progress['percent'] ) );

        echo '<div class="tt-knowledge-progress">';
        echo '<div class="tt-knowledge-progress__track" aria-hidden="true">';
        // Width is the one value a stylesheet cannot know.
        echo '<span class="tt-knowledge-progress__fill" style="width:' . esc_attr( (string) $percent ) . '%"></span>'; /* tt-inline-ok */
        echo '</div>';
        echo '<p class="tt-knowledge-progress__figure">' . esc_html( sprintf(
            /* translators: 1: lessons completed, 2: total lessons, 3: percentage */
            __( '%1$d of %2$d lessons · %3$d%%', 'talenttrack' ),
            (int) $progress['completed'],
            (int) $progress['total'],
            $percent
        ) ) . '</p>';
        echo '</div>';
    }

    /**
     * The course's state, as a chip.
     *
     * State is carried by the word as well as the colour — a coach who
     * cannot distinguish the two greens still reads "Completed".
     *
     * @param object|null $enrolment
     */
    public static function renderStateChip( GateVerdict $verdict, ?object $enrolment ): void {
        if ( $verdict->isLocked() ) {
            self::chip( 'locked', __( 'Locked', 'talenttrack' ) );
            return;
        }

        if ( $enrolment === null ) {
            self::chip( 'new', __( 'Not started', 'talenttrack' ) );
            return;
        }

        $status = (string) $enrolment->status;

        if ( $status === EnrolmentRepository::STATUS_COMPLETED ) {
            self::chip( 'done', __( 'Completed', 'talenttrack' ) );
            return;
        }

        if ( self::isOverdue( $enrolment ) ) {
            self::chip( 'overdue', __( 'Overdue', 'talenttrack' ) );
            return;
        }

        self::chip( 'active', __( 'In progress', 'talenttrack' ) );
    }

    /**
     * Why something is locked, in a sentence a coach can act on.
     *
     * Named rather than generic: "Finish X first" tells the reader what to
     * do; "Locked" tells them only that they cannot.
     */
    public static function lockReason( GateVerdict $verdict ): string {
        $context = $verdict->context();

        if ( $verdict->reason() === CourseAccessResolver::REASON_PREREQUISITE ) {
            $prerequisite = CourseRegistry::get( (string) ( $context['course'] ?? '' ) );

            if ( $prerequisite !== null ) {
                return sprintf(
                    /* translators: %s: the name of the course that must be completed first */
                    __( 'Finish %s first.', 'talenttrack' ),
                    $prerequisite->title()
                );
            }

            return __( 'Another course has to be completed first.', 'talenttrack' );
        }

        if ( $verdict->reason() === CourseAccessResolver::REASON_SEQUENTIAL ) {
            return __( 'Finish the previous lesson first.', 'talenttrack' );
        }

        return __( 'Not available yet.', 'talenttrack' );
    }

    /**
     * Whether an enrolment is past its due date.
     *
     * Read here rather than stored: `due_at` is a date and "overdue" is a
     * comparison against now, so a stored flag would be wrong the morning
     * after it was written.
     *
     * @param object|null $enrolment
     */
    public static function isOverdue( ?object $enrolment ): bool {
        if ( $enrolment === null || empty( $enrolment->due_at ) ) {
            return false;
        }

        if ( (string) $enrolment->status === EnrolmentRepository::STATUS_COMPLETED ) {
            return false;
        }

        return strtotime( (string) $enrolment->due_at ) < strtotime( current_time( 'mysql' ) );
    }

    private static function chip( string $kind, string $label ): void {
        printf(
            '<span class="tt-knowledge-chip tt-knowledge-chip--%1$s">%2$s</span>',
            esc_attr( $kind ),
            esc_html( $label )
        );
    }
}
