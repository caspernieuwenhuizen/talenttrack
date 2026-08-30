<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\SubmissionRepository;

/**
 * KnowledgeGenerator — staff working through the shipped courses.
 *
 * These rows exist in service of a player record, the same justification
 * the staff-development generator makes: a head of academy uses who has
 * completed the periodisation course to decide who runs the conditioning
 * sessions for which age group.
 *
 * The spread matters more than the volume. A demo install where everyone
 * is at 0% or everyone is at 100% makes the statistics report (#2650) look
 * like it works while proving nothing, so the cohort is deliberately
 * mixed: some finished, some mid-course, one overdue, one with an
 * assignment sitting in the review queue.
 *
 * Courses come from the corpus, not from a list here. A demo that named
 * its own course slugs would break the moment a course was renamed, and
 * would keep generating a course that no longer ships.
 */
class KnowledgeGenerator implements DependentGeneratorInterface {

    /** How many staff to enrol. Enough for a spread, few enough to read. */
    private const MAX_LEARNERS = 8;

    /** @var array<string, array{body: string, feedback: string}> */
    private const COPY_BY_LANGUAGE = [
        'en_US' => [
            'body'     => 'Measured the squad over three blocks of ten minutes. The majority dropped off after 24 minutes, so we start at step 3.',
            'feedback' => 'Good measurement, clearly written up. Next time note who you took out of the exercise and why.',
        ],
        'nl_NL' => [
            'body'     => 'De selectie gemeten over drie blokken van tien minuten. De meerderheid zakte na 24 minuten weg, dus we starten op stap 3.',
            'feedback' => 'Goede meting, helder uitgewerkt. Noteer volgende keer wie je uit de oefening haalde en waarom.',
        ],
    ];

    private DemoBatchRegistry $registry;

    private int $weeks;

    private string $language;

