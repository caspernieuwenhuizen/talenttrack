<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoMode;
use TT\Modules\DemoData\Generators\TrialCaseGenerator;

/**
 * #3592 — the demo's open trial cases belong to players who are on trial.
 *
 * They were opened on the first two roster players: established squad
 * members with a shirt number, a join date two years back and a regular's
 * minutes, so the trial case, the profile and the minutes reports
 * contradicted each other.
 */
final class DemoTrialistsTest extends WP_UnitTestCase {

    /** @var object[] */
    private array $roster = [];

    /** @var object[] */
    private array $teams = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'demo-' . uniqid(), 'name' => 'Standard' ] );

        foreach ( [ 'U11', 'U13' ] as $age ) {
            $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Demo ' . $age, 'age_group' => $age ] );
            $team_id       = (int) $wpdb->insert_id;
            $this->teams[] = (object) [ 'id' => $team_id, 'age_group' => $age ];

            for ( $i = 1; $i <= 6; $i++ ) {
                $wpdb->insert( "{$p}tt_players", [
                    'club_id' => $club, 'team_id' => $team_id, 'first_name' => 'Vaste', 'last_name' => "Speler {$i}",
                    'status' => 'active', 'jersey_number' => $i, 'date_joined' => '2024-01-03',
                ] );
                $this->roster[] = (object) [ 'id' => (int) $wpdb->insert_id, 'team_id' => $team_id, 'date_joined' => '2024-01-03' ];
            }
        }

        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );
        ( new TrialCaseGenerator( new DemoBatchRegistry( 'test-batch-3592' ), $this->roster, $this->teams, [ 'hjo' => $admin ], 'en_US' ) )->generate();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_open_cases_sit_on_trialists_who_joined_when_the_trial_started(): void {
        $open = $this->cases( true );
        $this->assertCount( 2, $open );

        $roster_ids = array_map( static fn( $p ): int => (int) $p->id, $this->roster );
        foreach ( $open as $case ) {
            $this->assertNotContains( (int) $case['player_id'], $roster_ids, 'not an established roster player' );

            [ $player, $status ] = $this->get( 'players/' . (int) $case['player_id'] );
            $this->assertSame( 200, $status );
            $this->assertSame( 'trial', $player['data']['status'] );
            $this->assertNull( $player['data']['jersey_number'] );
            $this->assertGreaterThanOrEqual( $case['start_date'], (string) $player['data']['date_joined'] );

            [ $share, $status ] = $this->get( 'teams/' . (int) $player['data']['team_id'] . '/minutes-share/' . (int) $case['player_id'] );
            $this->assertTrue( $status === 404 || (int) ( $share['data']['minutes'] ?? -1 ) === 0, 'no minutes from before the trial' );
        }
    }

    public function test_the_trialists_prospects_are_in_the_trial_group(): void {
        global $wpdb;
        foreach ( $this->cases( true ) as $case ) {
            $prospect = $wpdb->get_row( $wpdb->prepare(
                "SELECT promoted_to_player_id, promoted_to_trial_case_id FROM {$wpdb->prefix}tt_prospects WHERE promoted_to_trial_case_id = %d",
                (int) $case['id']
            ), ARRAY_A );
            $this->assertNotNull( $prospect );
            $this->assertSame( (int) $case['player_id'], (int) $prospect['promoted_to_player_id'] );
        }
    }

    public function test_historical_cases_stay_on_roster_players_and_end_before_they_joined(): void {
        $closed = $this->cases( false );
        $this->assertNotEmpty( $closed );

        $roster_ids = array_map( static fn( $p ): int => (int) $p->id, $this->roster );
        foreach ( $closed as $case ) {
            $this->assertContains( (int) $case['player_id'], $roster_ids );
            $this->assertLessThan( '2024-01-03', (string) $case['end_date'] );
        }
    }

    /** @return list<array<string,mixed>> */
    private function cases( bool $open ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, player_id, start_date, end_date, decision FROM {$wpdb->prefix}tt_trial_cases WHERE archived_at IS NULL",
            ARRAY_A
        );
        return array_values( array_filter(
            (array) $rows,
            static fn( array $r ): bool => $open ? empty( $r['decision'] ) : ! empty( $r['decision'] )
        ) );
    }

    /**
     * The generated rows are demo-tagged, and reads are demo-scoped: with
     * demo mode off, tagged rows are exactly what they hide.
     *
     * @return array{0:array<string,mixed>,1:int}
     */
    private function get( string $route ): array {
        DemoMode::overrideForRequest( DemoMode::ON );
        try {
            $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/' . $route ) );
        } finally {
            DemoMode::clearOverride();
        }
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
