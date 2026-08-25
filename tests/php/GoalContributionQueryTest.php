<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Reports\GoalContributionQuery;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #2859 — goals and assists per player.
 *
 * The counting rules are the substance here, and each one exists because the
 * obvious alternative is wrong: an unattributed goal must credit nobody
 * rather than be guessed at, an own goal must not flatter the player who
 * scored it, and an undone goal must vanish from everyone's tally.
 */
final class GoalContributionQueryTest extends WP_UnitTestCase {

    private const TEAM_ID = 771;
    private const OTHER_TEAM_ID = 772;
    private const HALF_LENGTH = 35;

    private const STRIKER = 11;
    private const PLAYMAKER = 12;
    private const DEFENDER = 13;

    /** activity_id => execution_id */
    private array $execs = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private function seedMatch( int $activity_id, string $date, int $team_id = self::TEAM_ID ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => $activity_id,
            'team_id'           => $team_id,
            'title'             => 'Match ' . $activity_id,
            'session_date'      => $date,
            'activity_type_key' => 'match',
        ] );

        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( $activity_id, self::HALF_LENGTH );

        $exec_repo = new MatchExecutionRepository();
        $exec_id   = $exec_repo->ensureForActivity( $activity_id, $prep_id );
        $exec_repo->update( $exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );

        $this->execs[ $activity_id ] = $exec_id;
        return $exec_id;
    }

    private function goal( int $exec_id, int $scorer, ?int $assist = null, string $team = 'home', bool $own = false ): string {
        $uuid = wp_generate_uuid4();
        ( new MatchExecutionRepository() )->logGoalEvent( $exec_id, $uuid, $scorer, 1, 10, $team, $assist, $own );
        return $uuid;
    }

    // ---------------------------------------------------------------
    // Counting rules
    // ---------------------------------------------------------------

    public function test_goals_and_assists_are_counted_independently(): void {
        $exec = $this->seedMatch( 9101, '2026-03-01' );
        // The striker scores twice, once set up by the playmaker, and then
        // turns provider for the playmaker's goal.
        $this->goal( $exec, self::STRIKER, self::PLAYMAKER );
        $this->goal( $exec, self::STRIKER );
        $this->goal( $exec, self::PLAYMAKER, self::STRIKER );

        $q = new GoalContributionQuery();

        $striker = $q->forPlayer( self::STRIKER );
        $this->assertSame( 2, $striker['goals'] );
        $this->assertSame( 1, $striker['assists'] );
        $this->assertSame( 3, $striker['contributions'] );

        $playmaker = $q->forPlayer( self::PLAYMAKER );
        $this->assertSame( 1, $playmaker['goals'] );
        $this->assertSame( 1, $playmaker['assists'] );
    }

    public function test_an_unattributed_goal_credits_nobody(): void {
        $exec = $this->seedMatch( 9102, '2026-03-02' );
        $this->goal( $exec, 0 );

        $team = ( new GoalContributionQuery() )->forTeam( self::TEAM_ID );
        $this->assertSame( [], $team, 'a goal with no scorer belongs to the match, not to a player' );
    }

    public function test_an_own_goal_never_adds_to_a_goal_tally(): void {
        $exec = $this->seedMatch( 9103, '2026-03-03' );
        // One of ours into our own net: counts for the opponent, and is
        // attributable to the defender without being a contribution.
        $this->goal( $exec, self::DEFENDER, null, 'away', true );

        $defender = ( new GoalContributionQuery() )->forPlayer( self::DEFENDER );
        $this->assertSame( 0, $defender['goals'], 'an own goal is not an attacking contribution' );
        $this->assertSame( 1, $defender['own_goals'], 'but it is still recorded against them' );
        $this->assertSame( 0, $defender['contributions'] );
    }

    public function test_an_opponent_own_goal_credits_none_of_ours(): void {
        $exec = $this->seedMatch( 9104, '2026-03-04' );
        // Their player into their own net: counts for us, scorer is nobody.
        $this->goal( $exec, 0, null, 'home', true );

        $this->assertSame( [], ( new GoalContributionQuery() )->forTeam( self::TEAM_ID ) );
    }

    public function test_an_undone_goal_counts_for_nobody(): void {
        $exec = $this->seedMatch( 9105, '2026-03-05' );
        $uuid = $this->goal( $exec, self::STRIKER, self::PLAYMAKER );

        $q = new GoalContributionQuery();
        $this->assertSame( 1, $q->forPlayer( self::STRIKER )['goals'] );

        ( new MatchExecutionRepository() )->reverseGoalEvent( $uuid );

        $this->assertSame( 0, $q->forPlayer( self::STRIKER )['goals'] );
        $this->assertSame( 0, $q->forPlayer( self::PLAYMAKER )['assists'], 'the assist goes with the goal' );
    }

    // ---------------------------------------------------------------
    // Scoping
    // ---------------------------------------------------------------

    public function test_the_team_view_only_counts_that_team(): void {
        $ours   = $this->seedMatch( 9106, '2026-03-06', self::TEAM_ID );
        $theirs = $this->seedMatch( 9107, '2026-03-07', self::OTHER_TEAM_ID );
        $this->goal( $ours, self::STRIKER );
        $this->goal( $theirs, self::STRIKER );

        $team = ( new GoalContributionQuery() )->forTeam( self::TEAM_ID );
        $this->assertSame( 1, $team[ self::STRIKER ]['goals'] );
    }

    public function test_the_window_narrows_the_count(): void {
        $early = $this->seedMatch( 9108, '2026-01-15' );
        $late  = $this->seedMatch( 9109, '2026-06-15' );
        $this->goal( $early, self::STRIKER );
        $this->goal( $late, self::STRIKER );

        $q = new GoalContributionQuery();
        $this->assertSame( 2, $q->forPlayer( self::STRIKER )['goals'], 'no window means the whole record' );
        $this->assertSame(
            1,
            $q->forPlayer( self::STRIKER, [ 'from' => '2026-06-01', 'to' => '2026-06-30' ] )['goals']
        );
    }

    /**
     * Both figures on the profile and the team report must describe the same
     * matches, or the row invites a comparison that does not hold.
     */
    public function test_player_and_team_views_agree(): void {
        $exec = $this->seedMatch( 9110, '2026-04-01' );
        $this->goal( $exec, self::STRIKER, self::PLAYMAKER );
        $this->goal( $exec, self::STRIKER );

        $q = new GoalContributionQuery();
        $team = $q->forTeam( self::TEAM_ID );
        $player = $q->forPlayer( self::STRIKER );

        $this->assertSame( $player['goals'], $team[ self::STRIKER ]['goals'] );
        $this->assertSame( $player['assists'], $team[ self::STRIKER ]['assists'] );
    }

    // ---------------------------------------------------------------
    // REST
    // ---------------------------------------------------------------

    public function test_rest_returns_the_same_numbers_as_the_query(): void {
        $exec = $this->seedMatch( 9111, '2026-05-01' );
        $this->goal( $exec, self::STRIKER, self::PLAYMAKER );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . self::STRIKER . '/goal-contributions' );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );

        $data = $res->get_data();
        $payload = $data['data'] ?? $data;
        $this->assertSame( 1, (int) $payload['goals'] );
        $this->assertSame( 0, (int) $payload['assists'] );

        $expected = ( new GoalContributionQuery() )->forPlayer( self::STRIKER );
        $this->assertSame( $expected['goals'], (int) $payload['goals'] );
    }

    public function test_rest_rejects_a_bad_player_id(): void {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/players/0/goal-contributions' );
        $this->assertNotSame( 200, rest_do_request( $req )->get_status() );
    }
}