    public static function category(): string {
        return 'knowledge';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->weeks(), $ctx->contentLanguage );
    }

    public function __construct( DemoBatchRegistry $registry, int $weeks, string $language = '' ) {
        $this->registry = $registry;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
    }

    public function generate(): int {
        global $wpdb;

        $courses = array_keys( CourseRegistry::all() );
        if ( ! $courses ) {
            return 0;
        }

        $staff = $this->staffPeople();
        if ( ! $staff ) {
            return 0;
        }

        $copy    = self::COPY_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $enrols  = new EnrolmentRepository();
        $total   = 0;

        foreach ( $staff as $index => $person_id ) {
            $course_slug = $courses[ $index % count( $courses ) ];
            $lessons     = array_keys( CourseRegistry::lessons( $course_slug ) );

            if ( ! $lessons ) {
                continue;
            }

            // The spread: every fourth learner is finished, every fourth
            // has not started, the rest are somewhere in between.
            $shape     = $index % 4;
            $reach     = $shape === 0 ? count( $lessons ) : ( $shape === 1 ? 0 : (int) ceil( count( $lessons ) * ( $shape === 2 ? 0.3 : 0.7 ) ) );
            $overdue   = $shape === 1 && $index > 0;
            $due       = gmdate( 'Y-m-d H:i:s', strtotime( $overdue ? '-1 week' : '+4 weeks' ) ?: time() );

            // #3102 — a learner already enrolled on this course is somebody
            // a previous run wrote. `enrol()` is idempotent and hands the
            // existing enrolment back rather than inserting, which sounds
            // like the right answer and is not: `writeProgress()` below would
            // then write this run's lesson rows against a populated
            // enrolment and collide with `uk_enrolment_lesson`. The learner
            // is already covered, so skip them.
            if ( $enrols->findFor( $person_id, $course_slug ) !== null ) {
                continue;
            }

            $enrolment_id = $enrols->enrol( $person_id, $course_slug, [
                'assigned_by' => 0,
                'due_at'      => $due,
            ] );

            if ( $enrolment_id <= 0 ) {
                continue;
            }

            $this->registry->tag( 'course_enrolment', $enrolment_id, [ 'person_id' => $person_id ] );
            $total++;

            if ( $reach > 0 ) {
                $wpdb->update(
                    "{$wpdb->prefix}tt_course_enrolments",
                    [
                        'status'     => $reach === count( $lessons )
                            ? EnrolmentRepository::STATUS_COMPLETED
                            : EnrolmentRepository::STATUS_IN_PROGRESS,
                        'started_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-' . $this->weeks . ' weeks' ) ?: time() ),
                        'completed_at' => $reach === count( $lessons )
                            ? gmdate( 'Y-m-d H:i:s', strtotime( '-1 week' ) ?: time() )
                            : null,
                    ],
                    [ 'id' => $enrolment_id ]
                );
            }

            $total += $this->writeProgress( $enrolment_id, array_slice( $lessons, 0, $reach ), $person_id );

            // One learner leaves an assignment in the review queue, so the
            // reviewer surface in #2648 has something to open on a fresh
            // demo install.
            if ( $shape === 2 && $reach > 0 ) {
                $total += $this->writeSubmission( $enrolment_id, $lessons[ $reach - 1 ], $copy, $person_id );
            }
        }

        return $total;
    }

    /**
     * Progress rows for the lessons this learner has reached, plus a quiz
     * attempt on each lesson that has a quiz.
     *
     * @param list<string> $lessons
     */
    private function writeProgress( int $enrolment_id, array $lessons, int $person_id ): int {
        global $wpdb;

        $written = 0;
        $course  = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT course_slug FROM {$wpdb->prefix}tt_course_enrolments WHERE id = %d",
            $enrolment_id
        ) );

        foreach ( $lessons as $offset => $lesson_slug ) {
            $lesson = CourseRegistry::lesson( $course, $lesson_slug );
            if ( $lesson === null ) {
                continue;
            }

            $read_at = gmdate(
                'Y-m-d H:i:s',
                strtotime( '-' . max( 1, $this->weeks - $offset ) . ' weeks' ) ?: time()
            );

            $wpdb->insert( "{$wpdb->prefix}tt_course_progress", [
                'club_id'                => CurrentClub::id(),
                'enrolment_id'           => $enrolment_id,
                'lesson_slug'            => $lesson_slug,
                'read_at'                => $read_at,
                'quiz_passed_at'         => $lesson->hasQuiz() ? $read_at : null,
                'assignment_approved_at' => $lesson->hasAssignment() ? $read_at : null,
                'tool_state'             => null,
            ] );

            $progress_id = (int) $wpdb->insert_id;
            if ( $progress_id ) {
                $this->registry->tag( 'course_progress', $progress_id, [ 'person_id' => $person_id ] );
                $written++;
            }

            if ( ! $lesson->hasQuiz() ) {
                continue;
            }

            // Some passed first time, some on the second attempt. The
            // attempt log is the reason the table exists; a demo where
            // everyone passed once would never show it doing anything.
            $attempts = ( $offset % 3 === 0 ) ? 2 : 1;
            for ( $n = 1; $n <= $attempts; $n++ ) {
                $passed = $n === $attempts;

                $wpdb->insert( "{$wpdb->prefix}tt_course_quiz_attempts", [
                    'club_id'      => CurrentClub::id(),
                    'enrolment_id' => $enrolment_id,
                    'lesson_slug'  => $lesson_slug,
                    'answers'      => wp_json_encode( [] ),
                    'score'        => $passed ? 5 : 2,
                    'max_score'    => 5,
                    'passed'       => $passed ? 1 : 0,
                    'created_at'   => $read_at,
                ] );

                $attempt_id = (int) $wpdb->insert_id;
                if ( $attempt_id ) {
                    $this->registry->tag( 'course_quiz_attempt', $attempt_id, [ 'person_id' => $person_id ] );
                    $written++;
                }
            }
        }

        return $written;
    }

    /**
     * One assignment awaiting review, and one already approved, so both
     * halves of the queue have something in them.
     *
     * @param array{body: string, feedback: string} $copy
     */
    private function writeSubmission( int $enrolment_id, string $lesson_slug, array $copy, int $person_id ): int {
        $repo = new SubmissionRepository();

        $id = $repo->submit( $enrolment_id, $lesson_slug, $lesson_slug, $copy['body'] );
        if ( $id <= 0 ) {
            return 0;
        }

        $this->registry->tag( 'course_submission', $id, [ 'person_id' => $person_id ] );

        return 1;
    }

    /**
     * Staff to enrol. The same source the staff-development generator
     * uses, so the two surfaces show the same people.
     *
     * @return int[]
     */
    private function staffPeople(): array {
        global $wpdb;

        // #3184 — club-wide on purpose. Courses are taken by the club's
        // staff, and the batch does not necessarily create any: `gen_people`
        // is an operator switch, and with it off the people already on the
        // install are the only learners there are. Scoping this to the batch
        // would leave the Knowledge module empty on exactly the run that
        // generates onto an existing academy.
        //
        // A second run re-enrolling the same staff is refused by
        // `uk_enrolment_lesson` rather than duplicated.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people
              WHERE club_id = %d AND archived_at IS NULL
              ORDER BY id LIMIT %d",
            CurrentClub::id(),
            self::MAX_LEARNERS
        ) );

        return array_map( 'intval', (array) $ids );
    }

    private static function resolveLanguage( string $locale ): string {
        if ( isset( self::COPY_BY_LANGUAGE[ $locale ] ) ) {
            return $locale;
        }

        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( self::COPY_BY_LANGUAGE ) as $key ) {
            if ( strpos( $key, $prefix ) === 0 ) {
                return $key;
            }
        }

        return 'en_US';
    }
}
