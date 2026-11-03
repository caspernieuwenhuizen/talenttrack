<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4671 — search stays fast at academy scale, and its statement count does
 * not grow with the number of results.
 *
 * The fixture holds 2,000 players and 20,000 activities over 40 teams. The
 * p95 of a paged search over every kind stays under 300 ms for a global
 * reader and for a coach.
 */
final class SearchPerformanceTest extends WP_UnitTestCase {

    use SearchFixtures;

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
    }

    public function tear_down(): void {
        $this->searchTearDown();
        parent::tear_down();
    }

    public function test_the_statement_count_does_not_grow_with_the_results(): void {
        $this->player( $this->team_a, 'Singlecount', 'Only', 60 );
        for ( $i = 0; $i < 40; $i++ ) $this->player( $this->team_a, 'Manycount', 'Number' . $i, 61 + $i );

        foreach ( [ 'coach', 'admin' ] as $persona ) {
            $uid = $this->personas[ $persona ];
            wp_set_current_user( $uid );
            // One run warms what a request resolves once whatever it finds
            // (the dashboard page, the viewer's own player); then both are
            // measured with the authorization caches emptied, so a decision
            // cached by one run cannot hide a per-row statement in the other.
            $this->statementsFor( $uid, 'manycount' );
            $this->resetSearchCaches();
            $one  = $this->statementsFor( $uid, 'singlecount' );
            $this->resetSearchCaches();
            $many = $this->statementsFor( $uid, 'manycount' );
            $this->assertSame( 1, $one['found'], "{$persona}: Precondition: one result" );
            $this->assertSame( 40, $many['found'], "{$persona}: Precondition: forty results" );
            $this->assertSame( $one['queries'], $many['queries'], "{$persona}: the statement count grew with the results" );
        }
    }

    public function test_p95_under_300ms_with_2000_players_and_20000_activities(): void {
        $this->seedAcademy( 2000, 20000, 40 );
        foreach ( [ 'admin', 'coach' ] as $persona ) {
            $uid = $this->personas[ $persona ];
            wp_set_current_user( $uid );
            // One cold run builds the registries a request reads once (the
            // help topics, the settings, the tiles); the timed runs follow.
            ( new SearchService() )->page( new SearchQuery( 'warm' ), [], 1, 20, $uid );
            $times = [];
            $log   = [];
            foreach ( [ 'jan', 'de', 'training', 'o14', 'zaterdag', '#7', 'perf', 'sportpark', 'match', 'zzqq' ] as $q ) {
                for ( $i = 0; $i < 2; $i++ ) {
                    $start   = microtime( true );
                    ( new SearchService() )->page( new SearchQuery( $q ), [], 1, 20, $uid );
                    $ms      = ( microtime( true ) - $start ) * 1000;
                    $times[] = $ms;
                    $log[]   = sprintf( '%s %.0f', $q, $ms );
                }
            }
            sort( $times );
            $p95 = $times[ (int) floor( 0.95 * ( count( $times ) - 1 ) ) ];
            $this->assertLessThan( 300.0, $p95, sprintf( '%s: p95 %.1f ms (%s)', $persona, $p95, implode( ', ', $log ) ) );
        }
    }

    /** @return array{queries:int, found:int} */
    private function statementsFor( int $uid, string $q ): array {
        global $wpdb;
        $before = $wpdb->num_queries;
        $data   = ( new SearchService() )->page( new SearchQuery( $q ), [ 'player' ], 1, 50, $uid );
        return [ 'queries' => $wpdb->num_queries - $before, 'found' => count( $data['items'] ) ];
    }

    private function seedAcademy( int $players, int $activities, int $teams ): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $team_ids = [ $this->team_a ];
        for ( $t = 1; $t < $teams; $t++ ) $team_ids[] = $this->team( 'Perf team ' . $t, 'O' . ( 8 + $t % 12 ) );

        $first = [ 'Jan', 'Daan', 'Sem', 'Lucas', 'Finn', 'Noah', 'Bram', 'Levi', 'Mees', 'Jesse' ];
        $last  = [ 'de Vries', 'Jansen', 'van Dijk', 'Bakker', 'Visser', 'Smit', 'Meijer', 'de Boer', 'Mulder', 'Perfman' ];
        $rows  = [];
        for ( $i = 0; $i < $players; $i++ ) {
            $fn     = $first[ $i % 10 ];
            $ln     = $last[ intdiv( $i, 10 ) % 10 ];
            $rows[] = $wpdb->prepare( '(%d,%d,%s,%s,%d,%s,%s,%d,NULL)', $club, $team_ids[ $i % $teams ], $fn, $ln, $i % 30, 'active', \TT\Infrastructure\Search\SearchText::fold( $fn . ' ' . $ln ), 1 );
            if ( count( $rows ) === 500 || $i === $players - 1 ) {
                $wpdb->query( "INSERT INTO {$p}tt_players (club_id, team_id, first_name, last_name, jersey_number, status, search_title, search_v, wp_user_id) VALUES " . implode( ',', $rows ) ); /* search-text-ok: fixture writes the fold itself */
                $rows = [];
            }
        }
        $titles = [ 'Training', 'Match', 'Perf session', 'Keeper training', 'Tournament' ];
        for ( $i = 0; $i < $activities; $i++ ) {
            $title  = $titles[ $i % 5 ] . ' ' . ( $i % 97 );
            $date   = gmdate( 'Y-m-d', strtotime( '2024-01-01' ) + ( $i % 900 ) * DAY_IN_SECONDS );
            $rows[] = $wpdb->prepare( '(%d,%d,%s,%s,%s,%s,%s,%s,%d)', $club, $team_ids[ $i % $teams ], $title, $date, 'Sportpark', $i % 5 === 1 ? 'match' : 'training', \TT\Infrastructure\Search\SearchText::fold( $title ), ' sportpark', 1 );
            if ( count( $rows ) === 500 || $i === $activities - 1 ) {
                $wpdb->query( "INSERT INTO {$p}tt_activities (club_id, team_id, title, session_date, location, activity_type_key, search_title, search_text, search_v) VALUES " . implode( ',', $rows ) ); /* search-text-ok: fixture writes the fold itself */
                $rows = [];
            }
        }
    }
}
