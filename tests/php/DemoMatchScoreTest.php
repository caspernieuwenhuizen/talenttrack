<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoMode;
use TT\Modules\DemoData\Generators\MatchDayGenerator;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;

/**
 * #3579 — a generated match's result has to land on the activity.
 *
 * The team record, the form line and the match result card read
 * `tt_activities.home_score` / `away_score`; the live product copies the
 * execution's score there when a match is finished. The generator wrote the
 * score onto the execution only, so every demo team showed 0 played and its
 * matches "without a score" beside a list of goalscorers.
 */
final class DemoMatchScoreTest extends WP_UnitTestCase {

    private int $team_id = 0;

    /** @var object[] */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Demo O11', 'age_group' => 'JO11' ] );
        $this->team_id = (int) $wpdb->insert_id;

        for ( $i = 0; $i < 16; $i++ ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id'    => $club,
                'team_id'    => $this->team_id,
                'first_name' => 'Demo',
                'last_name'  => "Speler {$i}",
                'status'     => 'active',
            ] );
            $this->players[] = (object) [ 'id' => (int) $wpdb->insert_id, 'team_id' => $this->team_id ];
        }
    }

    public function test_a_past_fixture_carries_its_executions_score(): void {
        $past   = $this->fixture( gmdate( 'Y-m-d', strtotime( '-7 days' ) ) );
        $future = $this->fixture( gmdate( 'Y-m-d', strtotime( '+7 days' ) ) );
        $this->generate();

        $execution = ( new MatchExecutionRepository() )->findByActivity( $past );
        $this->assertNotNull( $execution, 'the past fixture was played' );
        $exec = (array) $execution;

        $activity = $this->activity( $past );
        $this->assertNotNull( $activity['home_score'] );
        $this->assertNotNull( $activity['away_score'] );
        $this->assertSame( (int) $exec['home_score'], (int) $activity['home_score'] );
        $this->assertSame( (int) $exec['away_score'], (int) $activity['away_score'] );

        $upcoming = $this->activity( $future );
        $this->assertNull( $upcoming['home_score'], 'a fixture that has not been played has no score' );
        $this->assertNull( $upcoming['away_score'] );
    }

    public function test_the_team_record_counts_the_match_as_played(): void {
        $date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
        $this->fixture( $date );
        $this->generate();

        // The fixture is demo-tagged, and the stats read is demo-scoped:
        // with demo mode off, tagged rows are exactly what it hides.
        DemoMode::overrideForRequest( DemoMode::ON );
        try {
            $stats = ( new TeamMatchStatsQuery() )->forTeam( $this->team_id, [
                'from' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
                'to'   => gmdate( 'Y-m-d' ),
            ] );
        } finally {
            DemoMode::clearOverride();
        }

        $this->assertSame( 1, (int) $stats['record']['played'] );
        $this->assertSame( 0, (int) $stats['record']['without_a_score'] );
        $this->assertNotEmpty( $stats['form'] );
    }

    private function fixture( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => (int) CurrentClub::id(),
            'team_id'             => $this->team_id,
            'title'               => 'Wedstrijd ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'game',
            'activity_status_key' => strtotime( $date ) > time() ? 'planned' : 'completed',
            'plan_state'          => strtotime( $date ) > time() ? 'scheduled' : 'completed',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->registry()->tag( 'activity', $id );
        return $id;
    }

    private function generate(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        global $wpdb;
        $team = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_teams WHERE id = %d", $this->team_id ) );

        ( new MatchDayGenerator( $this->registry(), $this->players, [ $team ], [ 'hjo' => $admin ], 'en_US' ) )->generate();
    }

    /** @return array<string,mixed> */
    private function activity( int $id ): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT home_score, away_score FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    private function registry(): DemoBatchRegistry {
        return new DemoBatchRegistry( 'test-batch-3579' );
    }
}
