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
        $this->withPhoto( $this->player( $this->team_a, 'Singlecount', 'Only', 60 ) );
        // Every result has a photo in the media store, so the photo lookup
        // is counted too.
        for ( $i = 0; $i < 40; $i++ ) $this->withPhoto( $this->player( $this->team_a, 'Manycount', 'Number' . $i, 61 + $i ) );

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
            $this->assertSame( 40, $many['photos'], "{$persona}: every result carries its gated photo URL" );
        }
    }

    /**
     * The statement count does not grow with the size of the viewer's
     * scope either: a scout linked to one player and a scout linked to
     * twenty run the same number of statements, for players and for
     * activities.
     */
    public function test_the_statement_count_does_not_grow_with_a_scouts_links(): void {
        $linked = [];
        for ( $i = 0; $i < 20; $i++ ) $linked[] = $this->player( $this->team_a, 'Scoutlink', 'Number' . $i, 70 + $i );
        $few  = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $many = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        update_user_meta( $few, 'tt_scout_player_ids', (string) wp_json_encode( [ $linked[0] ] ) );
        update_user_meta( $many, 'tt_scout_player_ids', (string) wp_json_encode( $linked ) );

        foreach ( [ [ 'player', 'scoutlink number0' ], [ 'activity', 'searchside' ] ] as [ $kind, $q ] ) {
            foreach ( [ $few, $many ] as $uid ) {
                wp_set_current_user( $uid );
                $this->statementsFor( $uid, $q, $kind );
            }
            $this->resetSearchCaches();
            wp_set_current_user( $few );
            $one = $this->statementsFor( $few, $q, $kind );
            $this->resetSearchCaches();
            wp_set_current_user( $many );
            $twenty = $this->statementsFor( $many, $q, $kind );
            $this->assertSame( 1, $one['found'], "{$kind}: Precondition: the scout with one link finds one" );
            $this->assertSame( 1, $twenty['found'], "{$kind}: Precondition: the scout with twenty links finds the same one" );
            $this->assertSame( $one['queries'], $twenty['queries'], "{$kind}: the statement count grew with the scout's links" );
        }
    }

    /**
     * The p95 above runs on folded rows. Until the sweep has folded a row,
     * search matches its raw columns; this run times that branch, with
     * every row of the fixture unfolded.
     */
    public function test_p95_on_unfolded_rows(): void {
        $this->seedAcademy( 2000, 20000, 40, false );
        $uid = $this->personas['admin'];
        wp_set_current_user( $uid );
        ( new SearchService() )->page( new SearchQuery( 'warm' ), [], 1, 20, $uid );
        $times = [];
        $log   = [];
        foreach ( [ 'jan', 'de', 'training', 'o14', 'zaterdag', '#7', 'perf', 'sportpark', 'match', 'zzqq' ] as $q ) {
            $start   = microtime( true );
            ( new SearchService() )->page( new SearchQuery( $q ), [], 1, 20, $uid );
            $ms      = ( microtime( true ) - $start ) * 1000;
            $times[] = $ms;
            $log[]   = sprintf( '%s %.0f', $q, $ms );
        }
        sort( $times );
        $p95 = $times[ (int) floor( 0.95 * ( count( $times ) - 1 ) ) ];
        fwrite( STDERR, sprintf( "\nsearch p95 on unfolded rows: %.1f ms (%s)\n", $p95, implode( ', ', $log ) ) );
        $this->assertLessThan( 300.0, $p95, sprintf( 'p95 %.1f ms on unfolded rows (%s)', $p95, implode( ', ', $log ) ) );
    }

    private function withPhoto( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_media", [
            'club_id' => (int) CurrentClub::id(), 'uuid' => wp_generate_uuid4(), 'kind' => 'image',
            'storage_key' => 'tests/' . $player_id . '.jpg', 'mime_type' => 'image/jpeg',
        ] );
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'photo_media_id' => (int) $wpdb->insert_id, 'photo_url' => '' ], [ 'id' => $player_id ] );
        return $player_id;
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

    /** @return array{queries:int, found:int, photos:int} */
    private function statementsFor( int $uid, string $q, string $kind = 'player' ): array {
        global $wpdb;
        $before = $wpdb->num_queries;
        $data   = ( new SearchService() )->page( new SearchQuery( $q ), [ $kind ], 1, 50, $uid );
        $photos = 0;
        foreach ( $data['items'] as $item ) {
            // The gated delivery URL carries the REST nonce; the legacy
            // column held a bare uploads path.
            if ( strpos( (string) ( $item['photo_url'] ?? '' ), '_wpnonce=' ) !== false ) $photos++;
        }
        return [ 'queries' => $wpdb->num_queries - $before, 'found' => count( $data['items'] ), 'photos' => $photos ];
    }

    private function seedAcademy( int $players, int $activities, int $teams, bool $folded = true ): void {
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
        if ( ! $folded ) {
            // As after an upgrade, before the sweep has come by.
            foreach ( [ 'tt_players', 'tt_activities', 'tt_teams', 'tt_people' ] as $table ) {
                $wpdb->query( $wpdb->prepare( "UPDATE {$p}{$table} SET search_title = NULL, search_text = NULL, search_v = NULL WHERE club_id = %d", $club ) ); /* search-text-ok: fixture unfolds on purpose */
            }
        }
    }
}
