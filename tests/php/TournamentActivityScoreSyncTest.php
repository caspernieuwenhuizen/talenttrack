<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Reports\MinutesGridQuery;

/**
 * #4021 — one fixture, one scoreline, and no home leg to speak of.
 *
 * Two bugs, both on the read side of a completed tournament fixture. Its
 * activity's `home_score` / `away_score` were never written, so a 3-2 read as
 * no result recorded on every minutes surface — while the minutes grid offered
 * its own editable pair of boxes for the same fixture, so one match had two
 * independent score stores. And the activity was created with no `home_away`,
 * which `MinutesGridQuery` read as "not away, therefore home": every tournament
 * fixture was framed as a home game.
 *
 * `tt_tournament_matches` is the single store. The activity's pair is derived
 * from it on completion and on a later score correction, the grid shows it
 * read-only, and the fixture is framed as neither home nor away.
 */
final class TournamentActivityScoreSyncTest extends WP_UnitTestCase {

    private const TEAM_ID = 4021;

    private int $tournament_id = 0;
    private int $match_id      = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( $wpdb->prefix . 'tt_tournaments', [
            'club_id'    => 1,
            'team_id'    => self::TEAM_ID,
            'name'       => 'Score sync cup',
            'start_date' => '2026-10-31',
        ] );
        $this->tournament_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_tournament_matches', [
            'club_id'              => 1,
            'tournament_id'        => $this->tournament_id,
            'sequence'             => 1,
            'opponent_name'        => 'De Treffers',
            'duration_min'         => 20,
            'substitution_windows' => '[]',
            'our_score'            => 3,
            'their_score'          => 2,
        ] );
        $this->match_id = (int) $wpdb->insert_id;

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @param array<string,mixed> $body */
    private function dispatch( string $method, string $route, array $body = [] ): \WP_REST_Response {
        $req = new WP_REST_Request( $method, $route );
        foreach ( $body as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return rest_get_server()->dispatch( $req );
    }

    private function matchRoute( string $suffix = '' ): string {
        return '/talenttrack/v1/tournaments/' . $this->tournament_id
            . '/matches/' . $this->match_id . $suffix;
    }

    private function fixtureActivityId(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT activity_id FROM {$wpdb->prefix}tt_tournament_matches WHERE id = %d",
            $this->match_id
        ) );
    }

    /** @return array<string,mixed> */
    private function activityRow( int $activity_id ): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT home_score, away_score, home_away, activity_source_key, activity_status_key
               FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $activity_id
        ), ARRAY_A );
    }

    public function test_completing_a_scored_fixture_writes_the_scoreline_onto_its_activity(): void {
        $res = $this->dispatch( 'POST', $this->matchRoute( '/complete' ) );
        $this->assertSame( 200, $res->get_status() );

        $activity_id = $this->fixtureActivityId();
        $this->assertGreaterThan( 0, $activity_id );

        $row = $this->activityRow( $activity_id );
        $this->assertSame( '3', (string) $row['home_score'], 'our goals' );
        $this->assertSame( '2', (string) $row['away_score'], 'theirs' );
        $this->assertSame( 'completed', (string) $row['activity_status_key'] );
    }

    /**
     * No home/away column exists on a tournament fixture and none is invented:
     * the storage convention is ours-then-theirs and the read side frames it as
     * neither (#3529 decision 3).
     */
    public function test_the_fixture_activity_is_left_without_a_home_away(): void {
        $this->dispatch( 'POST', $this->matchRoute( '/complete' ) );

        $row = $this->activityRow( $this->fixtureActivityId() );
        $this->assertNull( $row['home_away'] );
        $this->assertSame( 'tournament', (string) $row['activity_source_key'] );
    }

    public function test_a_score_corrected_after_completion_follows_to_the_activity(): void {
        $this->dispatch( 'POST', $this->matchRoute( '/complete' ) );
        $activity_id = $this->fixtureActivityId();

        $res = $this->dispatch( 'PATCH', $this->matchRoute(), [ 'our_score' => 4 ] );
        $this->assertSame( 200, $res->get_status() );

        $row = $this->activityRow( $activity_id );
        $this->assertSame( '4', (string) $row['home_score'] );
        $this->assertSame( '2', (string) $row['away_score'], 'the other side is left alone' );
    }

    public function test_clearing_the_score_clears_it_on_the_activity_too(): void {
        $this->dispatch( 'POST', $this->matchRoute( '/complete' ) );
        $activity_id = $this->fixtureActivityId();

        $this->dispatch( 'PATCH', $this->matchRoute(), [ 'our_score' => '', 'their_score' => '' ] );

        $row = $this->activityRow( $activity_id );
        $this->assertNull( $row['home_score'], 'an emptied box is "no result recorded", not 0' );
        $this->assertNull( $row['away_score'] );
    }

    public function test_kicking_off_a_fixture_that_already_has_a_score_carries_it_over(): void {
        $res = $this->dispatch( 'POST', $this->matchRoute( '/kickoff' ) );
        $this->assertSame( 200, $res->get_status() );

        $row = $this->activityRow( $this->fixtureActivityId() );
        $this->assertSame( '3', (string) $row['home_score'] );
        $this->assertSame( '2', (string) $row['away_score'] );
    }

    /**
     * The demo install has three of these: a score typed in weeks before
     * anybody kicks the fixture off. There is nothing to sync it to, the score
     * is already safe on the fixture, and the write must not invent an activity
     * to hold it.
     */
    public function test_a_scored_fixture_with_no_activity_is_left_alone(): void {
        $res = $this->dispatch( 'PATCH', $this->matchRoute(), [ 'our_score' => 1, 'their_score' => 1 ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 0, $this->fixtureActivityId(), 'no activity is conjured up' );
    }

    // ── the read side ─────────────────────────────────────────────────

    public function test_the_minutes_grid_frames_a_tournament_fixture_as_neither_home_nor_away(): void {
        $this->dispatch( 'POST', $this->matchRoute( '/complete' ) );

        $column = $this->gridColumn( $this->fixtureActivityId() );

        $this->assertTrue( $column['is_neutral'] );
        $this->assertNull( $column['is_home'], 'a fixture at a tournament has no home leg' );
        $this->assertSame( 3, $column['home_score'] );
        $this->assertSame( 2, $column['away_score'] );
    }

    /**
     * The NULL-to-home fallback on an ordinary match was unpinned:
     * `MinutesGridScoreRowsTest` always seeds an explicit `home_away`. A league
     * match with none still reads as home, which is what the rest of the
     * plugin assumes — the change is scoped to tournament fixtures.
     */
    public function test_an_ordinary_match_with_no_home_away_still_reads_as_home(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'team_id'           => self::TEAM_ID,
            'session_date'      => '2026-10-31',
            'title'             => 'League match',
            'activity_type_key' => 'match',
            'home_score'        => 1,
            'away_score'        => 0,
        ] );
        $activity_id = (int) $wpdb->insert_id;

        $column = $this->gridColumn( $activity_id );

        $this->assertTrue( $column['is_home'] );
        $this->assertFalse( $column['is_neutral'] );
    }

    /** @return array<string,mixed> */
    private function gridColumn( int $activity_id ): array {
        $matrix = ( new MinutesGridQuery() )->matrix( self::TEAM_ID, '2026-10-01', '2026-11-30' );
        foreach ( $matrix['activities'] as $column ) {
            if ( (int) $column['activity_id'] === $activity_id ) return $column;
        }
        $this->fail( "activity {$activity_id} is not a column in the grid" );
    }
}
