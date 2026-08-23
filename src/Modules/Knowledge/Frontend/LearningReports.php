<?php
namespace TT\Modules\Knowledge\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\LearningStatisticsService;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;

/**
 * LearningReports (#2650, epic #2641) — the three tables behind the learning
 * reports.
 *
 * The page chrome — breadcrumbs, header, Explore/Export actions, the
 * `.tt-rep-*` stylesheet — belongs to `FrontendStandardReportsView`, which
 * calls in here for the body. Keeping the markup in the module rather than
 * adding four hundred lines to a shared 1,500-line file, while still using
 * that file's chrome, is the compromise: consistent surface, module-owned
 * content.
 *
 * Aggregation is `LearningStatisticsService`'s. Nothing here counts anything
 * (CLAUDE.md §4); it decides what a number *looks* like, which is a different
 * job and the only one a view should have.
 *
 * ## Status is a chip, not a colour
 *
 * Overdue and completed carry a word and a shape as well as a hue. A reader
 * who cannot separate the red from the green still reads the table, and a
 * percentage alone never says "this one needs chasing" at a glance — which is
 * the whole reason a head of development opens the page.
 */
final class LearningReports {

    /** Own record only. Everything wider needs the statistics capability. */
    public const CAP_OWN = 'tt_view_knowledge';

    public const CAP_ALL = 'tt_view_knowledge_statistics';

    /** Whether this user may see anyone's record but their own. */
    public static function canSeeEveryone( int $user_id ): bool {
        return user_can( $user_id, self::CAP_ALL );
    }

    /* ===== per course ===== */

