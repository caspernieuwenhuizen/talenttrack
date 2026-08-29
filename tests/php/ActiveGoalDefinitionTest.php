<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3033 — "active goal" had three definitions behind one tab, so the badge,
 * the list heading and the profile KPI could each show a different number for
 * the same player. The equality below is the bug; asserting it is what stops
 * the three drifting apart again.
 */
final class ActiveGoalDefinitionTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function test_badge_heading_and_kpi_agree_on_one_player(): void {
        $player_id = $this->player( 'Agr', 'Eement' );

        $this->goal( $player_id, 'still working', 'in_progress' );
        $this->goal( $player_id, 'no status yet', null );
        $this->goal( $player_id, 'done', 'completed' );
        $this->goal( $player_id, 'dropped', 'cancelled' );

        $repo = new GoalsRepository();

        $this->assertSame( 2, $repo->countActiveForPlayer( $player_id ),
            'a completed and a cancelled goal are not active' );
        $this->assertCount( 2, $repo->listActiveByDueDateForPlayer( $player_id ),
            'the list the tab renders must hold exactly the active goals' );
        $this->assertSame( 2, (int) PlayerFileCounts::for( $player_id )['goals'],
            'the tab badge must count the same set the list renders' );
    }

    public function test_active_and_closed_lists_partition_the_players_goals(): void {
        $player_id = $this->player( 'Par', 'Tition' );

        $this->goal( $player_id, 'open', 'in_progress' );
        $this->goal( $player_id, 'pending', null );
        $this->goal( $player_id, 'achieved', 'completed' );
        $this->goal( $player_id, 'abandoned', 'cancelled' );

        $repo = new GoalsRepository();

        $active = $repo->listActiveByDueDateForPlayer( $player_id );
        $closed = $repo->listClosedForPlayer( $player_id );

        $this->assertCount( 2, $active );
        $this->assertCount( 2, $closed );
        $this->assertSame( $repo->countClosedForPlayer( $player_id ), count( $closed ) );

        $ids = array_merge(
            array_map( static fn( $g ): int => (int) $g->id, $active ),
            array_map( static fn( $g ): int => (int) $g->id, $closed )
        );
        $this->assertSame( count( $ids ), count( array_unique( $ids ) ),
            'no goal may appear in both lists' );
        $this->assertSame( 4, count( $ids ),
            'and no goal may fall into neither' );
    }

    /**
     * The badge query had no club filter at all, which is a tenancy leak the
     * moment there is a second club (CLAUDE.md §4).
     */
    public function test_badge_ignores_goals_belonging_to_another_club(): void {
        global $wpdb;
        $player_id = $this->player( 'Ten', 'Ancy' );

        $this->goal( $player_id, 'ours', 'in_progress' );
        $theirs = $this->goal( $player_id, 'other club', 'in_progress' );
        $wpdb->update( "{$this->p}tt_goals", [ 'club_id' => $this->club + 7 ], [ 'id' => $theirs ] );

        $this->assertSame( 1, (int) PlayerFileCounts::for( $player_id )['goals'] );
        $this->assertSame( 1, ( new GoalsRepository() )->countActiveForPlayer( $player_id ) );
    }

    /* ---- helpers -------------------------------------------------------- */

    private function player( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => "Squad {$first}{$last}" ] );
        $team_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first,
            'last_name'  => $last,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function goal( int $player_id, string $title, ?string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'title'      => $title,
            'status'     => $status,
            'created_by' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }
}
