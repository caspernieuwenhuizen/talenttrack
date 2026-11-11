<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * `GET /teams/{id}/stats`: the per-player rows follow the player read the
 * player pages use, so a reader sees figures only for players they may open.
 * The team's record and form stay whole for everyone who reads the team.
 */
final class TeamStatsReaderRowsTest extends WP_UnitTestCase {

    private const FROM = '2026-01-01';
    private const TO   = '2026-06-30';

    private string $p = '';
    private int $team = 0;
    private int $striker = 0;
    private int $playmaker = 0;
    private int $winger = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $this->p = $wpdb->prefix;

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O14-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->striker   = $this->player( 'Sem', 'Spits' );
        $this->playmaker = $this->player( 'Morris', 'Middenveld' );
        $this->winger    = $this->player( 'Wout', 'Vleugel' );

        $exec = $this->match( '2026-02-01', 3, 1 );
        $this->goal( $exec, $this->striker, $this->playmaker );
        $this->goal( $exec, $this->playmaker, $this->winger );
        $this->goal( $exec, $this->winger, $this->striker );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_a_scout_linked_to_no_player_gets_no_rows_and_the_whole_record(): void {
        $payload = $this->statsAs( $this->scout( [] ) );

        $this->assertSame( [], $payload['scorers'] );
        $this->assertSame( [], $payload['assists'] );
        $this->assertSame( [], $payload['appearances'] );
        $this->assertSame( 1, $payload['record']['played'], 'team totals are unchanged' );
        $this->assertSame( 3, $payload['record']['goals_for'] );
        $this->assertCount( 1, $payload['form'] );
    }

    public function test_a_scout_linked_to_some_players_gets_rows_for_those_only(): void {
        $payload = $this->statsAs( $this->scout( [ $this->striker ] ) );

        $this->assertSame( [ $this->striker ], array_column( $payload['scorers'], 'player_id' ) );
        $this->assertSame( [ $this->striker ], array_column( $payload['assists'], 'player_id' ) );
        $this->assertSame( 3, $payload['record']['goals_for'], 'team totals are unchanged' );
    }

    public function test_a_scout_linked_to_every_player_gets_every_row(): void {
        $payload = $this->statsAs( $this->scout( [ $this->striker, $this->playmaker, $this->winger ] ) );

        $this->assertEqualsCanonicalizing( [ $this->striker, $this->playmaker, $this->winger ], array_column( $payload['scorers'], 'player_id' ) );
        $this->assertCount( 3, $payload['assists'] );
    }

    public function test_the_coach_of_the_team_gets_every_row(): void {
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->coachOf( $coach, $this->team );

        $payload = $this->statsAs( $coach );

        $this->assertCount( 3, $payload['scorers'] );
        $this->assertCount( 3, $payload['assists'] );
    }

    public function test_the_head_of_development_gets_every_row(): void {
        $payload = $this->statsAs( (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] ) );

        $this->assertCount( 3, $payload['scorers'] );
        $this->assertCount( 3, $payload['assists'] );
    }

    public function test_appearances_are_narrowed_by_the_same_check(): void {
        $scout = $this->scout( [ $this->winger ] );
        $stats = [
            'record'      => [ 'played' => 1 ],
            'form'        => [ [ 'activity_id' => 1 ] ],
            'scorers'     => [],
            'assists'     => [],
            'appearances' => [
                [ 'player_id' => $this->striker, 'matches' => 1 ],
                [ 'player_id' => $this->winger, 'matches' => 1 ],
            ],
        ];

        $out = TeamMatchStatsQuery::forReader( $stats, $scout );

        $this->assertSame( [ $this->winger ], array_column( $out['appearances'], 'player_id' ) );
        $this->assertSame( $stats['record'], $out['record'] );
        $this->assertSame( $stats['form'], $out['form'] );
    }

    public function test_the_check_is_one_batch_whatever_the_number_of_rows(): void {
        $few  = $this->rows( 2 );
        $many = $this->rows( 12 );

        $this->assertSame(
            $this->statementsFor( $this->scout( [ $this->striker ] ), $few ),
            $this->statementsFor( $this->scout( [ $this->striker ] ), $many ),
            'no statement per row'
        );
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private function statsAs( int $user_id ): array {
        wp_set_current_user( $user_id );
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/teams/' . $this->team . '/stats' );
        $req->set_param( 'from', self::FROM );
        $req->set_param( 'to', self::TO );
        $res = rest_get_server()->dispatch( $req );

        $this->assertSame( 200, $res->get_status(), 'precondition: the reader reads the team' );
        $data = $res->get_data();
        return (array) ( $data['data'] ?? $data );
    }

    /** @param list<int> $player_ids */
    private function scout( array $player_ids ): int {
        $scout = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        update_user_meta( $scout, 'tt_scout_player_ids', wp_json_encode( $player_ids ) );
        return $scout;
    }

    /** @return array<string, mixed> */
    private function rows( int $count ): array {
        $rows = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $rows[] = [ 'player_id' => $this->player( 'Extra', 'Speler ' . $i ), 'goals' => 1 ];
        }
        return [ 'record' => [], 'form' => [], 'scorers' => $rows, 'assists' => $rows, 'appearances' => $rows ];
    }

    /** @param array<string, mixed> $stats */
    private function statementsFor( int $user_id, array $stats ): int {
        global $wpdb;
        $before = (int) $wpdb->num_queries;
        TeamMatchStatsQuery::forReader( $stats, $user_id );
        return (int) $wpdb->num_queries - $before;
    }

    /** A tt_people row plus an active team scope, which get_teams_for_coach reads. */
    private function coachOf( int $user_id, int $team_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Team',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
    }

    private function player( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => 1, 'first_name' => $first, 'last_name' => $last, 'team_id' => $this->team, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A match activity with an execution; returns the execution id. */
    private function match( string $date, int $ours, int $theirs ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => 1,
            'team_id'           => $this->team,
            'title'             => 'Match ' . $date,
            'session_date'      => $date,
            'activity_type_key' => 'game',
            'opponent'          => 'VV Opponent',
            'home_away'         => 'home',
            'home_score'        => $ours,
            'away_score'        => $theirs,
        ] );
        $activity_id = (int) $wpdb->insert_id;

        $prep_id = ( new MatchPrepRepository() )->ensureForActivity( $activity_id, 35 );
        $repo    = new MatchExecutionRepository();
        $exec_id = $repo->ensureForActivity( $activity_id, $prep_id );
        $repo->update( $exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );
        return $exec_id;
    }

    private function goal( int $exec_id, int $scorer, ?int $assist = null ): void {
        ( new MatchExecutionRepository() )->logGoalEvent( $exec_id, wp_generate_uuid4(), $scorer, 1, 10, 'home', $assist, false );
    }
}
