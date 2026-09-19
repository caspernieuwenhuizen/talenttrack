<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\Generators\MatchDayGenerator;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\Teams\FootballFormResolver;

/**
 * #3588 — the demo shares out playing time instead of starting the lowest
 * ids every match.
 *
 * The starting side was the first `squad_size` available players in roster
 * order, which is id order, so every demo minutes report showed minutes
 * falling as the id rose: a distribution the generator made by accident.
 */
final class DemoStarterRotationTest extends WP_UnitTestCase {

    private const FIXTURES = 10;

    private int $team_id = 0;
    private int $squad_size = 0;

    /** @var object[] */
    private array $players = [];

    /** @var list<int> */
    private array $fixtures = [];

    public function set_up(): void {
        parent::set_up();
        mt_srand( 3588 );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Demo O11', 'age_group' => 'JO11' ] );
        $this->team_id    = (int) $wpdb->insert_id;
        $this->squad_size = FootballFormResolver::squadSizeForAgeGroup( 'JO11' );

        for ( $i = 0; $i < 14; $i++ ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => $club, 'team_id' => $this->team_id,
                'first_name' => 'Demo', 'last_name' => "Speler {$i}", 'status' => 'active',
            ] );
            $this->players[] = (object) [ 'id' => (int) $wpdb->insert_id, 'team_id' => $this->team_id ];
        }

        $registry = new DemoBatchRegistry( 'test-batch-3588' );
        for ( $i = self::FIXTURES; $i >= 1; $i-- ) {
            $date = gmdate( 'Y-m-d', strtotime( '-' . ( $i * 7 ) . ' days' ) );
            $wpdb->insert( "{$p}tt_activities", [
                'club_id' => $club, 'team_id' => $this->team_id, 'title' => 'Wedstrijd ' . $date,
                'session_date' => $date, 'activity_type_key' => 'game',
                'activity_status_key' => 'completed', 'plan_state' => 'completed',
            ] );
            $activity_id = (int) $wpdb->insert_id;
            $registry->tag( 'activity', $activity_id );
            $this->fixtures[] = $activity_id;

            // The recorded register `ActivityGenerator` would have written,
            // which is where the generator puts the minutes.
            foreach ( $this->players as $player ) {
                $wpdb->insert( "{$p}tt_attendance", [
                    'club_id' => $club, 'activity_id' => $activity_id, 'player_id' => (int) $player->id,
                    'is_guest' => 0, 'status' => 'Present', 'record_type' => 'actual',
                ] );
            }
        }

        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}tt_teams WHERE id = %d", $this->team_id ) );
        ( new MatchDayGenerator( $registry, $this->players, [ $team ], [ 'hjo' => $admin ], 'en_US' ) )->generate();
    }

    public function tear_down(): void {
        mt_srand();
        parent::tear_down();
    }

    public function test_the_starting_side_rotates(): void {
        $repo    = new MatchPrepRepository();
        $started = [];
        foreach ( $this->fixtures as $activity_id ) {
            $prep = $repo->findByActivity( $activity_id );
            $this->assertNotNull( $prep );
            foreach ( $repo->listLineup( (int) $prep->id ) as $row ) {
                if ( (int) $row->half === 1 ) $started[ (int) $row->player_id ] = true;
            }
        }

        $this->assertGreaterThanOrEqual( $this->squad_size + 2, count( $started ) );
    }

    public function test_minutes_still_reconcile_per_match(): void {
        global $wpdb;
        $repo = new MatchPrepRepository();
        foreach ( $this->fixtures as $activity_id ) {
            $half  = (int) ( (array) $repo->findByActivity( $activity_id ) )['half_length_minutes'];
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(minutes_played), 0) FROM {$wpdb->prefix}tt_attendance
                  WHERE activity_id = %d AND record_type = 'actual' AND is_guest = 0",
                $activity_id
            ) );
            $this->assertSame( $this->squad_size * $half * 2, $total, "fixture {$activity_id}" );
        }
    }

    public function test_minutes_do_not_follow_the_player_id(): void {
        global $wpdb;
        $ids = array_map( static fn( $p ): int => (int) $p->id, $this->players );
        $in  = implode( ',', array_fill( 0, count( $this->fixtures ), '%d' ) );

        $minutes = array_fill_keys( $ids, 0 );
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT player_id, SUM(minutes_played) AS m FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id IN ({$in}) AND record_type = 'actual' GROUP BY player_id",
            ...$this->fixtures
        ) ) as $row ) {
            $minutes[ (int) $row->player_id ] = (int) $row->m;
        }

        arsort( $minutes );
        $top_five    = array_slice( array_keys( $minutes ), 0, 5 );
        $lowest_five = array_slice( $ids, 0, 5 );
        sort( $top_five );

        $this->assertNotSame( $lowest_five, $top_five, 'the five lowest ids are the five most-played' );
        $this->assertGreaterThan( 0, min( array_slice( $minutes, 0, count( $ids ) - 2, true ) ), 'most of the squad gets minutes' );
    }
}
