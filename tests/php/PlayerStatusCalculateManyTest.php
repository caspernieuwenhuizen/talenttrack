<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\PlayerStatus\PlayerStatusCalculator;
use TT\Infrastructure\PlayerStatus\StatusVerdict;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;

/**
 * #4945 — `PlayerStatusCalculator::calculateMany()` is `calculate()` for a
 * squad: the same verdict for every player, for every status the rule
 * produces, with one statement per input instead of one per player.
 *
 * The fixture has two teams. Team A's age group has a configured
 * methodology; team B falls back to the shipped default. So the
 * methodology a player resolves to is part of what is held equal.
 */
final class PlayerStatusCalculateManyTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team_a = 0;
    private int $team_b = 0;
    private string $today = '';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $this->club  = (int) CurrentClub::id();
        $this->today = gmdate( 'Y-m-d' );

        $this->team_a = $this->team( 'Status U15', 'U15' );
        $this->team_b = $this->team( 'Status U17', 'U17' );

        // Team A's age group has its own methodology: ratings and
        // attendance only, a higher amber line.
        // The seed may carry the age group already; use its row, the one the
        // resolver finds.
        $age_group = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(id) FROM {$wpdb->prefix}tt_lookups WHERE lookup_type = 'age_group' AND name = %s AND club_id = %d",
            'U15', $this->club
        ) );
        if ( $age_group <= 0 ) {
            $wpdb->insert( "{$wpdb->prefix}tt_lookups", [ 'club_id' => $this->club, 'lookup_type' => 'age_group', 'name' => 'U15' ] );
            $age_group = (int) $wpdb->insert_id;
        }
        $wpdb->insert( "{$wpdb->prefix}tt_player_status_methodology", [
            'club_id' => $this->club, 'age_group_id' => $age_group,
            'config_json' => (string) wp_json_encode( [
                'inputs'      => [
                    'ratings'    => [ 'enabled' => true, 'weight' => 70 ],
                    'behaviour'  => [ 'enabled' => false, 'weight' => 0 ],
                    'attendance' => [ 'enabled' => true, 'weight' => 30 ],
                    'potential'  => [ 'enabled' => false, 'weight' => 0 ],
                ],
                'thresholds'  => [ 'amber_below' => 70, 'red_below' => 40 ],
                'floor_rules' => [ 'behaviour_floor_below' => 0 ],
            ] ),
        ] );
    }

    public function test_calculate_many_equals_calculate_for_every_status(): void {
        $players = [];
        foreach ( [ $this->team_a, $this->team_b ] as $team ) {
            $players[] = $this->player( $team, 9, 9, 3, PotentialBand::EXCEPTIONAL ); // green
            $players[] = $this->player( $team, 4, 8, 0, null );                        // amber
            $players[] = $this->player( $team, 1, 5, 0, null );                        // red
            $players[] = $this->player( $team, 9, 6, 0, null );                        // behaviour floor: green capped at amber
            $players[] = $this->player( $team, null, null, 0, null );                  // unknown
            $players[] = $this->player( $team, null, null, 0, PotentialBand::ON_TRACK ); // partial
            $players[] = $this->player( $team, 7, null, 1, null );                     // sparse attendance
        }

        $calc = new PlayerStatusCalculator();
        $many = $calc->calculateMany( $players, $this->today );
        $this->assertSame( $players, array_keys( $many ), 'every player, in the order asked' );

        $colours = [];
        foreach ( $players as $pid ) {
            $one = $calc->calculate( $pid, $this->today );
            $this->assertEquals( $one->toArray(), $many[ $pid ]->toArray(), "player {$pid}" );
            $colours[ $one->color ] = true;
        }
        foreach ( [ StatusVerdict::COLOR_GREEN, StatusVerdict::COLOR_AMBER, StatusVerdict::COLOR_RED, StatusVerdict::COLOR_UNKNOWN ] as $colour ) {
            $this->assertArrayHasKey( $colour, $colours, "the fixture produces {$colour}; it produced " . implode( ', ', array_map( static fn( int $p ): string => $many[ $p ]->color . ':' . (string) $many[ $p ]->score . ':' . (string) wp_json_encode( array_column( $many[ $p ]->inputs, 'score' ) ), $players ) ) );
        }

        // The two teams resolve to different methodologies.
        $this->assertNotSame( $many[ $players[0] ]->methodology_version, $many[ $players[7] ]->methodology_version );
        $this->assertSame( 'shipped', $many[ $players[7] ]->methodology_version );
    }

    public function test_a_passed_methodology_applies_to_every_player(): void {
        $players = [ $this->player( $this->team_a, 6, 6, 2, null ), $this->player( $this->team_b, 8, 5, 3, PotentialBand::AHEAD ) ];
        $method  = [
            'version_id'  => 'test',
            'inputs'      => [ 'ratings' => [ 'enabled' => true, 'weight' => 100 ] ],
            'thresholds'  => [ 'amber_below' => 75, 'red_below' => 50 ],
            'floor_rules' => [ 'behaviour_floor_below' => 0 ],
        ];
        $calc = new PlayerStatusCalculator();
        $many = $calc->calculateMany( $players, $this->today, $method );
        foreach ( $players as $pid ) {
            $this->assertEquals( $calc->calculate( $pid, $this->today, $method )->toArray(), $many[ $pid ]->toArray() );
            $this->assertSame( 'test', $many[ $pid ]->methodology_version );
        }
    }

    public function test_the_squad_verdicts_run_the_same_statements_for_five_players_and_for_twenty_five(): void {
        $five = $this->verdictStatements( 5 );
        $this->moveSquadAway();
        $twenty_five = $this->verdictStatements( 25 );
        $this->assertSame( $five, $twenty_five, "verdictsAt() ran {$five} statements for 5 players and {$twenty_five} for 25" );
    }

    // ---- statements -----------------------------------------------------------

    private function verdictStatements( int $players ): int {
        global $wpdb;
        for ( $i = 0; $i < $players; $i++ ) {
            $this->player( $this->team_a, 6 + ( $i % 4 ), 7 + ( $i % 3 ), 2 + ( $i % 2 ), $i % 2 ? PotentialBand::ON_TRACK : null );
        }
        wp_cache_flush();
        $ctx = new TeamReportContext( $this->team_a, gmdate( 'Y-m-d', strtotime( '-30 days' ) ), $this->today, 0 );
        $this->assertCount( $players, $ctx->players(), 'Precondition: the squad' );

        $start    = (int) $wpdb->num_queries;
        $verdicts = $ctx->verdictsAt( $this->today );
        $used     = (int) $wpdb->num_queries - $start;
        $this->assertCount( $players, $verdicts );
        return $used;
    }

    /** A fresh squad on team A for the second measurement. */
    private function moveSquadAway(): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'team_id' => $this->team_b ], [ 'team_id' => $this->team_a ] );
    }

    // ---- fixtures -------------------------------------------------------------

    private function team( string $name, string $age_group ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name, 'age_group' => $age_group ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * A player with an evaluation rating, a behaviour rating, a number of
     * completed activities attended, and a potential band, each optional.
     */
    private function player( int $team, ?int $rating, ?int $behaviour, int $attended, ?string $band ): int {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Status', 'last_name' => 'Player',
            'status' => 'active', 'wp_user_id' => null,
        ] );
        $pid = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $pid, 'fixture: player inserted' );

        if ( $rating !== null ) {
            $wpdb->insert( "{$p}tt_evaluations", [
                'club_id' => $this->club, 'player_id' => $pid, 'coach_id' => 1,
                'eval_date' => gmdate( 'Y-m-d', strtotime( '-10 days' ) ),
            ] );
            $wpdb->insert( "{$p}tt_eval_ratings", [ 'club_id' => $this->club, 'evaluation_id' => (int) $wpdb->insert_id, 'category_id' => 1, 'rating' => $rating ] );
        }
        if ( $behaviour !== null ) {
            $wpdb->insert( "{$p}tt_player_behaviour_ratings", [
                'club_id' => $this->club, 'player_id' => $pid, 'rated_by' => 1, 'rating' => $behaviour,
                'rated_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-5 days' ) ),
            ] );
        }
        for ( $i = 0; $i < $attended; $i++ ) {
            $wpdb->insert( "{$p}tt_activities", [
                'club_id' => $this->club, 'team_id' => $team, 'title' => 'Training ' . $pid . '-' . $i,
                'session_date' => gmdate( 'Y-m-d', strtotime( '-' . ( 3 + $i ) . ' days' ) ),
                'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
            ] );
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id' => $this->club, 'activity_id' => (int) $wpdb->insert_id, 'player_id' => $pid,
                'status' => 'present', 'record_type' => 'actual',
            ] );
        }
        if ( $band !== null ) {
            $wpdb->insert( "{$p}tt_player_potential", [
                'club_id' => $this->club, 'player_id' => $pid, 'set_by' => 0, 'potential_band' => $band,
                'set_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-5 days' ) ),
            ] );
        }
        return $pid;
    }
}
