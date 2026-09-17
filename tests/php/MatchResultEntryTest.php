<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Reports\MatchResultQuery;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * #3530 (epic #3529) — recording a match result without the live match sheet.
 *
 * Covers the four things that would each be a silent data bug:
 *
 *   1. An empty score saves as NULL, never 0. A match nobody recorded a
 *      result for is not a goalless draw, and #3519's team record counts on
 *      being able to tell the two apart.
 *   2. The write is partial. Saving one side must not blank the other, and
 *      saving the result must not blank the title or the date the way posting
 *      two fields at the full activity-update route would.
 *   3. A match the live sheet owns refuses the write. Its scoreline is derived
 *      from the goal log (#2857), so a second writer would be overwritten by
 *      the next goal event without anyone being told.
 *   4. `recentResultsForTeam()` frames the result as ours-then-theirs whatever
 *      the venue. It used to swap the pair on `home_away = 'away'`, which is
 *      latent only because nothing populated that column — and this issue adds
 *      the control that populates it.
 */
final class MatchResultEntryTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb; $wpdb->hide_errors();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        global $wp_rest_server; $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'id' => 1, 'club_id' => 1, 'name' => 'Test team' ] );
    }

    public function tear_down(): void {
        global $wp_rest_server; $wp_rest_server = null;
        parent::tear_down();
    }

    /** A fixture, created the way the activity form creates one. */
    private function createFixture( string $title = 'Result fixture', string $type = 'game' ): int {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/activities' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [
            'title'             => $title,
            'session_date'      => '2026-09-12',
            'team_id'           => 1,
            'activity_type_key' => $type,
            'opponent'          => 'VVV JO14-1',
            'home_away'         => 'away',
        ] ) );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status(), 'fixture created' );

        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_activities WHERE title = %s ORDER BY id DESC LIMIT 1",
            $title
        ) );
    }

    /** @param array<string,mixed> $body */
    private function putResult( int $activity_id, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $activity_id . '/result' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    /** @return array{0:?int,1:?int} */
    private function storedScores( int $activity_id ): array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT home_score, away_score FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $activity_id
        ) );
        return [
            $row->home_score !== null ? (int) $row->home_score : null,
            $row->away_score !== null ? (int) $row->away_score : null,
        ];
    }

    // ---- the fixture fields the form now writes -------------------

    public function test_activity_form_persists_opponent_and_venue(): void {
        $id = $this->createFixture( 'Fixture facts' );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT opponent, home_away FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $id
        ) );
        $this->assertSame( 'VVV JO14-1', (string) $row->opponent );
        $this->assertSame( 'away', (string) $row->home_away );
    }

    public function test_non_fixture_type_nulls_the_fixture_fields(): void {
        // A training carries no opponent even if a client posts one: the rows
        // are hidden on the form for non-match types, so a value arriving here
        // is a stale payload or a probe.
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/activities' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [
            'title'             => 'A training',
            'session_date'      => '2026-09-12',
            'team_id'           => 1,
            'activity_type_key' => 'training',
            'opponent'          => 'VVV JO14-1',
            'home_away'         => 'away',
        ] ) );
        $this->assertSame( 200, rest_do_request( $req )->get_status() );

        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT opponent, home_away FROM {$wpdb->prefix}tt_activities WHERE title = 'A training' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNull( $row->opponent );
        $this->assertNull( $row->home_away );
    }

    // ---- the scoreline --------------------------------------------

    public function test_result_saves_and_reads_back(): void {
        $id  = $this->createFixture();
        $res = $this->putResult( $id, [ 'home_score' => 3, 'away_score' => 1 ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( [ 3, 1 ], $this->storedScores( $id ) );

        $result = ( new MatchResultQuery() )->forActivity( $id );
        $this->assertNotNull( $result );
        $this->assertSame( 3, $result['our_score'] );
        $this->assertSame( 1, $result['their_score'] );
        $this->assertTrue( $result['has_score'] );
        $this->assertFalse( $result['owned_by_execution'] );
    }

    public function test_empty_score_clears_to_null_not_zero(): void {
        $id = $this->createFixture();
        $this->putResult( $id, [ 'home_score' => 3, 'away_score' => 1 ] );

        // An emptied number box posts '' — which means "no result recorded",
        // not "it finished 0-0".
        $res = $this->putResult( $id, [ 'home_score' => '', 'away_score' => '' ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( [ null, null ], $this->storedScores( $id ) );
        $this->assertFalse( ( new MatchResultQuery() )->forActivity( $id )['has_score'] );
    }

    public function test_write_is_partial_and_leaves_the_other_side_alone(): void {
        $id = $this->createFixture();
        $this->putResult( $id, [ 'home_score' => 3, 'away_score' => 1 ] );

        $this->putResult( $id, [ 'home_score' => 4 ] );

        $this->assertSame( [ 4, 1 ], $this->storedScores( $id ), 'away_score untouched by a home-only write' );
    }

    public function test_saving_the_result_does_not_disturb_the_rest_of_the_row(): void {
        $id = $this->createFixture( 'Keeps its title' );
        $this->putResult( $id, [ 'home_score' => 2, 'away_score' => 2 ] );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT title, opponent, home_away, team_id FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $id
        ) );
        $this->assertSame( 'Keeps its title', (string) $row->title );
        $this->assertSame( 'VVV JO14-1', (string) $row->opponent );
        $this->assertSame( 'away', (string) $row->home_away );
        $this->assertSame( 1, (int) $row->team_id );
    }

    public function test_score_is_capped_rather_than_refused(): void {
        // A coach holding a key down should get a number, not an error dialog
        // — the same ruling `put_contributions()` takes on goals.
        $id = $this->createFixture();
        $this->putResult( $id, [ 'home_score' => 400, 'away_score' => 0 ] );

        $this->assertSame( [ 99, 0 ], $this->storedScores( $id ) );
    }

    public function test_a_training_has_no_result_to_record(): void {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/activities' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [
            'title'             => 'Not a fixture',
            'session_date'      => '2026-09-12',
            'team_id'           => 1,
            'activity_type_key' => 'training',
        ] ) );
        rest_do_request( $req );

        global $wpdb;
        $id = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}tt_activities WHERE title = 'Not a fixture' ORDER BY id DESC LIMIT 1"
        );

        $this->assertNull( ( new MatchResultQuery() )->forActivity( $id ) );
        $this->assertSame( 404, $this->putResult( $id, [ 'home_score' => 1 ] )->get_status() );
    }

    public function test_a_tournament_is_not_a_single_fixture(): void {
        // #2686 — a tournament is a multi-game day and one score line cannot
        // describe it. #3532 owns the per-fixture case.
        $id = $this->createFixture( 'A tournament day', 'tournament' );

        $this->assertNull( ( new MatchResultQuery() )->forActivity( $id ) );
        $this->assertSame( 404, $this->putResult( $id, [ 'home_score' => 1 ] )->get_status() );
    }

    public function test_execution_owned_match_refuses_a_typed_score(): void {
        $id = $this->createFixture( 'Run on the live sheet' );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_match_execution', [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => 1,
            'activity_id'   => $id,
            'match_prep_id' => 0,
            'state'         => 'pending_review',
            'home_score'    => 1,
            'away_score'    => 1,
        ] );

        $result = ( new MatchResultQuery() )->forActivity( $id );
        $this->assertTrue( $result['owned_by_execution'] );

        $res = $this->putResult( $id, [ 'home_score' => 9, 'away_score' => 0 ] );
        $this->assertSame( 409, $res->get_status(), 'the goal log owns this scoreline' );
        $this->assertSame( [ null, null ], $this->storedScores( $id ), 'nothing was written' );
    }

    // ---- the venue-swap regression --------------------------------

    public function test_away_result_is_not_inverted_on_the_team_form_line(): void {
        // `home_score` is what WE scored, whatever the venue — that is what
        // the end-of-match copy writes. `recentResultsForTeam()` used to swap
        // the pair when `home_away = 'away'`, so a 1-3 defeat would have read
        // as a 3-1 win on the player's My-team page the moment the activity
        // form started populating that column.
        $id = $this->createFixture( 'Away defeat' );
        $this->putResult( $id, [ 'home_score' => 1, 'away_score' => 3 ] );

        $rows = ( new ActivitiesRepository() )->recentResultsForTeam( 1, 5 );
        $row  = null;
        foreach ( $rows as $r ) {
            if ( (int) $r->id === $id ) { $row = $r; break; }
        }

        $this->assertNotNull( $row, 'the away fixture is in the recent results' );
        $this->assertSame( 'away', (string) $row->home_away, 'the fixture really is an away one' );
        $this->assertSame( 1, (int) $row->team_score, 'we scored one' );
        $this->assertSame( 3, (int) $row->opp_score, 'they scored three' );
        $this->assertSame( 'L', (string) $row->outcome, 'which is a defeat, not a win' );
    }
}
