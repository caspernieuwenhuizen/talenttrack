<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;

/**
 * #3094 — entering goals and assists as counts, from the minutes grid.
 *
 * The grid enters numbers and the table stores events, so everything worth
 * testing lives in the gap between those two:
 *
 *   - counting up inserts manual rows with no minute, because the coach
 *     does not know it and a fabricated 45' would read as observed;
 *   - counting down reverses rather than deletes, and reverses what was
 *     typed before what was watched;
 *   - an assist attaches to an existing goal instead of inventing one,
 *     which would inflate the team's score;
 *   - an assist with no free goal creates the "no scorer recorded"
 *     placeholder, and taking it away again removes it;
 *   - a correction on a live column leaves the live rows' minutes intact;
 *   - the scoreline is never written.
 */
final class MinutesGridContributionsTest extends WP_UnitTestCase {

    /** @var int */
    private $activity_id;

    /** @var int */
    private $scorer;

    /** @var int */
    private $mate;

    /** @var MatchExecutionRepository */
    private $repo;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $this->repo        = new MatchExecutionRepository();
        $this->activity_id = $this->makeMatch();
        $this->scorer      = $this->makePlayer( 'Sem', 'Bakker' );
        $this->mate        = $this->makePlayer( 'Noah', 'Jansen' );
    }

    public function test_counting_up_writes_goals_with_no_minute(): void {
        global $wpdb;

        $this->repo->setContributions( $this->activity_id, $this->scorer, 2, 0 );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT execution_id, half, minute_in_half FROM {$wpdb->prefix}tt_match_execution_goal_events
              WHERE activity_id = %d AND player_id = %d AND reversed_at IS NULL",
            $this->activity_id,
            $this->scorer
        ) );

        $this->assertCount( 2, $rows );
        foreach ( $rows as $row ) {
            $this->assertNull( $row->execution_id, 'a manual goal belongs to no execution' );
            $this->assertNull( $row->half );
            $this->assertNull( $row->minute_in_half, 'a minute nobody knows must not be invented' );
        }

        $this->assertSame( 2, $this->goals( $this->scorer ) );
    }

    public function test_counting_down_reverses_rather_than_deletes(): void {
        global $wpdb;

        $this->repo->setContributions( $this->activity_id, $this->scorer, 3, 0 );
        $this->repo->setContributions( $this->activity_id, $this->scorer, 1, 0 );

        $this->assertSame( 1, $this->goals( $this->scorer ) );

        $reversed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_match_execution_goal_events
              WHERE activity_id = %d AND player_id = %d AND reversed_at IS NOT NULL",
            $this->activity_id,
            $this->scorer
        ) );

        $this->assertSame( 2, $reversed, 'the correction stays auditable' );
    }

    /** Setting the same number twice must not churn the rows. */
    public function test_saving_an_unchanged_count_writes_nothing(): void {
        global $wpdb;

        $this->repo->setContributions( $this->activity_id, $this->scorer, 2, 0 );
        $before = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_match_execution_goal_events"
        );

        $this->repo->setContributions( $this->activity_id, $this->scorer, 2, 0 );
        $after = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_match_execution_goal_events"
        );

        $this->assertSame( $before, $after );
    }

    /**
     * The mistake this method exists to avoid: an assist is a column on a
     * goal, so recording one must not add a goal to the team's total.
     */
    public function test_an_assist_attaches_to_an_existing_goal(): void {
        $this->repo->setContributions( $this->activity_id, $this->scorer, 1, 0 );
        $this->repo->setContributions( $this->activity_id, $this->mate, 0, 1 );

        $this->assertSame( 1, $this->goals( $this->scorer ) );
        $this->assertSame( 0, $this->goals( $this->mate ), 'an assist is not a goal' );
        $this->assertSame( 1, $this->assists( $this->mate ) );
        $this->assertSame( 1, $this->totalGoals(), 'the team scored once, not twice' );
    }

    /** With no goal to hang it on, the honest record is a scorerless goal. */
    public function test_an_assist_with_no_free_goal_creates_an_unattributed_one(): void {
        global $wpdb;

        $this->repo->setContributions( $this->activity_id, $this->mate, 0, 1 );

        $this->assertSame( 1, $this->assists( $this->mate ) );

        $placeholder = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_match_execution_goal_events
              WHERE activity_id = %d AND player_id = 0 AND assist_player_id = %d
                AND execution_id IS NULL AND reversed_at IS NULL",
            $this->activity_id,
            $this->mate
        ) );

        $this->assertSame( 1, $placeholder );
    }

    /** Taking that assist away must not leave a goal nobody claims. */
    public function test_removing_the_assist_removes_its_placeholder(): void {
        $this->repo->setContributions( $this->activity_id, $this->mate, 0, 1 );
        $this->repo->setContributions( $this->activity_id, $this->mate, 0, 0 );

        $this->assertSame( 0, $this->assists( $this->mate ) );
        $this->assertSame( 0, $this->totalGoals(), 'the placeholder went with the assist' );
    }

    /** A player cannot set up their own goal. */
    public function test_an_assist_never_attaches_to_the_players_own_goal(): void {
        $this->repo->setContributions( $this->activity_id, $this->scorer, 1, 1 );

        $this->assertSame( 1, $this->goals( $this->scorer ) );
        $this->assertSame( 1, $this->assists( $this->scorer ) );
        $this->assertSame( 2, $this->totalGoals(), 'the assist needed a second goal to sit on' );
    }

    /**
     * A correction on a live column keeps the live data. Reversing an
     * observed goal to satisfy a typed count would destroy the more
     * reliable record of the two.
     */
    public function test_a_correction_reverses_manual_rows_before_live_ones(): void {
        global $wpdb;

        $execution_id = $this->makeExecution();
        $this->repo->logGoalEvent( $execution_id, 'live-uuid-1', $this->scorer, 1, 12, 'home' );
        $this->repo->setContributions( $this->activity_id, $this->scorer, 3, 0 );

        $this->assertSame( 3, $this->goals( $this->scorer ) );

        // Back down to one: the two typed rows go, the watched one stays.
        $this->repo->setContributions( $this->activity_id, $this->scorer, 1, 0 );

        $survivor = $wpdb->get_row( $wpdb->prepare(
            "SELECT execution_id, half, minute_in_half FROM {$wpdb->prefix}tt_match_execution_goal_events
              WHERE activity_id = %d AND player_id = %d AND reversed_at IS NULL",
            $this->activity_id,
            $this->scorer
        ) );

        $this->assertNotNull( $survivor );
        $this->assertSame( $execution_id, (int) $survivor->execution_id );
        $this->assertSame( 12, (int) $survivor->minute_in_half, 'the live minute is untouched' );
    }

    /** A live goal is now addressable by its activity, not only its execution. */
    public function test_a_live_goal_carries_its_activity(): void {
        $execution_id = $this->makeExecution();
        $this->repo->logGoalEvent( $execution_id, 'live-uuid-2', $this->scorer, 2, 30, 'home' );

        $this->assertSame( 1, $this->goals( $this->scorer ) );
    }

    /** Attribution is what we know; the scoreline is what happened. */
    public function test_recording_output_never_writes_the_scoreline(): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'tt_activities',
            [ 'home_score' => 3 ],
            [ 'id' => $this->activity_id ]
        );

        $this->repo->setContributions( $this->activity_id, $this->scorer, 7, 0 );

        $score = $wpdb->get_var( $wpdb->prepare(
            "SELECT home_score FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $this->activity_id
        ) );

        $this->assertSame( 3, (int) $score );
    }

    // ---- helpers ----------------------------------------------------------

    private function goals( int $player_id ): int {
        $counts = $this->repo->contributionsByActivity( [ $this->activity_id ] );
        return (int) ( $counts[ $this->activity_id ][ $player_id ]['goals'] ?? 0 );
    }

    private function assists( int $player_id ): int {
        $counts = $this->repo->contributionsByActivity( [ $this->activity_id ] );
        return (int) ( $counts[ $this->activity_id ][ $player_id ]['assists'] ?? 0 );
    }

    private function totalGoals(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_match_execution_goal_events
              WHERE activity_id = %d AND team = 'home' AND is_own_goal = 0 AND reversed_at IS NULL",
            $this->activity_id
        ) );
    }

    private function makeMatch(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => CurrentClub::id(),
            'team_id'           => 7,
            'title'             => 'Ajax U17 — away',
            'session_date'      => '2026-08-15',
            'activity_type_key' => 'game',
            'opponent'          => 'Ajax U17',
            'home_away'         => 'away',
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makeExecution(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_match_execution', [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => CurrentClub::id(),
            'activity_id'   => $this->activity_id,
            'match_prep_id' => 0,
            'state'         => 'finished',
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makePlayer( string $first, string $last ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => CurrentClub::id(),
            'team_id'    => 7,
            'first_name' => $first,
            'last_name'  => $last,
        ] );

        return (int) $wpdb->insert_id;
    }
}
