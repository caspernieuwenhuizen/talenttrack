<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Repositories\GoalLinksRepository;

/**
 * #2566 — a goal's principles live in `tt_goal_links`, many per goal, and
 * writing them must not disturb the other link types the same table carries.
 */
final class GoalPrincipleLinksTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function test_a_goal_can_carry_several_principles(): void {
        $goal_id = $this->goal( $this->player( 'Mul', 'Ti' ), 'in_progress' );
        $repo    = new GoalLinksRepository();

        $repo->syncPrinciples( $goal_id, [ 11, 12, 13 ] );

        $this->assertSame( [ 11, 12, 13 ], $repo->principleIdsForGoal( $goal_id ) );
    }

    public function test_syncing_principles_leaves_other_link_types_alone(): void {
        $goal_id = $this->goal( $this->player( 'Oth', 'Ers' ), 'in_progress' );
        $repo    = new GoalLinksRepository();

        $repo->sync( $goal_id, [
            [ 'type' => 'football_action', 'id' => 41 ],
            [ 'type' => 'position',        'id' => 42 ],
        ] );
        $repo->syncPrinciples( $goal_id, [ 21 ] );

        $this->assertSame( [ 21 ], $repo->principleIdsForGoal( $goal_id ) );

        $types = array_column( $repo->listForGoal( $goal_id ), 'type' );
        sort( $types );
        $this->assertSame( [ 'football_action', 'position', 'principle' ], $types,
            'a principle re-sync must not wipe the wizard-set links' );
    }

    public function test_an_empty_set_clears_the_principles(): void {
        $goal_id = $this->goal( $this->player( 'Cle', 'Ar' ), 'in_progress' );
        $repo    = new GoalLinksRepository();

        $repo->syncPrinciples( $goal_id, [ 31 ] );
        $repo->syncPrinciples( $goal_id, [] );

        $this->assertSame( [], $repo->principleIdsForGoal( $goal_id ) );
    }

    /**
     * The Training generator's read API is the reason this issue exists; it
     * must see a goal tagged through the picker.
     */
    public function test_open_principle_targets_sees_picker_written_links(): void {
        $player_id = $this->player( 'Tar', 'Gets' );
        $open      = $this->goal( $player_id, 'in_progress' );
        $closed    = $this->goal( $player_id, 'completed' );

        $repo = new GoalLinksRepository();
        $repo->syncPrinciples( $open, [ 51, 52 ] );
        $repo->syncPrinciples( $closed, [ 53 ] );

        $targets = ( new GoalsRepository() )->openPrincipleTargetsForPlayers( [ $player_id ] );

        $this->assertArrayHasKey( $player_id, $targets );
        sort( $targets[ $player_id ] );
        $this->assertSame( [ 51, 52 ], $targets[ $player_id ],
            'a completed goal is not an open development target' );
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

    private function goal( int $player_id, string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'title'      => 'principle link fixture',
            'status'     => $status,
            'created_by' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }
}
