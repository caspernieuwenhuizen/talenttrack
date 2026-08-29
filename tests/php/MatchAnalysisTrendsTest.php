<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisTrends;

/**
 * #2725 — the three decisions that make this report honest are the ones
 * worth pinning: it counts rather than averages, an unrated section counts
 * as nothing, and below the floor there is no trend.
 */
final class MatchAnalysisTrendsTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $team_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Trend squad' ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    public function test_counts_occurrences_per_phase(): void {
        $phase = MatchAnalysisEnums::ratedSectionKeys()[0];

        foreach ( [ '2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26' ] as $i => $date ) {
            $analysis = $this->analysis( $date );
            $this->section(
                $analysis,
                $phase,
                $i === 0 ? MatchAnalysisEnums::RATING_WENT_WELL : MatchAnalysisEnums::RATING_NEEDS_WORK
            );
        }

        $trends = ( new MatchAnalysisTrends() )->forTeams( [ $this->team_id ], '2026-01-01', '2026-02-01' );

        $this->assertSame( 4, $trends['rated_matches'] );
        $this->assertTrue( $trends['meets_floor'] );

        $row = $this->sectionRow( $trends['sections'], $phase );
        $this->assertSame( 4, $row['total'] );
        $this->assertSame( 3, $row['counts'][ MatchAnalysisEnums::RATING_NEEDS_WORK ] );
        $this->assertSame( 1, $row['counts'][ MatchAnalysisEnums::RATING_WENT_WELL ] );
        $this->assertSame( 0, $row['counts'][ MatchAnalysisEnums::RATING_MIXED ] );

        // No key anywhere in the payload holds a mean. Three ordered values
        // are not a number and turning them into one is the helpful
        // simplification this test exists to block.
        $this->assertArrayNotHasKey( 'average', $row );
        $this->assertArrayNotHasKey( 'score', $row );
    }

    public function test_an_unrated_section_counts_as_nothing(): void {
        $phase = MatchAnalysisEnums::ratedSectionKeys()[0];

        $this->section( $this->analysis( '2026-03-01' ), $phase, MatchAnalysisEnums::RATING_MIXED );
        $this->section( $this->analysis( '2026-03-08' ), $phase, null );
        $this->section( $this->analysis( '2026-03-15' ), $phase, '' );
        $this->section( $this->analysis( '2026-03-22' ), $phase, MatchAnalysisEnums::RATING_MIXED );
        $this->section( $this->analysis( '2026-03-29' ), $phase, MatchAnalysisEnums::RATING_MIXED );

        $trends = ( new MatchAnalysisTrends() )->forTeams( [ $this->team_id ], '2026-02-15', '2026-04-05' );

        $row = $this->sectionRow( $trends['sections'], $phase );
        $this->assertSame( 3, $row['total'],
            'the two unrated sections are out of the denominator, not neutral in it' );
        $this->assertSame( 3, $trends['rated_matches'] );
    }

    public function test_below_the_floor_there_is_no_trend(): void {
        $phase = MatchAnalysisEnums::ratedSectionKeys()[0];

        $this->section( $this->analysis( '2026-05-03' ), $phase, MatchAnalysisEnums::RATING_NEEDS_WORK );
        $this->section( $this->analysis( '2026-05-10' ), $phase, MatchAnalysisEnums::RATING_NEEDS_WORK );

        $trends = ( new MatchAnalysisTrends() )->forTeams( [ $this->team_id ], '2026-04-01', '2026-06-01' );

        $this->assertSame( 2, $trends['rated_matches'] );
        $this->assertFalse( $trends['meets_floor'] );
    }

    public function test_matches_outside_the_window_are_excluded(): void {
        $phase = MatchAnalysisEnums::ratedSectionKeys()[0];

        $this->section( $this->analysis( '2025-11-01' ), $phase, MatchAnalysisEnums::RATING_WENT_WELL );
        $this->section( $this->analysis( '2026-07-05' ), $phase, MatchAnalysisEnums::RATING_WENT_WELL );

        $trends = ( new MatchAnalysisTrends() )->forTeams( [ $this->team_id ], '2026-07-01', '2026-07-31' );

        $this->assertSame( 1, $trends['rated_matches'] );
    }

    public function test_player_markers_are_counted_and_tagged(): void {
        $tag = array_key_first( MatchAnalysisEnums::playerItemTags() );
        $player_id = $this->player();

        $this->playerItem( $this->analysis( '2026-08-02' ), $player_id, MatchAnalysisEnums::MARKER_BELOW_PAR, $tag );
        $this->playerItem( $this->analysis( '2026-08-09' ), $player_id, MatchAnalysisEnums::MARKER_BELOW_PAR, $tag );
        $this->playerItem( $this->analysis( '2026-08-16' ), $player_id, MatchAnalysisEnums::MARKER_STOOD_OUT, null );

        $trends = ( new MatchAnalysisTrends() )->forPlayer( $player_id, '2026-08-01', '2026-08-31' );

        $this->assertSame( 3, $trends['rated_matches'] );
        $this->assertTrue( $trends['meets_floor'] );
        $this->assertSame( 2, $trends['markers'][ MatchAnalysisEnums::MARKER_BELOW_PAR ] );
        $this->assertSame( 1, $trends['markers'][ MatchAnalysisEnums::MARKER_STOOD_OUT ] );

        $this->assertCount( 1, $trends['tags'],
            'the untagged item counts towards the markers and towards no phase' );
        $this->assertSame( $tag, $trends['tags'][0]['key'] );
        $this->assertSame( 2, $trends['tags'][0]['total'] );
    }

    /* ---- REST (#1388 endpoint-test mandate) ----------------------------- */

    public function test_rest_team_trends_returns_the_same_answer_as_the_report(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        do_action( 'rest_api_init' );

        $phase = MatchAnalysisEnums::ratedSectionKeys()[0];
        foreach ( [ '2026-09-06', '2026-09-13', '2026-09-20' ] as $date ) {
            $this->section( $this->analysis( $date ), $phase, MatchAnalysisEnums::RATING_MIXED );
        }

        $data = $this->rest( '/talenttrack/v1/match-analysis-trends/teams/' . $this->team_id, [
            'from' => '2026-09-01',
            'to'   => '2026-09-30',
        ] );

        $this->assertSame( 3, $data['rated_matches'] );
        $this->assertTrue( $data['meets_floor'] );
        $this->assertSame( MatchAnalysisTrends::MIN_RATED_MATCHES, $data['min_rated_matches'] );
    }

    public function test_rest_trends_are_closed_to_a_user_without_the_activities_cap(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        do_action( 'rest_api_init' );

        $request  = new \WP_REST_Request( 'GET', '/talenttrack/v1/match-analysis-trends/teams/' . $this->team_id );
        $response = rest_get_server()->dispatch( $request );

        $this->assertGreaterThanOrEqual( 400, $response->get_status() );
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int|string,mixed>
     */
    private function rest( string $route, array $params = [] ): array {
        $request = new \WP_REST_Request( 'GET', $route );
        foreach ( $params as $k => $v ) {
            $request->set_param( $k, $v );
        }
        $response = rest_get_server()->dispatch( $request );
        $this->assertLessThan( 300, $response->get_status(), "unexpected status for GET {$route}" );
        $data = $response->get_data();
        return is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : (array) $data;
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @param list<array{key:string,label:string,total:int,counts:array<string,int>}> $sections */
    private function sectionRow( array $sections, string $key ): array {
        foreach ( $sections as $section ) {
            if ( $section['key'] === $key ) return $section;
        }
        $this->fail( "no section row for {$key}" );
    }

    private function player(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team_id,
            'first_name' => 'Trend',
            'last_name'  => 'Player',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function analysis( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => $this->club,
            'team_id'           => $this->team_id,
            'title'             => 'Trend fixture ' . $date,
            'activity_type_key' => 'match',
            'session_date'      => $date,
        ] );
        $activity_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_match_analyses", [
            'club_id'     => $this->club,
            'uuid'        => wp_generate_uuid4(),
            'activity_id' => $activity_id,
            'status'      => MatchAnalysisEnums::STATUS_FINAL,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function section( int $analysis_id, string $key, ?string $rating ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_analysis_sections", [
            'club_id'     => $this->club,
            'analysis_id' => $analysis_id,
            'section_key' => $key,
            'rating'      => $rating,
        ] );
    }

    private function playerItem( int $analysis_id, int $player_id, string $marker, ?string $tag ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_analysis_players", [
            'club_id'       => $this->club,
            'analysis_id'   => $analysis_id,
            'player_id'     => $player_id,
            'marker'        => $marker,
            'team_function' => $tag,
        ] );
    }
}
