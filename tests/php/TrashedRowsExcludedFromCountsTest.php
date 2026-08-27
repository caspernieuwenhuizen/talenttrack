<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Evaluations\EvaluationsRepository;

/**
 * #2906 — aggregates written before the recycle bin counted rows every list
 * hides.
 *
 * The failure is one-directional and silent: the number is always too
 * generous, and the screen a coach opens to explain it shows nothing. #2865
 * fixed one instance (a team profile printing a squad rating for a team whose
 * evaluations list was empty); this covers the class.
 *
 * `trashed-but-not-archived` is the state that leaks. An archived row was
 * already excluded by the old `archived_at IS NULL` guard, so a test that only
 * archives passes against the bug.
 */
final class TrashedRowsExcludedFromCountsTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function test_player_file_badge_counts_exclude_trashed_goals(): void {
        global $wpdb;
        $team_id   = $this->insertTeam( 'U15 bin-goals' );
        $player_id = $this->insertPlayer( $team_id, 'Bin', 'Goals' );

        $this->insertGoal( $player_id, 'live one' );
        $this->insertGoal( $player_id, 'live two' );
        $trashed = $this->insertGoal( $player_id, 'binned' );

        $wpdb->update( "{$this->p}tt_goals", [ 'trashed_at' => '2020-05-01 09:00:00' ], [ 'id' => $trashed ] );

        $counts = PlayerFileCounts::for( $player_id );

        $this->assertSame( 2, (int) $counts['goals'],
            'the badge must not count a goal sitting in the recycle bin' );
    }

    public function test_player_file_badge_counts_exclude_trashed_evaluations(): void {
        global $wpdb;
        $team_id   = $this->insertTeam( 'U15 bin-evals' );
        $player_id = $this->insertPlayer( $team_id, 'Bin', 'Evals' );

        $this->insertEvaluation( $player_id, '2020-04-01' );
        $trashed = $this->insertEvaluation( $player_id, '2020-04-08' );

        $wpdb->update( "{$this->p}tt_evaluations", [ 'trashed_at' => '2020-04-09 09:00:00' ], [ 'id' => $trashed ] );

        $counts = PlayerFileCounts::for( $player_id );

        $this->assertSame( 1, (int) $counts['evaluations'],
            'the badge must not count an evaluation sitting in the recycle bin' );
    }

    /**
     * The badge and the list it opens must agree. A badge reading 2 over a tab
     * that renders 1 row is the symptom an operator actually reports.
     */
    public function test_goal_badge_agrees_with_the_list_the_tab_opens(): void {
        global $wpdb;
        $team_id   = $this->insertTeam( 'U16 bin-agree' );
        $player_id = $this->insertPlayer( $team_id, 'Agr', 'Ees' );

        $this->insertGoal( $player_id, 'live' );
        $trashed  = $this->insertGoal( $player_id, 'binned' );
        $archived = $this->insertGoal( $player_id, 'archived' );

        $wpdb->update( "{$this->p}tt_goals", [ 'trashed_at'  => '2020-06-01 09:00:00' ], [ 'id' => $trashed ] );
        $wpdb->update( "{$this->p}tt_goals", [ 'archived_at' => '2020-06-01 09:00:00' ], [ 'id' => $archived ] );

        $counts = PlayerFileCounts::for( $player_id );
        $listed = ( new GoalsRepository() )->listForPlayer( $player_id );

        $this->assertSame( count( $listed ), (int) $counts['goals'],
            'badge and list must resolve the same scope' );
        $this->assertSame( 1, (int) $counts['goals'],
            'only the live goal counts; archived and trashed are both hidden' );
    }

    /**
     * `countActiveForPlayer` feeds the archive-confirm cascade preview and
     * several dashboards. It is a visible-row count, so it filters.
     */
    public function test_active_goal_count_excludes_trashed(): void {
        global $wpdb;
        $team_id   = $this->insertTeam( 'U14 bin-count' );
        $player_id = $this->insertPlayer( $team_id, 'Cou', 'Nter' );

        $this->insertGoal( $player_id, 'live' );
        $trashed = $this->insertGoal( $player_id, 'binned' );
        $wpdb->update( "{$this->p}tt_goals", [ 'trashed_at' => '2020-07-01 09:00:00' ], [ 'id' => $trashed ] );

        $this->assertSame( 1, ( new GoalsRepository() )->countActiveForPlayer( $player_id ) );
    }

    /**
     * The evaluations repository backs the player file's evaluation tab and
     * several rating aggregates. A trashed evaluation must not reach either.
     */
    public function test_evaluation_list_for_player_excludes_trashed(): void {
        global $wpdb;
        $team_id   = $this->insertTeam( 'U18 bin-evallist' );
        $player_id = $this->insertPlayer( $team_id, 'Eva', 'List' );

        $this->insertEvaluation( $player_id, '2020-08-01' );
        $trashed = $this->insertEvaluation( $player_id, '2020-08-08' );
        $wpdb->update( "{$this->p}tt_evaluations", [ 'trashed_at' => '2020-08-09 09:00:00' ], [ 'id' => $trashed ] );

        $rows = ( new EvaluationsRepository() )->listRecentForPlayer( $player_id );

        $this->assertCount( 1, $rows,
            'a trashed evaluation must not appear in the player evaluation list' );
        $this->assertSame( 1, ( new EvaluationsRepository() )->countForPlayer( $player_id ),
            'and the count backing the tab badge must agree with that list' );
    }

    /* ---- helpers -------------------------------------------------------- */

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first,
            'last_name'  => $last,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertGoal( int $player_id, string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'title'      => $title,
            'created_by' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertEvaluation( int $player_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'   => $this->club,
            'player_id' => $player_id,
            'coach_id'  => 1,
            'eval_date' => $date,
        ] );
        return (int) $wpdb->insert_id;
    }
}
