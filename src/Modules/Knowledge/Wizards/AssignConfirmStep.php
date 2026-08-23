<?php
namespace TT\Modules\Knowledge\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\Frontend\KnowledgeLinks;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — confirm and enrol (#2649).
 *
 * Says how many of the chosen people are actually new before it writes
 * anything. `EnrolmentRepository::enrol()` is idempotent on
 * `(club_id, course_slug, person_id)`, so re-assigning cannot duplicate — but
 * an administrator re-running the wizard over a squad deserves to see "3 new,
 * 12 already enrolled" rather than a silent success that leaves them
 * wondering whether they just reset a dozen due dates.
 *
 * They did not: an existing enrolment is left exactly as it was, deadline
 * included. Changing somebody's deadline is a different decision from
 * assigning them a course, and this wizard is not where it belongs.
 */
final class AssignConfirmStep implements WizardStepInterface {

    public function slug(): string { return 'confirm'; }

    public function label(): string { return __( 'Check and assign', 'talenttrack' ); }

    public function render( array $state ): void {
        $course_slug = (string) ( $state[ AssignCourseStep::FIELD ] ?? '' );
        $manifest    = $course_slug !== '' ? CourseRegistry::get( $course_slug ) : null;
        $ids         = array_map( 'intval', (array) ( $state[ AssignPeopleStep::FIELD ] ?? [] ) );

        if ( $manifest === null || $ids === [] ) {
            echo '<p>' . esc_html__( 'Go back and choose a course and at least one person.', 'talenttrack' ) . '</p>';
            return;
        }

        [ $fresh, $already ] = self::split( $ids, $course_slug );

        echo '<p>' . esc_html( sprintf(
            /* translators: %s: the course title */
            __( 'Assigning %s.', 'talenttrack' ),
            $manifest->title()
        ) ) . '</p>';

        echo '<ul class="tt-assign-summary">';

        echo '<li>' . esc_html( sprintf(
            /* translators: %d: how many people will be newly enrolled */
            _n( '%d person will be enrolled.', '%d people will be enrolled.', count( $fresh ), 'talenttrack' ),
            count( $fresh )
        ) ) . '</li>';

        if ( $already !== [] ) {
            echo '<li>' . esc_html( sprintf(
                /* translators: %d: how many people were already on the course */
                _n(
                    '%d person is already on this course and will be left as they are.',
                    '%d people are already on this course and will be left as they are.',
                    count( $already ),
                    'talenttrack'
                ),
                count( $already )
            ) ) . '</li>';
        }

        $due = (string) ( $state[ AssignDueDateStep::FIELD ] ?? '' );
        echo '<li>' . esc_html(
            $due === ''
                ? __( 'No deadline.', 'talenttrack' )
                : sprintf(
                    /* translators: %s: the deadline date */
                    __( 'Deadline: %s.', 'talenttrack' ),
                    date_i18n( get_option( 'date_format' ), (int) strtotime( $due ) )
                )
        ) . '</li>';

        echo '</ul>';
    }

    public function validate( array $post, array $state ) {
        return [];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $course_slug = (string) ( $state[ AssignCourseStep::FIELD ] ?? '' );
        $ids         = array_map( 'intval', (array) ( $state[ AssignPeopleStep::FIELD ] ?? [] ) );
        $due         = (string) ( $state[ AssignDueDateStep::FIELD ] ?? '' );

        if ( $course_slug === '' || $ids === [] ) {
            return new \WP_Error( 'incomplete', __( 'Go back and choose a course and at least one person.', 'talenttrack' ) );
        }

        $repo    = new EnrolmentRepository();
        $created = 0;

        foreach ( $ids as $person_id ) {
            $existing = $repo->findFor( $person_id, $course_slug );
            if ( $existing !== null ) {
                continue;
            }

            $id = $repo->enrol( $person_id, $course_slug, [
                'assigned_by' => get_current_user_id(),
                'due_at'      => $due !== '' ? $due : null,
            ] );

            if ( $id > 0 ) $created++;
        }

        Logger::info( 'knowledge.course_assigned', [
            'course'  => $course_slug,
            'chosen'  => count( $ids ),
            'created' => $created,
            'due_at'  => $due,
        ] );

        return [ 'redirect_url' => KnowledgeLinks::course( $course_slug ) ];
    }

    /**
     * Split the chosen people into those who are new and those already on it.
     *
     * @param  list<int> $ids
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function split( array $ids, string $course_slug ): array {
        $repo    = new EnrolmentRepository();
        $fresh   = [];
        $already = [];

        foreach ( $ids as $person_id ) {
            if ( $repo->findFor( $person_id, $course_slug ) === null ) {
                $fresh[] = $person_id;
            } else {
                $already[] = $person_id;
            }
        }

        return [ $fresh, $already ];
    }
}
