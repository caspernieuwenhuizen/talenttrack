<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Teams\TeamKpisRepository;

/**
 * #2865 — a trashed evaluation must not feed the team's squad-rating KPI.
 *
 * The KPI hand-rolled `e.archived_at IS NULL`, written before the recycle
 * bin (#2018) existed. The evaluations list filters through
 * `ArchiveRepository::filterClause`, which hides trashed rows in *every*
 * view — archived included. So a trashed evaluation was invisible on every
 * screen a coach could open and still counted toward the number on the team
 * profile: a pilot team read "Selectiebeoordeling 8,3" beside a list that
 * was empty in all three states.
 *
 * Trashed-but-not-archived is the state that leaked, so it is the state
 * these tests centre on.
 */
final class TeamSquadRatingTrashedTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function test_a_trashed_evaluation_does_not_feed_the_kpi(): void {
        $team_id   = $this->insertTeam( 'JO13-1 leak' );
        $player_id = $this->insertPlayer( $team_id );

        $live    = $this->insertEvaluation( $player_id );
        $trashed = $this->insertEvaluation( $player_id );
        $this->setEvaluation( $trashed, [ 'trashed_at' => '2026-08-01 00:00:00' ] );

        $this->insertRating( $live, 6.0 );
        $this->insertRating( $trashed, 9.0 );

        $avg = ( new TeamKpisRepository() )->avgSquadRating( $team_id );

        $this->assertNotNull( $avg );
        $this->assertEqualsWithDelta(
            6.0,
            $avg,
            0.001,
            'the trashed evaluation must not pull the squad rating up'
        );
    }

    /**
     * The exact reported shape: every evaluation trashed, list empty in all
     * three states, and the profile still printing a number.
     */
    public function test_a_team_whose_evaluations_are_all_trashed_has_no_rating(): void {
        $team_id   = $this->insertTeam( 'JO13-1 all trashed' );
        $player_id = $this->insertPlayer( $team_id );

        foreach ( [ 8.0, 9.0 ] as $rating ) {
            $eval = $this->insertEvaluation( $player_id );
            $this->insertRating( $eval, $rating );
            $this->setEvaluation( $eval, [ 'trashed_at' => '2026-08-01 00:00:00' ] );
        }

        $this->assertNull(
            ( new TeamKpisRepository() )->avgSquadRating( $team_id ),
            'no live evaluation means no number, not a number nobody can explain'
        );
    }

    /** Restoring from the recycle bin brings the KPI back. */
    public function test_restoring_a_trashed_evaluation_restores_the_kpi(): void {
        $team_id   = $this->insertTeam( 'JO13-1 restore' );
        $player_id = $this->insertPlayer( $team_id );

        $eval = $this->insertEvaluation( $player_id );
        $this->insertRating( $eval, 7.0 );
        $this->setEvaluation( $eval, [ 'trashed_at' => '2026-08-01 00:00:00' ] );

        $repo = new TeamKpisRepository();
        $this->assertNull( $repo->avgSquadRating( $team_id ) );

        $this->setEvaluation( $eval, [ 'trashed_at' => null ] );
        $this->assertEqualsWithDelta( 7.0, (float) $repo->avgSquadRating( $team_id ), 0.001 );
    }

    /** Archived-but-not-trashed was already excluded; keep it that way. */
    public function test_an_archived_evaluation_still_does_not_feed_the_kpi(): void {
        $team_id   = $this->insertTeam( 'JO13-1 archived' );
        $player_id = $this->insertPlayer( $team_id );

        $eval = $this->insertEvaluation( $player_id );
        $this->insertRating( $eval, 9.0 );
        $this->setEvaluation( $eval, [ 'archived_at' => '2026-08-01 00:00:00' ] );

        $this->assertNull( ( new TeamKpisRepository() )->avgSquadRating( $team_id ) );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Squad',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertEvaluation( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'   => $this->club,
            'player_id' => $player_id,
            'eval_date' => '2026-07-01',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertRating( int $evaluation_id, float $rating ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_eval_ratings", [
            'evaluation_id' => $evaluation_id,
            'rating'        => $rating,
        ] );
    }

    /** @param array<string,string|null> $data */
    private function setEvaluation( int $id, array $data ): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_evaluations", $data, [ 'id' => $id ] );
    }
}
