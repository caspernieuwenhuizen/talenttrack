<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\GoalContributionQuery;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;

/**
 * #4506 — goals and assists cannot exceed what the match's score allows.
 *
 * On jg4it a team report showed about 800 assists against about 20 goals:
 * minutes typed into the grid's A column, each assist above the free goals
 * written as a `player_id = 0` placeholder carrying it. With a score stored,
 * our score is the ceiling, per player and added up; with none, 15 per
 * player. A refused batch writes nothing. Lowering an inflated count is the
 * correction, and it takes the placeholders with it.
 */
final class ContributionsCeilingTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 0;
    private int $team = 0;
    private int $scorer = 0;
    private int $mate = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Hedel O14-1' ] );
        $this->team   = (int) $wpdb->insert_id;
        $this->scorer = $this->insertPlayer( 'Sem' );
        $this->mate   = $this->insertPlayer( 'Morris' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_on_a_2_1_match_70_assists_are_refused_naming_the_limit(): void {
        $match = $this->insertMatch( 2, 1 );

        $res = $this->put( $match, [ [ 'player_id' => $this->mate, 'goals' => 0, 'assists' => 70 ] ] );

        $this->assertSame( 400, $res->get_status() );
        $error = $this->error( $res );
        $this->assertSame( 'contributions_exceed_score', $error['code'] );
        $this->assertStringContainsString( '2', (string) $error['message'], 'the message names the 2-goal limit' );
        $details = (array) $error['details'];
        $this->assertSame( 2, $details['limit'] );
        $this->assertSame( $match, $details['activity_id'] );
        $this->assertSame( [ [ 'player_id' => $this->mate, 'field' => 'assists', 'value' => 70 ] ], $details['players'] );
        $this->assertSame( 0, $this->assists( $match, $this->mate ), 'nothing is written' );
        $this->assertSame( 0, $this->placeholders( $match ) );
    }

    public function test_on_a_2_1_match_1_assist_is_accepted(): void {
        $match = $this->insertMatch( 2, 1 );

        $res = $this->put( $match, [ [ 'player_id' => $this->mate, 'goals' => 0, 'assists' => 1 ] ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 1, $this->assists( $match, $this->mate ) );
    }

    public function test_the_match_total_cannot_exceed_our_score(): void {
        $match = $this->insertMatch( 2, 1 );
        $this->assertSame( 200, $this->put( $match, [ [ 'player_id' => $this->scorer, 'goals' => 2, 'assists' => 0 ] ] )->get_status() );

        // Each count fits on its own; the match would then have 3 goals.
        $res = $this->put( $match, [ [ 'player_id' => $this->mate, 'goals' => 1, 'assists' => 0 ] ] );

        $this->assertSame( 400, $res->get_status() );
        $details = (array) $this->error( $res )['details'];
        $this->assertSame( [ [ 'field' => 'goals', 'value' => 3 ] ], $details['totals'] );
        $this->assertSame( 0, $this->goals( $match, $this->mate ) );
    }

    public function test_with_no_score_16_assists_are_refused_and_3_accepted(): void {
        $match = $this->insertMatch( null, null );

        $refused = $this->put( $match, [ [ 'player_id' => $this->mate, 'goals' => 0, 'assists' => 16 ] ] );
        $this->assertSame( 400, $refused->get_status() );
        $this->assertSame( 'contributions_out_of_range', $this->error( $refused )['code'] );
        $this->assertSame( 15, ( (array) $this->error( $refused )['details'] )['limit'] );

        $accepted = $this->put( $match, [ [ 'player_id' => $this->mate, 'goals' => 0, 'assists' => 3 ] ] );
        $this->assertSame( 200, $accepted->get_status() );
        $this->assertSame( 3, $this->assists( $match, $this->mate ) );
    }

    public function test_lowering_an_inflated_count_reverses_its_placeholders(): void {
        $match = $this->insertMatch( 2, 1 );
        // Written before the ceiling existed: straight to the repository.
        ( new MatchExecutionRepository() )->setContributions( $match, $this->mate, 0, 70 );
        ( new MatchExecutionRepository() )->setContributions( $match, $this->scorer, 0, 72 );
        $this->assertSame( 142, $this->placeholders( $match ), 'precondition: one placeholder goal per inflated assist' );
        $this->assertSame( 70, $this->reportAssists( $this->mate ) );

        // Lowering one player is accepted while the other is still inflated.
        $res = $this->put( $match, [ [ 'player_id' => $this->mate, 'goals' => 0, 'assists' => 0 ] ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 72, $this->placeholders( $match ), 'his 70 placeholders are reversed' );
        $this->assertSame( 0, $this->reportAssists( $this->mate ), 'the team report drops accordingly' );

        $this->assertSame( 200, $this->put( $match, [ [ 'player_id' => $this->scorer, 'goals' => 0, 'assists' => 1 ] ] )->get_status() );
        $this->assertSame( 1, $this->placeholders( $match ) );
        $this->assertSame( 1, $this->reportAssists( $this->scorer ) );
    }

    /**
     * The grid sends a player's goals and assists together. With his goals
     * still inflated, lowering his assists must be accepted: only a raise
     * past the ceiling is refused, per player as per match.
     */
    public function test_an_inflated_player_can_lower_assists_while_goals_stay_inflated(): void {
        $match = $this->insertMatch( 2, 1 );
        ( new MatchExecutionRepository() )->setContributions( $match, $this->mate, 30, 40 );
        $this->assertSame( 30, $this->goals( $match, $this->mate ), 'precondition: inflated before the ceiling' );
        $this->assertSame( 40, $this->assists( $match, $this->mate ) );

        $res = $this->put( $match, [ [ 'player_id' => $this->mate, 'goals' => 30, 'assists' => 1 ] ] );

        $this->assertSame( 200, $res->get_status(), 'a lowering is accepted while goals are still 30' );
        $this->assertSame( 1, $this->assists( $match, $this->mate ) );
        $this->assertSame( 30, $this->goals( $match, $this->mate ) );

        // A raise is still refused, even on an inflated player.
        $raise = $this->put( $match, [ [ 'player_id' => $this->mate, 'goals' => 31, 'assists' => 1 ] ] );
        $this->assertSame( 400, $raise->get_status() );
        $this->assertSame( 'contributions_exceed_score', $this->error( $raise )['code'] );
    }

    // ---- fixtures ---------------------------------------------------------

    /** @param list<array<string, int>> $players */
    private function put( int $activity_id, array $players ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $activity_id . '/contributions' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'players' => $players ] ) );
        return rest_do_request( $req );
    }

    /** @return array<string, mixed> */
    private function error( \WP_REST_Response $res ): array {
        $data = (array) $res->get_data();
        $this->assertNotEmpty( $data['errors'] ?? [], 'an error envelope' );
        return (array) $data['errors'][0];
    }

    private function insertPlayer( string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => $first,
            'last_name'  => 'Speler',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMatch( ?int $ours, ?int $theirs ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Baronie O14-1',
            'session_date'        => '2026-10-31',
            'activity_type_key'   => 'game',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
            'opponent'            => 'Baronie O14-1',
            'home_away'           => 'home',
            'home_score'          => $ours,
            'away_score'          => $theirs,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function goals( int $activity_id, int $player_id ): int {
        $c = ( new MatchExecutionRepository() )->contributionsByActivity( [ $activity_id ] );
        return (int) ( $c[ $activity_id ][ $player_id ]['goals'] ?? 0 );
    }

    private function assists( int $activity_id, int $player_id ): int {
        $c = ( new MatchExecutionRepository() )->contributionsByActivity( [ $activity_id ] );
        return (int) ( $c[ $activity_id ][ $player_id ]['assists'] ?? 0 );
    }

    /** Unreversed "no scorer recorded" goals on the match. */
    private function placeholders( int $activity_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_match_execution_goal_events
              WHERE activity_id = %d AND player_id = 0 AND execution_id IS NULL AND reversed_at IS NULL",
            $activity_id
        ) );
    }

    /** The assists the team report counts for a player this season. */
    private function reportAssists( int $player_id ): int {
        $rows = ( new GoalContributionQuery() )->forTeam( $this->team, [ 'from' => '2026-07-01', 'to' => '2027-06-30' ] );
        return (int) ( $rows[ $player_id ]['assists'] ?? 0 );
    }
}
