<?php
namespace TT\Tests\Php;

use TT\Domain\Vocabularies\Enums\GoalOrigin;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Wizards\Goal\DetailsStep;
use WP_UnitTestCase;

/**
 * #3131 — a goal reaches the player's timeline whichever path wrote it.
 *
 * `tt_goals` had six write paths and exactly one of them fired
 * `tt_goal_saved`, so `JourneyEventSubscriber` heard about a goal created
 * over REST and about none of the others. The goal wizard was the sharp
 * end: CLAUDE.md §3 makes it the primary record-creation path, so the
 * route the product steers a coach onto was the route whose goals were
 * invisible on the journey.
 *
 * The announcement moved into `GoalsRepository::create()`, so these
 * assertions are about the chokepoint holding rather than about six call
 * sites each remembering to fire a hook.
 */
final class GoalSetJourneyEventTest extends WP_UnitTestCase {

    private int $user_id   = 0;
    private int $player_id = 0;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );
        $this->player_id = $this->makePlayer();
    }

    /** The path the issue was filed about, asserted through the wizard step. */
    public function test_a_goal_set_in_the_wizard_reaches_the_timeline(): void {
        $result = ( new DetailsStep() )->submit( [
            'player_id'   => $this->player_id,
            'title'       => 'Improve weak foot',
            'description' => 'Both feet in the final third.',
            'priority'    => 'high',
            'due_date'    => '2026-12-01',
        ] );

        $this->assertIsArray( $result, 'the wizard step returned an error instead of a redirect' );

        $events = $this->goalEvents( $this->player_id );
        $this->assertCount( 1, $events, 'a goal set in the wizard writes exactly one journey entry' );
        $this->assertSame( 'Goal set: Improve weak foot', (string) $events[0]->summary );
        $this->assertSame( GoalOrigin::SET, $this->origin( $events[0] ) );
    }

    /** Announced once — not once per caller plus once per repository. */
    public function test_the_repository_announces_exactly_once(): void {
        $fired = 0;
        add_action( 'tt_goal_saved', static function () use ( &$fired ): void { $fired++; }, 10, 4 );

        $goal_id = ( new GoalsRepository() )->create( [
            'player_id' => $this->player_id,
            'title'     => 'One announcement',
            'status'    => 'pending',
        ] );

        $this->assertGreaterThan( 0, $goal_id );
        $this->assertSame( 1, $fired );
        $this->assertCount( 1, $this->goalEvents( $this->player_id ) );
    }

    /** The announcement belongs to a row that exists. */
    public function test_a_failed_insert_announces_nothing(): void {
        $fired = 0;
        add_action( 'tt_goal_saved', static function () use ( &$fired ): void { $fired++; }, 10, 4 );

        // `no_such_column` is not on `tt_goals`, so `wpdb::insert()` refuses
        // the row before it reaches the database.
        $goal_id = ( new GoalsRepository() )->create( [
            'player_id'      => $this->player_id,
            'title'          => 'Rejected',
            'no_such_column' => 'x',
        ] );

        $this->assertSame( 0, $goal_id );
        $this->assertSame( 0, $fired, 'nothing is announced for a row that was never written' );
    }

    /**
     * A carried-over goal emits, says so, and is dated to the season it
     * belongs to rather than to the afternoon the rollover was run.
     */
    public function test_a_carried_over_goal_carries_its_provenance_and_the_season_date(): void {
        ( new GoalsRepository() )->create( [
            'player_id' => $this->player_id,
            'title'     => 'Hold the line',
            'status'    => 'pending',
        ], [
            'origin'      => GoalOrigin::CARRIED_OVER,
            'occurred_at' => '2026-07-01',
        ] );

        $events = $this->goalEvents( $this->player_id );
        $this->assertCount( 1, $events );
        $this->assertSame( 'Goal carried over: Hold the line', (string) $events[0]->summary );
        $this->assertSame( GoalOrigin::CARRIED_OVER, $this->origin( $events[0] ) );
        $this->assertSame(
            '2026-07-01 00:00:00',
            (string) $events[0]->event_date,
            'the entry is dated to the goal, not to the run'
        );
    }

    public function test_a_spawned_goal_says_where_it_came_from(): void {
        ( new GoalsRepository() )->create( [
            'player_id' => $this->player_id,
            'title'     => 'Idea goal',
            'status'    => 'pending',
        ], [ 'origin' => GoalOrigin::SPAWNED ] );

        $events = $this->goalEvents( $this->player_id );
        $this->assertCount( 1, $events );
        $this->assertSame( 'Goal opened from a development idea: Idea goal', (string) $events[0]->summary );
        $this->assertSame( GoalOrigin::SPAWNED, $this->origin( $events[0] ) );
    }

    /**
     * The burst a season rollover writes is what the provenance field is
     * for: one date, many players, and a reader who can still tell the
     * club's bookkeeping from a coaching decision.
     */
    public function test_a_rollover_burst_stays_distinguishable_from_a_coach_setting_a_goal(): void {
        $repo   = new GoalsRepository();
        $others = [ $this->makePlayer(), $this->makePlayer() ];

        foreach ( $others as $pid ) {
            $repo->create(
                [ 'player_id' => $pid, 'title' => 'Carried', 'status' => 'pending' ],
                [ 'origin' => GoalOrigin::CARRIED_OVER, 'occurred_at' => '2026-07-01' ]
            );
        }
        $repo->create(
            [ 'player_id' => $this->player_id, 'title' => 'Decided', 'status' => 'pending' ],
            [ 'origin' => GoalOrigin::SET ]
        );

        foreach ( $others as $pid ) {
            $e = $this->goalEvents( $pid );
            $this->assertCount( 1, $e );
            $this->assertSame( GoalOrigin::CARRIED_OVER, $this->origin( $e[0] ) );
        }

        $mine = $this->goalEvents( $this->player_id );
        $this->assertCount( 1, $mine );
        $this->assertSame( GoalOrigin::SET, $this->origin( $mine[0] ) );
    }

    /**
     * A listener written before the fourth argument existed still works —
     * WordPress passes it only what it registered for.
     */
    public function test_a_three_argument_listener_still_receives_the_goal(): void {
        $seen = [];
        add_action( 'tt_goal_saved', static function ( $player_id, $goal_id, $data ) use ( &$seen ): void {
            $seen[] = [ (int) $player_id, (int) $goal_id, (string) ( $data['title'] ?? '' ) ];
        }, 10, 3 );

        $goal_id = ( new GoalsRepository() )->create( [
            'player_id' => $this->player_id,
            'title'     => 'Back-compat',
            'status'    => 'pending',
        ] );

        $this->assertSame( [ [ $this->player_id, $goal_id, 'Back-compat' ] ], $seen );
    }

    /** An unrecognised origin reads as a person having set the goal. */
    public function test_an_unknown_origin_degrades_to_set(): void {
        ( new GoalsRepository() )->create( [
            'player_id' => $this->player_id,
            'title'     => 'Odd',
            'status'    => 'pending',
        ], [ 'origin' => 'something_else' ] );

        $events = $this->goalEvents( $this->player_id );
        $this->assertCount( 1, $events );
        $this->assertSame( GoalOrigin::SET, $this->origin( $events[0] ) );
    }

    /* ---- helpers -------------------------------------------------------- */

    private function makePlayer(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Goal',
            'last_name'     => 'Owner',
            'date_of_birth' => '2011-01-01',
            'status'        => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return array<int,object> */
    private function goalEvents( int $player_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT summary, payload, event_date FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s
              ORDER BY id ASC",
            $player_id,
            'goal_set'
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    private function origin( object $row ): string {
        $payload = json_decode( (string) ( $row->payload ?? '{}' ), true );
        return is_array( $payload ) ? (string) ( $payload['origin'] ?? '' ) : '';
    }
}
