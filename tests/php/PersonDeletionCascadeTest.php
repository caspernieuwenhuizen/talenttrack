<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Database\Transaction;
use TT\Infrastructure\People\PersonDeletionCascade;
use TT\Infrastructure\Query\TableExists;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4589 — permanently deleting a person succeeds, and takes their staff
 * development record with them.
 *
 * The cascade deleted from `tt_staff_development`, which no migration
 * creates; the staff-development table is `tt_staff_pdp`. So every permanent
 * person delete failed on the missing table and rolled back.
 */
final class PersonDeletionCascadeTest extends WP_UnitTestCase {

    // The cascade commits, which ends the transaction the suite opened.
    use CommitsAfterCascade;

    /** The table a test pretends this install does not have. */
    private string $hidden = '';

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        TableExists::reset();
    }

    public function tear_down(): void {
        remove_filter( 'query', [ $this, 'hide' ] );
        TableExists::reset();
        $this->assertSame( 0, Transaction::depth() );
        $this->cleanUpAndCommit();
        parent::tear_down();
    }

    /** @param string $sql */
    public function hide( $sql ) {
        global $wpdb;
        $sql = (string) $sql;
        if ( $this->hidden !== '' && stripos( $sql, 'SHOW TABLES LIKE' ) !== false && stripos( $sql, "'" . $wpdb->prefix . $this->hidden . "'" ) !== false ) {
            return "SHOW TABLES LIKE '" . $wpdb->prefix . "tt_no_such_table'";
        }
        return $sql;
    }

    public function test_a_person_with_a_staff_development_record_is_deleted_with_it(): void {
        global $wpdb;
        $person = $this->person();
        $wpdb->insert( $wpdb->prefix . 'tt_staff_pdp', [
            'club_id'   => (int) CurrentClub::id(),
            'uuid'      => wp_generate_uuid4(),
            'person_id' => $person,
            'strengths' => 'Clear session plans.',
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'precondition: the staff PDP row exists' );

        $result = ( new PersonDeletionCascade() )->cascade( [ $person ] );

        $this->assertSame( 1, $result['deleted'] );
        $this->assertSame( 1, $result['per_table']['tt_staff_pdp'] ?? 0 );
        $this->assertSame( 0, $this->rows( 'tt_people', 'id', $person ) );
        $this->assertSame( 0, $this->rows( 'tt_staff_pdp', 'person_id', $person ) );
    }

    public function test_the_preview_counts_the_staff_development_record(): void {
        global $wpdb;
        $person = $this->person();
        $wpdb->insert( $wpdb->prefix . 'tt_staff_pdp', [
            'club_id'   => (int) CurrentClub::id(),
            'uuid'      => wp_generate_uuid4(),
            'person_id' => $person,
        ] );

        $preview  = ( new PersonDeletionCascade() )->preview( [ $person ] );
        $removals = $preview['persons'][0]['removals'] ?? [];

        $this->assertContains( [ 'kind' => 'staff_development', 'count' => 1 ], $removals );
    }

    public function test_a_table_the_install_does_not_have_is_skipped(): void {
        $person       = $this->person();
        $this->hidden = 'tt_staff_goals';
        add_filter( 'query', [ $this, 'hide' ] );

        $result = ( new PersonDeletionCascade() )->cascade( [ $person ] );

        $this->assertSame( 1, $result['deleted'], 'the delete goes through' );
        $this->assertArrayNotHasKey( 'tt_staff_goals', $result['per_table'], 'the missing table was not touched' );
        $this->assertSame( 0, $this->rows( 'tt_people', 'id', $person ) );
    }

    /** #4605 — the ratings of a deleted person's staff evaluations go too. */
    public function test_the_ratings_of_the_staff_evaluations_are_deleted(): void {
        $person     = $this->person();
        $evaluation = $this->staffEvaluation( $person );
        $this->staffRating( $evaluation );
        $this->staffRating( $evaluation );

        $colleague = $this->person();
        $kept      = $this->staffRating( $this->staffEvaluation( $colleague ) );

        $result = ( new PersonDeletionCascade() )->cascade( [ $person ] );

        $this->assertSame( 2, $result['per_table']['tt_staff_eval_ratings'] ?? 0 );
        $this->assertSame( 0, $this->rows( 'tt_staff_eval_ratings', 'evaluation_id', $evaluation ) );
        $this->assertSame( 0, $this->rows( 'tt_staff_evaluations', 'id', $evaluation ) );
        $this->assertSame( 1, $this->rows( 'tt_staff_eval_ratings', 'id', $kept ), "a colleague's ratings stay" );
    }

    /** #4605 — a row with the same person id under another club is not touched. */
    public function test_a_row_under_another_club_is_left_untouched(): void {
        global $wpdb;
        $person = $this->person();
        $club   = (int) CurrentClub::id();
        $other  = $club + 1;
        $ours   = $this->staffGoal( $person, $club );
        $theirs = $this->staffGoal( $person, $other );

        // One row per club-scoped statement, all for the same person id.
        $evaluation = $this->staffEvaluation( $person, $other );
        $rating     = $this->staffRating( $evaluation );
        $mentorship = $this->mentorship( $person, $other );
        $pending    = $this->invitation( $person, $other, 'pending' );
        $accepted   = $this->invitation( $person, $other, 'accepted' );
        $scope      = $this->scopeGrantedBy( $person, $other );

        $result = ( new PersonDeletionCascade() )->cascade( [ $person ] );

        $this->assertSame( 1, $result['deleted'] );
        $this->assertSame( 0, $this->rows( 'tt_staff_goals', 'id', $ours ) );
        $this->assertSame( 1, $this->rows( 'tt_staff_goals', 'id', $theirs ), "the other club's row stays" );
        $this->assertSame( $other, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT club_id FROM {$wpdb->prefix}tt_staff_goals WHERE id = %d",
            $theirs
        ) ) );

        $this->assertSame( 1, $this->rows( 'tt_staff_evaluations', 'id', $evaluation ), "the other club's staff evaluation stays" );
        $this->assertSame( 1, $this->rows( 'tt_staff_eval_ratings', 'id', $rating ), "the other club's rating stays" );
        $this->assertSame( 1, $this->rows( 'tt_staff_mentorships', 'id', $mentorship ), "the other club's mentorship stays" );
        $this->assertSame( 1, $this->rows( 'tt_invitations', 'id', $pending ), "the other club's pending invitation stays" );
        $this->assertSame( $person, $this->value( 'tt_invitations', 'target_person_id', $accepted ), "the other club's accepted invitation keeps its target" );
        $this->assertSame( $person, $this->value( 'tt_user_role_scopes', 'granted_by_person_id', $scope ), "the other club's grant keeps its grantor" );
    }

    /** #4605 — a person of another club is not reachable through this club's cascade. */
    public function test_a_person_of_another_club_is_not_deleted(): void {
        $club   = (int) CurrentClub::id();
        $person = $this->person( $club + 1 );
        $goal   = $this->staffGoal( $person, $club + 1 );

        $result = ( new PersonDeletionCascade() )->cascade( [ $person ] );

        $this->assertSame( 0, $result['deleted'] );
        $this->assertSame( [], $result['per_table'] );
        $this->assertSame( 1, $this->rows( 'tt_people', 'id', $person ) );
        $this->assertSame( 1, $this->rows( 'tt_staff_goals', 'id', $goal ) );
    }

    /** #4613 — the preview counts exactly what the delete removes. */
    public function test_the_preview_counts_what_the_delete_removes(): void {
        $person = $this->person();
        $club   = (int) CurrentClub::id();
        $other  = $club + 1;

        // This club: the rows the delete removes.
        $this->teamRole( $person, $club, 990001 );
        $this->staffGoal( $person, $club );
        $evaluation = $this->staffEvaluation( $person );
        $this->staffRating( $evaluation );
        $this->staffRating( $evaluation );
        $this->mentorship( $person, $club );
        $this->invitation( $person, $club, 'pending' );
        $this->invitation( $person, $club, 'accepted' );

        // Another club, same person id: the delete leaves these alone.
        $this->teamRole( $person, $other, 990002 );
        $this->staffGoal( $person, $other );
        $this->staffRating( $this->staffEvaluation( $person, $other ) );
        $this->mentorship( $person, $other );
        $this->invitation( $person, $other, 'pending' );
        $this->invitation( $person, $other, 'accepted' );

        $preview = ( new PersonDeletionCascade() )->preview( [ $person ] );
        $entry   = $preview['persons'][0] ?? [ 'removals' => [], 'nullifications' => [] ];

        $counted = [];
        foreach ( $entry['removals'] as $r ) {
            $kind = (string) $r['kind'];
            $counted[ $kind ] = ( $counted[ $kind ] ?? 0 ) + ( $kind === 'team_role' ? 1 : (int) $r['count'] );
        }
        $nulled_counted = [];
        foreach ( $entry['nullifications'] as $r ) {
            $nulled_counted[ (string) $r['kind'] ] = (int) $r['count'];
        }

        $result = ( new PersonDeletionCascade() )->cascade( [ $person ] );

        $this->assertSame( $result['per_table']['tt_team_people'], $counted['team_role'] ?? 0, 'team roles' );
        $this->assertSame( $result['per_table']['tt_staff_goals'], $counted['staff_goals'] ?? 0, 'staff goals' );
        $this->assertSame( $result['per_table']['tt_staff_evaluations'], $counted['staff_evaluations'] ?? 0, 'staff evaluations' );
        $this->assertSame( $result['per_table']['tt_staff_eval_ratings'], $counted['staff_eval_ratings'] ?? 0, 'staff evaluation ratings' );
        $this->assertSame( $result['per_table']['tt_staff_mentorships'], $counted['mentorship'] ?? 0, 'mentorships' );
        $this->assertSame( $result['per_table']['tt_invitations'], $counted['invitation_pending'] ?? 0, 'pending invitations' );
        $this->assertSame( $result['nulled']['tt_invitations.target_person_id'], $nulled_counted['invitation_accepted'] ?? 0, 'accepted invitations' );

        $this->assertSame( 2, $counted['staff_eval_ratings'] ?? 0, 'the ratings are counted' );
        $this->assertSame( 1, $counted['team_role'] ?? 0, "the other club's team role is not counted" );
        $this->assertSame( 1, $counted['staff_goals'] ?? 0, "the other club's goal is not counted" );
        $this->assertSame( 1, $counted['mentorship'] ?? 0, "the other club's mentorship is not counted" );
        $this->assertSame( 1, $counted['invitation_pending'] ?? 0, "the other club's pending invitation is not counted" );
        $this->assertSame( 1, $nulled_counted['invitation_accepted'] ?? 0, "the other club's accepted invitation is not counted" );
    }

    // Fixtures

    private function teamRole( int $person, int $club, int $team ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_team_people', [
            'club_id'      => $club,
            'team_id'      => $team,
            'person_id'    => $person,
            'role_in_team' => 'assistant_coach',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a team role' );
        return $id;
    }

    private function mentorship( int $person, int $club ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_staff_mentorships', [
            'club_id'          => $club,
            'mentor_person_id' => $person,
            'mentee_person_id' => $person + 1000,
            'started_on'       => '2026-09-01',
            'created_by'       => get_current_user_id(),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a mentorship' );
        return $id;
    }

    private function invitation( int $person, int $club, string $status ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_invitations', [
            'club_id'          => $club,
            'token'            => wp_generate_password( 40, false ),
            'kind'             => 'staff',
            'target_person_id' => $person,
            'created_by'       => get_current_user_id(),
            'expires_at'       => '2026-12-31 00:00:00',
            'status'           => $status,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write an invitation' );
        return $id;
    }

    private function scopeGrantedBy( int $person, int $club ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'club_id'              => $club,
            'person_id'            => $person + 1000,
            'role_id'              => 1,
            'scope_type'           => 'global',
            'granted_by_person_id' => $person,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a role scope' );
        return $id;
    }

    private function value( string $table, string $column, int $id ): int {
        global $wpdb;
        // Table and column are literals from this test, never caller input.
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT {$column} FROM {$wpdb->prefix}{$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $id
        ) );
    }

    private function staffEvaluation( int $person, ?int $club = null ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_staff_evaluations', [
            'club_id'          => $club ?? (int) CurrentClub::id(),
            'person_id'        => $person,
            'reviewer_user_id' => get_current_user_id(),
            'review_kind'      => 'top_down',
            'eval_date'        => '2026-10-01',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a staff evaluation' );
        return $id;
    }

    private function staffRating( int $evaluation ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_staff_eval_ratings', [
            'evaluation_id' => $evaluation,
            'category_id'   => 1,
            'rating'        => 3.5,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a staff rating' );
        return $id;
    }

    private function staffGoal( int $person, int $club ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_staff_goals', [
            'club_id'    => $club,
            'person_id'  => $person,
            'title'      => 'Run a pressing session',
            'created_by' => get_current_user_id(),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a staff goal' );
        return $id;
    }

    private function person( ?int $club = null ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => $club ?? (int) CurrentClub::id(),
            'first_name' => 'Staff',
            'last_name'  => 'Leaver',
            'role_type'  => 'coach',
            'status'     => 'active',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a person' );
        return $id;
    }

    private function rows( string $table, string $column, int $id ): int {
        global $wpdb;
        // Table and column are literals from this test, never caller input.
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $id
        ) );
    }

    /** #4618 — no course row belongs to or points at a deleted person. */
    public function test_the_course_records_of_the_person_are_deleted(): void {
        $club      = (int) CurrentClub::id();
        $person    = $this->person();
        $enrolment = $this->enrolment( $person, $club );
        $progress  = $this->courseChild( 'tt_course_progress', $enrolment, $club );
        $attempt   = $this->courseChild( 'tt_course_quiz_attempts', $enrolment, $club );
        $own_sub   = $this->submission( $enrolment, $club, null );

        // A colleague's submission the person reviewed.
        $colleague = $this->person();
        $reviewed  = $this->submission( $this->enrolment( $colleague, $club ), $club, $person );

        // The same person id under another club.
        $theirs       = $this->enrolment( $person, $club + 1 );
        $theirs_child = $this->courseChild( 'tt_course_progress', $theirs, $club + 1 );
        $theirs_rev   = $this->submission( $this->enrolment( $colleague, $club + 1 ), $club + 1, $person );

        $result = ( new PersonDeletionCascade() )->cascade( [ $person ] );

        $this->assertSame( 1, $result['deleted'] );
        $this->assertSame( 1, $result['per_table']['tt_course_enrolments'] ?? 0 );
        $this->assertSame( 1, $result['per_table']['tt_course_progress'] ?? 0 );
        $this->assertSame( 1, $result['per_table']['tt_course_quiz_attempts'] ?? 0 );
        $this->assertSame( 1, $result['per_table']['tt_course_submissions'] ?? 0 );
        $this->assertSame( 1, $result['nulled']['tt_course_submissions.reviewer_person_id'] ?? 0 );

        $this->assertSame( 0, $this->rows( 'tt_course_enrolments', 'id', $enrolment ) );
        $this->assertSame( 0, $this->rows( 'tt_course_progress', 'id', $progress ) );
        $this->assertSame( 0, $this->rows( 'tt_course_quiz_attempts', 'id', $attempt ) );
        $this->assertSame( 0, $this->rows( 'tt_course_submissions', 'id', $own_sub ) );
        $this->assertSame( 0, $this->clubRows( 'tt_course_enrolments', 'person_id', $person, $club ), 'no enrolment belongs to the person' );
        $this->assertSame( 0, $this->clubRows( 'tt_course_submissions', 'reviewer_person_id', $person, $club ), 'no submission points at the person' );

        $this->assertSame( 1, $this->rows( 'tt_course_submissions', 'id', $reviewed ), "the colleague's submission survives" );
        $this->assertNull( $this->nullable( 'tt_course_submissions', 'reviewer_person_id', $reviewed ), 'with the reviewer cleared' );

        $this->assertSame( 1, $this->rows( 'tt_course_enrolments', 'id', $theirs ), "the other club's enrolment stays" );
        $this->assertSame( 1, $this->rows( 'tt_course_progress', 'id', $theirs_child ), "the other club's progress stays" );
        $this->assertSame( $person, $this->value( 'tt_course_submissions', 'reviewer_person_id', $theirs_rev ), "the other club's review keeps its reviewer" );
    }

    /** #4618 — the preview counts the course rows the delete removes and clears. */
    public function test_the_preview_counts_the_course_records(): void {
        $club      = (int) CurrentClub::id();
        $person    = $this->person();
        $enrolment = $this->enrolment( $person, $club );
        $this->courseChild( 'tt_course_progress', $enrolment, $club );
        $this->courseChild( 'tt_course_progress', $enrolment, $club, 'lesson-2' );
        $this->courseChild( 'tt_course_quiz_attempts', $enrolment, $club );
        $this->submission( $enrolment, $club, $person ); // their own, reviewed by themselves: removed, not cleared
        $this->submission( $this->enrolment( $this->person(), $club ), $club, $person );

        $preview = ( new PersonDeletionCascade() )->preview( [ $person ] );
        $entry   = $preview['persons'][0] ?? [ 'removals' => [], 'nullifications' => [] ];
        $removed = [];
        foreach ( $entry['removals'] as $r ) $removed[ (string) $r['kind'] ] = (int) ( $r['count'] ?? 0 );
        $cleared = [];
        foreach ( $entry['nullifications'] as $r ) $cleared[ (string) $r['kind'] ] = (int) $r['count'];

        $result = ( new PersonDeletionCascade() )->cascade( [ $person ] );

        $this->assertSame( $result['per_table']['tt_course_enrolments'], $removed['course_enrolments'] ?? 0 );
        $this->assertSame( $result['per_table']['tt_course_progress'], $removed['course_progress'] ?? 0 );
        $this->assertSame( $result['per_table']['tt_course_quiz_attempts'], $removed['course_quiz_attempts'] ?? 0 );
        $this->assertSame( $result['per_table']['tt_course_submissions'], $removed['course_submissions'] ?? 0 );
        $this->assertSame( $result['nulled']['tt_course_submissions.reviewer_person_id'], $cleared['course_reviewer'] ?? 0 );
        $this->assertSame( 2, $removed['course_progress'] ?? 0 );
        $this->assertSame( 1, $cleared['course_reviewer'] ?? 0 );
    }

    private function enrolment( int $person, int $club ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_course_enrolments', [
            'uuid'        => wp_generate_uuid4(),
            'club_id'     => $club,
            'course_slug' => 'coaching-basics',
            'person_id'   => $person,
            'status'      => 'in_progress',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a course enrolment' );
        return $id;
    }

    private function courseChild( string $table, int $enrolment, int $club, string $lesson = 'lesson-1' ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . $table, [
            'club_id'      => $club,
            'enrolment_id' => $enrolment,
            'lesson_slug'  => $lesson,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a ' . $table . ' row' );
        return $id;
    }

    private function submission( int $enrolment, int $club, ?int $reviewer ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_course_submissions', [
            'uuid'               => wp_generate_uuid4(),
            'club_id'            => $club,
            'enrolment_id'       => $enrolment,
            'lesson_slug'        => 'lesson-1',
            'reviewer_person_id' => $reviewer,
            'outcome'            => $reviewer !== null ? 'approved' : '',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a course submission' );
        return $id;
    }

    private function clubRows( string $table, string $column, int $id, int $club ): int {
        global $wpdb;
        // Table and column are literals from this test, never caller input.
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} = %d AND club_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $id, $club
        ) );
    }

    private function nullable( string $table, string $column, int $id ): ?string {
        global $wpdb;
        // Table and column are literals from this test, never caller input.
        $v = $wpdb->get_var( $wpdb->prepare(
            "SELECT {$column} FROM {$wpdb->prefix}{$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $id
        ) );
        return $v === null ? null : (string) $v;
    }
}
