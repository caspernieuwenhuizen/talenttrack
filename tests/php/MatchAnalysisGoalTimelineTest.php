<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #2860 — the goal timeline on the match-analysis readback.
 *
 * The analysis and the match-execution log describe the same game and did
 * not agree about it: the readback showed which team functions were rated
 * and how the match ended, but never when the goals came or who scored
 * them. These cases pin the shape the readback reads — chronological across
 * both halves, per-fixture, and read-only.
 */
final class MatchAnalysisGoalTimelineTest extends WP_UnitTestCase {

    private const TEAM_ID = 881;
    private const HALF_LENGTH = 30;

    private const STRIKER = 21;
    private const PLAYMAKER = 22;
    private const DEFENDER = 23;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    /** @return array{prep:?object, exec:?object} */
    private function seedMatch( int $activity_id ): array {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => $activity_id,
            'team_id'           => self::TEAM_ID,
            'title'             => 'Analysis match ' . $activity_id,
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );

        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( $activity_id, self::HALF_LENGTH );

        $exec_repo = new MatchExecutionRepository();
        $exec_id   = $exec_repo->ensureForActivity( $activity_id, $prep_id );
        $exec_repo->update( $exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );

        return [
            'prep' => $prep_repo->findByActivity( $activity_id ),
            'exec' => $exec_repo->findByActivity( $activity_id ),
        ];
    }

    private function goal( int $exec_id, int $half, int $minute, int $scorer, ?int $assist = null, string $team = 'home', bool $own = false ): void {
        ( new MatchExecutionRepository() )->logGoalEvent(
            $exec_id, wp_generate_uuid4(), $scorer, $half, $minute, $team, $assist, $own
        );
    }

    public function test_goals_read_chronologically_across_both_halves(): void {
        $seed = $this->seedMatch( 9201 );
        $exec_id = (int) $seed['exec']->id;

        // Logged out of order on purpose: a late correction can land after a
        // second-half goal was already recorded.
        $this->goal( $exec_id, 2, 5, self::STRIKER );
        $this->goal( $exec_id, 1, 12, self::PLAYMAKER );

        $goals = MatchAnalysisComposer::goalsFor( $seed['prep'], $seed['exec'], new MatchExecutionRepository() );

        $this->assertCount( 2, $goals );
        $this->assertSame( 12, $goals[0]['minute'], 'the first-half goal comes first' );
        $this->assertSame(
            self::HALF_LENGTH + 5,
            $goals[1]['minute'],
            'a second-half minute is made absolute so the list reads as one game'
        );
    }

    public function test_our_goal_carries_scorer_and_assist(): void {
        $seed = $this->seedMatch( 9202 );
        $this->goal( (int) $seed['exec']->id, 1, 10, self::STRIKER, self::PLAYMAKER );

        $goals = MatchAnalysisComposer::goalsFor( $seed['prep'], $seed['exec'], new MatchExecutionRepository() );

        $this->assertSame( 'home', $goals[0]['team'] );
        $this->assertTrue( $goals[0]['has_scorer'] );
        $this->assertNotSame( '', $goals[0]['scorer'] );
        $this->assertNotSame( '', $goals[0]['assist'] );
    }

    public function test_the_three_states_stay_apart(): void {
        $seed = $this->seedMatch( 9203 );
        $exec_id = (int) $seed['exec']->id;

        $this->goal( $exec_id, 1, 5, 0 );                                  // ours, no scorer
        $this->goal( $exec_id, 1, 10, 0, null, 'home', true );             // their own goal, counts for us
        $this->goal( $exec_id, 1, 15, 0, null, 'away' );                   // theirs
        $this->goal( $exec_id, 1, 20, self::DEFENDER, null, 'away', true ); // ours into our own net

        $goals = MatchAnalysisComposer::goalsFor( $seed['prep'], $seed['exec'], new MatchExecutionRepository() );

        $this->assertFalse( $goals[0]['has_scorer'] );
        $this->assertFalse( $goals[0]['is_own_goal'], 'no scorer recorded is not an own goal' );

        $this->assertTrue( $goals[1]['is_own_goal'] );
        $this->assertSame( 'home', $goals[1]['team'] );

        $this->assertSame( 'away', $goals[2]['team'] );
        $this->assertFalse( $goals[2]['has_scorer'], 'the opponent squad is not in the system' );

        $this->assertSame( 'away', $goals[3]['team'] );
        $this->assertTrue( $goals[3]['is_own_goal'] );
        $this->assertTrue( $goals[3]['has_scorer'], 'one of ours into our own net is attributable' );
    }

    public function test_an_undone_goal_leaves_the_timeline(): void {
        $seed = $this->seedMatch( 9204 );
        $repo = new MatchExecutionRepository();
        $uuid = wp_generate_uuid4();
        $repo->logGoalEvent( (int) $seed['exec']->id, $uuid, self::STRIKER, 1, 10, 'home' );

        $this->assertCount( 1, MatchAnalysisComposer::goalsFor( $seed['prep'], $seed['exec'], $repo ) );

        $repo->reverseGoalEvent( $uuid );

        $this->assertSame( [], MatchAnalysisComposer::goalsFor( $seed['prep'], $seed['exec'], $repo ) );
    }

    /**
     * A match with no execution, or one with an execution and no goals, must
     * produce nothing at all — the renderer drops the whole section rather
     * than asserting "no goals were scored" about a match that was simply
     * never run through the live screen.
     */
    public function test_a_match_with_no_goals_yields_nothing(): void {
        $seed = $this->seedMatch( 9205 );
        $this->assertSame( [], MatchAnalysisComposer::goalsFor( $seed['prep'], $seed['exec'], new MatchExecutionRepository() ) );
        $this->assertSame( [], MatchAnalysisComposer::goalsFor( $seed['prep'], null, new MatchExecutionRepository() ) );
    }

    /**
     * Tournaments are multi-game days: two fixtures on one day each have
     * their own execution, and a per-fixture surface must not pool them.
     */
    public function test_goals_are_resolved_per_fixture_not_per_day(): void {
        $first  = $this->seedMatch( 9206 );
        $second = $this->seedMatch( 9207 );

        $this->goal( (int) $first['exec']->id, 1, 10, self::STRIKER );
        $this->goal( (int) $second['exec']->id, 1, 10, self::PLAYMAKER );
        $this->goal( (int) $second['exec']->id, 1, 20, self::PLAYMAKER );

        $repo = new MatchExecutionRepository();
        $this->assertCount( 1, MatchAnalysisComposer::goalsFor( $first['prep'], $first['exec'], $repo ) );
        $this->assertCount( 2, MatchAnalysisComposer::goalsFor( $second['prep'], $second['exec'], $repo ) );
    }

    public function test_half_length_falls_back_when_there_is_no_prep(): void {
        $seed = $this->seedMatch( 9208 );
        $this->goal( (int) $seed['exec']->id, 2, 5, self::STRIKER );

        $goals = MatchAnalysisComposer::goalsFor( null, $seed['exec'], new MatchExecutionRepository() );

        $this->assertSame( 40, $goals[0]['minute'], 'no prep means the 35-minute default half' );
    }
}
