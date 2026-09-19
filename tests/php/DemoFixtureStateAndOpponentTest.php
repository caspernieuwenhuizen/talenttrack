<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoMode;
use TT\Modules\DemoData\DemoRoster;
use TT\Modules\DemoData\Generators\ActivityGenerator;
use TT\Modules\DemoData\Generators\MatchDayGenerator;
use TT\Modules\DemoData\SeedLoader;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;

/**
 * #3664 — a generated past match is a played, reviewed match against
 * somebody.
 *
 * The generator stored executions in the `'finished'` state #1033 retired,
 * which no list, filter or pill recognises, so every demo match read "Not
 * started" beside its final score. And no generated fixture carried an
 * opponent or a venue, so every one of them listed "—".
 */
final class DemoFixtureStateAndOpponentTest extends WP_UnitTestCase {

    /** Tuesday 2026-09-15 00:00 UTC, as DemoActivityScheduleTest pins it. */
    private const NOW = 1789430400;

    private int $team_id = 0;

    public function test_a_played_fixture_is_finalized(): void {
        global $wpdb;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Demo O11', 'age_group' => 'JO11' ] );
        $team_id = (int) $wpdb->insert_id;

        $players = [];
        for ( $i = 0; $i < 16; $i++ ) {
            $wpdb->insert( "{$wpdb->prefix}tt_players", [
                'club_id'    => $club,
                'team_id'    => $team_id,
                'first_name' => 'Demo',
                'last_name'  => "Speler {$i}",
                'status'     => 'active',
            ] );
            $players[] = (object) [ 'id' => (int) $wpdb->insert_id, 'team_id' => $team_id ];
        }

        $registry = new DemoBatchRegistry( 'test-batch-3664' );
        $past     = $this->fixture( $registry, $team_id, gmdate( 'Y-m-d', strtotime( '-7 days' ) ) );
        $future   = $this->fixture( $registry, $team_id, gmdate( 'Y-m-d', strtotime( '+7 days' ) ) );

        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_teams WHERE id = %d", $team_id ) );
        ( new MatchDayGenerator( $registry, $players, [ $team ], [ 'hjo' => $admin ], 'en_US' ) )->generate();

        $repo      = new MatchExecutionRepository();
        $execution = $repo->findByActivity( $past );
        $this->assertNotNull( $execution, 'the past fixture was played' );
        $state = (string) ( (array) $execution )['state'];
        $this->assertSame( MatchExecutionState::FINALIZED, $state );
        $this->assertContains( $state, MatchExecutionState::ALL, 'a state the list, filters and pills recognise' );

        $this->assertNull( $repo->findByActivity( $future ), 'a fixture ahead stops at prep' );

        $retired = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_match_execution WHERE state = %s",
            MatchExecutionState::FINISHED
        ) );
        $this->assertSame( 0, $retired, 'nothing is written in the retired state' );
    }

    public function test_generated_games_have_an_opponent_and_a_venue(): void {
        global $wpdb;
        $this->generateActivities();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_type_key, opponent, home_away, location
               FROM {$wpdb->prefix}tt_activities
              WHERE team_id = %d AND activity_source_key = 'generated'
              ORDER BY session_date ASC, id ASC",
            $this->team_id
        ), ARRAY_A );

        $this->assertNotEmpty( $rows );
        $opponents = SeedLoader::opponents();
        $venues    = [];
        foreach ( (array) $rows as $row ) {
            if ( (string) $row['activity_type_key'] !== 'game' ) {
                $this->assertNull( $row['opponent'], 'a training has no opponent' );
                $this->assertNull( $row['home_away'] );
                continue;
            }
            $opponent = (string) $row['opponent'];
            $this->assertNotSame( '', $opponent );
            $this->assertContains( $opponent, $opponents );
            $this->assertContains( (string) $row['home_away'], [ 'home', 'away' ] );
            if ( $row['home_away'] === 'away' ) {
                $this->assertStringContainsString( $opponent, (string) $row['location'], 'an away game is played at the opponent' );
            } else {
                $this->assertSame( 'Home pitch', (string) $row['location'] );
            }
            $venues[] = (string) $row['home_away'];
        }

        $this->assertGreaterThanOrEqual( 2, count( $venues ) );
        $this->assertSame( 'home', $venues[0], 'the season opens at home' );
        $this->assertSame( 'away', $venues[1], 'and alternates' );
    }

    public function test_the_rest_list_returns_the_opponent(): void {
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->generateActivities();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( [
            'per_page' => 100,
            'filter'   => [ 'team_id' => $this->team_id, 'date_from' => '2026-07-01', 'date_to' => '2026-10-31' ],
        ] );
        DemoMode::overrideForRequest( DemoMode::ON );
        try {
            $res = rest_do_request( $req );
        } finally {
            DemoMode::clearOverride();
        }
        $this->assertSame( 200, $res->get_status() );

        $rows  = $res->get_data()['data']['rows'] ?? [];
        $games = array_values( array_filter( $rows, static fn( $r ): bool => ( $r['activity_type_key'] ?? '' ) === 'game' ) );
        $this->assertNotEmpty( $games );
        foreach ( $games as $game ) {
            $this->assertNotEmpty( $game['opponent'] );
            $this->assertContains( $game['home_away'], [ 'home', 'away' ] );
        }

        $wp_rest_server = null;
        wp_set_current_user( 0 );
    }

    private function generateActivities(): void {
        global $wpdb;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Opponent U14', 'age_group' => 'U14' ] );
        $this->team_id = (int) $wpdb->insert_id;

        for ( $i = 1; $i <= 4; $i++ ) {
            $wpdb->insert( "{$wpdb->prefix}tt_players", [
                'club_id'     => $club,
                'first_name'  => 'Opponent',
                'last_name'   => 'Player ' . $i,
                'team_id'     => $this->team_id,
                'date_joined' => '2024-08-01',
                'wp_user_id'  => null,
            ] );
        }

        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_teams WHERE id = %d",
            $this->team_id
        ) );
        foreach ( $teams as $team ) {
            $team->head_coach_user_id = 0;
        }
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE team_id = %d",
            $this->team_id
        ) );

        $calendar = new DemoCalendar( 8, self::NOW );
        ( new ActivityGenerator(
            new DemoBatchRegistry( 'test-3664' ),
            $teams,
            $players,
            8,
            'en_US',
            $calendar,
            new DemoRoster( $calendar, $teams, $players )
        ) )->generate();
    }

    private function fixture( DemoBatchRegistry $registry, int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => (int) CurrentClub::id(),
            'team_id'             => $team_id,
            'title'               => 'Wedstrijd ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'game',
            'activity_status_key' => strtotime( $date ) > time() ? 'planned' : 'completed',
            'plan_state'          => strtotime( $date ) > time() ? 'scheduled' : 'completed',
        ] );
        $id = (int) $wpdb->insert_id;
        $registry->tag( 'activity', $id );
        return $id;
    }
}
