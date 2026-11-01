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
        $ours   = $this->staffGoal( $person, $club );
        $theirs = $this->staffGoal( $person, $club + 1 );

        $result = ( new PersonDeletionCascade() )->cascade( [ $person ] );

        $this->assertSame( 1, $result['deleted'] );
        $this->assertSame( 0, $this->rows( 'tt_staff_goals', 'id', $ours ) );
        $this->assertSame( 1, $this->rows( 'tt_staff_goals', 'id', $theirs ), "the other club's row stays" );
        $this->assertSame( $club + 1, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT club_id FROM {$wpdb->prefix}tt_staff_goals WHERE id = %d",
            $theirs
        ) ) );
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

    // Fixtures

    private function staffEvaluation( int $person ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_staff_evaluations', [
            'club_id'          => (int) CurrentClub::id(),
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
}