    public static function renderCourseOverview( int $user_id ): void {
        if ( ! self::canSeeEveryone( $user_id ) ) {
            self::renderOwnRecordOnly( $user_id );
            return;
        }

        $stats = ( new LearningStatisticsService() )->forAllCourses();

        if ( $stats === [] ) {
            self::renderEmpty( __( 'This install has no courses.', 'talenttrack' ) );
            return;
        }

        echo '<div class="tt-report-card"><div class="tt-table-wrap">';
        echo '<table class="tt-table tt-learning-report">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Course', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="num">' . esc_html__( 'Enrolled', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="num">' . esc_html__( 'Not started', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="num">' . esc_html__( 'In progress', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="num">' . esc_html__( 'Completed', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="num">' . esc_html__( 'Overdue', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="num">' . esc_html__( 'Median days', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Stalls at', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        $service = new LearningStatisticsService();

        foreach ( $stats as $row ) {
            $drop = $service->dropOffFor( (string) $row['course_slug'] );

            echo '<tr>';
            echo '<th scope="row">' . esc_html( (string) $row['title'] ) . '</th>';
            echo '<td class="num">' . (int) $row['enrolled'] . '</td>';
            echo '<td class="num">' . (int) $row['not_started'] . '</td>';
            echo '<td class="num">' . (int) $row['in_progress'] . '</td>';
            echo '<td class="num">' . (int) $row['completed'] . '</td>';
            echo '<td class="num">' . self::overdueCell( (int) $row['overdue'] ) . '</td>';
            // An em dash, not a zero: nobody having finished yet is a
            // different statement from finishing in no time.
            echo '<td class="num">'
                . esc_html( $row['median_days_to_complete'] === null ? '—' : (string) $row['median_days_to_complete'] )
                . '</td>';
            echo '<td>' . esc_html(
                $drop['stalls_at'] === null
                    ? __( 'No drop-off yet', 'talenttrack' )
                    : sprintf(
                        /* translators: 1: lesson title, 2: how many readers stopped there */
                        __( '%1$s (−%2$d)', 'talenttrack' ),
                        $drop['stalls_at']['title'],
                        $drop['stalls_at']['drop']
                    )
            ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';

        echo '<p class="tt-rep-note">'
            . esc_html__( '"Stalls at" is the lesson the most readers stop before finishing. A high number usually says something about the lesson, not about the coaches.', 'talenttrack' )
            . '</p>';
    }

    /* ===== per person ===== */

    public static function renderPeople( int $user_id ): void {
        if ( ! self::canSeeEveryone( $user_id ) ) {
            self::renderOwnRecordOnly( $user_id );
            return;
        }

        $people = ( new LearningStatisticsService() )->forEveryone();

        if ( $people === [] ) {
            self::renderEmpty( __( 'Nobody is on a course yet.', 'talenttrack' ) );
            return;
        }

        echo '<div class="tt-report-card"><div class="tt-table-wrap">';
        echo '<table class="tt-table tt-learning-report">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Person', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="num">' . esc_html__( 'Courses', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="num">' . esc_html__( 'Complete', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="num">' . esc_html__( 'Overdue', 'talenttrack' ) . '</th>';
        echo '<th scope="col" class="num">' . esc_html__( 'Awaiting review', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Last activity', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $people as $person ) {
            echo '<tr>';
            echo '<th scope="row">' . esc_html( (string) $person['name'] ) . '</th>';
            echo '<td class="num">' . (int) $person['assigned'] . '</td>';
            echo '<td class="num">' . esc_html( sprintf( '%d%%', (int) $person['percent'] ) ) . '</td>';
            echo '<td class="num">' . self::overdueCell( (int) $person['overdue'] ) . '</td>';
            echo '<td class="num">' . (int) $person['awaiting_review'] . '</td>';
            echo '<td>' . esc_html( self::when( $person['last_activity'] ) ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';
    }

    /* ===== per team ===== */

    public static function renderTeams( int $user_id ): void {
        if ( ! self::canSeeEveryone( $user_id ) ) {
            self::renderOwnRecordOnly( $user_id );
            return;
        }

        $courses = CourseRegistry::all();
        if ( $courses === [] ) {
            self::renderEmpty( __( 'This install has no courses.', 'talenttrack' ) );
            return;
        }

        $service = new LearningStatisticsService();
        $any     = false;

        foreach ( $courses as $slug => $manifest ) {
            $teams = $service->forTeams( (string) $slug );
            if ( $teams === [] ) continue;

            $any = true;

            echo '<h2 class="tt-rep-subhead">' . esc_html( $manifest->title() ) . '</h2>';
            echo '<div class="tt-report-card"><div class="tt-table-wrap">';
            echo '<table class="tt-table tt-learning-report">';
            echo '<thead><tr>';
            echo '<th scope="col">' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
            echo '<th scope="col" class="num">' . esc_html__( 'Staff trained', 'talenttrack' ) . '</th>';
            echo '<th scope="col">' . esc_html__( 'Coverage', 'talenttrack' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $teams as $team ) {
                $done  = (int) $team['done'];
                $total = (int) $team['total'];

                echo '<tr>';
                echo '<th scope="row">' . esc_html( (string) $team['team_name'] ) . '</th>';
                echo '<td class="num">' . esc_html( sprintf(
                    /* translators: 1: how many staff finished, 2: how many staff there are */
                    __( '%1$d of %2$d', 'talenttrack' ),
                    $done,
                    $total
                ) ) . '</td>';
                echo '<td>' . self::coverageCell( $done, $total ) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div></div>';
        }

        if ( ! $any ) {
            self::renderEmpty( __( 'No team has staff assigned to it yet, so there is nothing to measure coverage against.', 'talenttrack' ) );
        }
    }

    /* ===== shared bits ===== */

    /**
     * A coach's own record, for anyone without the statistics capability.
     *
     * Rendered rather than refused. A coach reaching a learning report should
     * see their own progress — hiding the page entirely would make the
     * capability feel like a punishment, and the spec is explicit that own-
     * record access is a level, not an absence of one.
     */
    private static function renderOwnRecordOnly( int $user_id ): void {
        $person_id = KnowledgePerson::forUser( $user_id );

        if ( $person_id <= 0 ) {
            self::renderEmpty( __( 'Your login is not linked to a staff record, so there is no learning history to show.', 'talenttrack' ) );
            return;
        }

        $stats = ( new LearningStatisticsService() )->forPerson( $person_id );

        echo '<p class="tt-rep-note">'
            . esc_html__( 'Showing your own record. Seeing everyone\'s needs the learning-statistics permission.', 'talenttrack' )
            . '</p>';

        echo '<div class="tt-report-card"><div class="tt-table-wrap">';
        echo '<table class="tt-table tt-learning-report">';
        echo '<tbody>';
        self::ownRow( __( 'Courses', 'talenttrack' ), (string) $stats['assigned'] );
        self::ownRow( __( 'Complete', 'talenttrack' ), sprintf( '%d%%', (int) $stats['percent'] ) );
        self::ownRow( __( 'Overdue', 'talenttrack' ), (string) $stats['overdue'] );
        self::ownRow( __( 'Awaiting review', 'talenttrack' ), (string) $stats['awaiting_review'] );
        self::ownRow( __( 'Last activity', 'talenttrack' ), self::when( $stats['last_activity'] ) );
        echo '</tbody></table></div></div>';

        $url = KnowledgeLinks::myLearning();
        echo '<p><a class="tt-btn tt-btn-secondary" href="' . esc_url( $url ) . '">'
            . esc_html__( 'Open my learning', 'talenttrack' ) . '</a></p>';
    }

    private static function ownRow( string $label, string $value ): void {
        echo '<tr><th scope="row">' . esc_html( $label ) . '</th>'
            . '<td class="num">' . esc_html( $value ) . '</td></tr>';
    }

    /**
     * Overdue as a chip when there is one, a plain zero when there is not.
     *
     * A zero chip would make every clean row shout as loudly as the one that
     * needs attention, which is the opposite of what a status colour is for.
     */
    private static function overdueCell( int $count ): string {
        if ( $count === 0 ) {
            return '<span class="tt-learning-report__zero">0</span>';
        }

        return '<span class="tt-learning-chip tt-learning-chip--overdue">'
            . esc_html( sprintf(
                /* translators: %d: how many items are overdue */
                _n( '%d overdue', '%d overdue', $count, 'talenttrack' ),
                $count
            ) )
            . '</span>';
    }

    /** Coverage as a chip carrying the word as well as the colour. */
    private static function coverageCell( int $done, int $total ): string {
        if ( $total === 0 ) {
            return '<span class="tt-learning-report__zero">—</span>';
        }

        if ( $done === $total ) {
            return '<span class="tt-learning-chip tt-learning-chip--complete">'
                . esc_html__( 'All trained', 'talenttrack' ) . '</span>';
        }

        if ( $done === 0 ) {
            return '<span class="tt-learning-chip tt-learning-chip--none">'
                . esc_html__( 'None trained', 'talenttrack' ) . '</span>';
        }

        return '<span class="tt-learning-chip tt-learning-chip--partial">'
            . esc_html( sprintf(
                /* translators: %d: how many staff still to finish */
                _n( '%d to go', '%d to go', $total - $done, 'talenttrack' ),
                $total - $done
            ) )
            . '</span>';
    }

    private static function when( ?string $timestamp ): string {
        if ( $timestamp === null || $timestamp === '' ) {
            return __( 'Never', 'talenttrack' );
        }

        $ts = strtotime( $timestamp );

        return $ts === false ? __( 'Never', 'talenttrack' ) : date_i18n( get_option( 'date_format' ), $ts );
    }

    private static function renderEmpty( string $message ): void {
        echo '<p class="tt-notice">' . esc_html( $message ) . '</p>';
    }

    /**
     * Humanised rows for the CSV export (#2012).
     *
     * Status columns are for people. An export that ships `not_started` where
     * the screen said "Not started" makes the reader translate the enum back,
     * which is exactly the gap #2012 closed elsewhere.
     *
     * @return array{0: list<string>, 1: list<list<string>>} header, rows
     */
    public static function exportCourseRows(): array {
        $service = new LearningStatisticsService();

        $header = [
            __( 'Course', 'talenttrack' ),
            __( 'Enrolled', 'talenttrack' ),
            __( 'Not started', 'talenttrack' ),
            __( 'In progress', 'talenttrack' ),
            __( 'Completed', 'talenttrack' ),
            __( 'Overdue', 'talenttrack' ),
            __( 'Median days', 'talenttrack' ),
            __( 'Stalls at', 'talenttrack' ),
        ];

        $rows = [];
        foreach ( $service->forAllCourses() as $row ) {
            $drop = $service->dropOffFor( (string) $row['course_slug'] );

            $rows[] = [
                (string) $row['title'],
                (string) $row['enrolled'],
                (string) $row['not_started'],
                (string) $row['in_progress'],
                (string) $row['completed'],
                (string) $row['overdue'],
                $row['median_days_to_complete'] === null
                    ? __( 'Nobody has finished yet', 'talenttrack' )
                    : (string) $row['median_days_to_complete'],
                $drop['stalls_at'] === null
                    ? __( 'No drop-off yet', 'talenttrack' )
                    : (string) $drop['stalls_at']['title'],
            ];
        }

        return [ $header, $rows ];
    }

    /** The vocabulary the export humanises, shared with any other consumer. */
    public static function statusLabel( string $status ): string {
        switch ( $status ) {
            case EnrolmentRepository::STATUS_COMPLETED:
                return __( 'Completed', 'talenttrack' );
            case EnrolmentRepository::STATUS_IN_PROGRESS:
                return __( 'In progress', 'talenttrack' );
            default:
                return __( 'Not started', 'talenttrack' );
        }
    }
}
