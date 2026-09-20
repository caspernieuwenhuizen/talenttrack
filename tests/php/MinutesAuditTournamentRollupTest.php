<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;

/**
 * #3857 — a tournament day is a roll-up of its fixtures, not a match
 * somebody forgot to record.
 *
 * The day carries no attendance of its own: the play happens in its
 * fixtures, each promoted to its own activity on kickoff. The audit read
 * the day as a game with an empty squad and printed `none` — "go and
 * record this" — over what is usually the largest block of minutes in the
 * month, while its editor answered 200 with `players: []`, no minutes
 * field and no way to add anybody. The row could never leave `none`.
 *
 * Minutes have one home (#2686): the fixtures. The day now shows what they
 * hold, says it is a roll-up, and refuses to be edited.
 */
final class MinutesAuditTournamentRollupTest extends WP_UnitTestCase {

    private const FROM = '2026-09-01';
    private const TO   = '2026-09-30';

    private string $p = '';
    private int $club = 0;
    private int $team = 0;
    private int $tournament = 0;
    private int $day_activity = 0;
    private int $fixture_activity = 0;
    private int $match_activity = 0;
    private int $player_a = 0;
    private int $player_b = 0;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->team     = $this->insertTeam( 'Rollup U12' );
        $this->player_a = $this->insertPlayer( 'Aad' );
        $this->player_b = $this->insertPlayer( 'Bert' );

        // The tournament: a day activity linked to it, plus one fixture that
        // was kicked off and therefore has an activity of its own carrying
        // the recorded minutes.
        $this->tournament       = $this->insertTournament( '2026-09-05' );
        $this->day_activity     = $this->insertActivity( 'tournament', '2026-09-05', 'Toernooidag U12', $this->tournament );
        $this->fixture_activity = $this->insertActivity( 'match', '2026-09-05', 'Fixture 1', null );
        $this->insertFixture( $this->fixture_activity );
        $this->insertMinutes( $this->fixture_activity, $this->player_a, 30 );
        $this->insertMinutes( $this->fixture_activity, $this->player_b, 20 );

        // An ordinary league match in the same window, to prove it is
        // untouched by any of this.
        $this->match_activity = $this->insertActivity( 'match', '2026-09-12', 'League match', null );
        $this->insertMinutes( $this->match_activity, $this->player_a, 60 );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_tournament_day_carries_its_fixtures_minutes(): void {
        $row = $this->rowFor( $this->day_activity );

        $this->assertSame( 50, (int) $row['total_minutes'], 'the day should sum its fixture minutes' );
        $this->assertSame( 30, (int) $row['minutes'][ $this->player_a ] );
        $this->assertSame( 20, (int) $row['minutes'][ $this->player_b ] );
        $this->assertTrue( (bool) $row['on_squad'][ $this->player_a ], 'a player who played a fixture is on the day' );
    }

    public function test_the_tournament_day_is_marked_as_a_read_only_rollup(): void {
        $row = $this->rowFor( $this->day_activity );

        $this->assertTrue( (bool) $row['is_rollup'] );
        $this->assertFalse( (bool) $row['editable'], 'the day must not offer an editor' );
        $this->assertSame( $this->tournament, (int) $row['tournament_id'], 'the row points at the planner' );
    }

    public function test_the_status_comes_from_the_fixtures_not_from_the_empty_day(): void {
        $row = $this->rowFor( $this->day_activity );

        $this->assertNotSame( 'none', (string) $row['status'], 'the fixtures have minutes, so the day is not unrecorded' );
    }

    /** A day whose fixtures hold nothing is still honestly `none`. */
    public function test_a_day_with_no_recorded_fixtures_is_none(): void {
        $tournament = $this->insertTournament( '2026-09-19' );
        $day        = $this->insertActivity( 'tournament', '2026-09-19', 'Quiet tournament', $tournament );

        $row = $this->rowFor( $day );

        $this->assertSame( 'none', (string) $row['status'] );
        $this->assertSame( 0, (int) $row['total_minutes'] );
        $this->assertFalse( (bool) $row['editable'], 'an empty day is still not editable' );
    }

    /**
     * The roll-up shows minutes its fixtures already contribute, so counting
     * it again would tell a coach a player played twice the game time.
     */
    public function test_the_rollup_is_left_out_of_the_column_and_grand_totals(): void {
        $matrix = $this->matrix();

        // 30 + 20 from the fixture, 60 from the league match — the day's 50
        // are the fixture's own, counted once.
        $this->assertSame( 110, (int) $matrix['grand_total'] );
        $this->assertSame( 90, (int) $matrix['column_totals'][ $this->player_a ] );
        $this->assertSame( 20, (int) $matrix['column_totals'][ $this->player_b ] );
    }

    public function test_the_summary_counts_rollups_separately_from_games(): void {
        $summary = $this->matrix()['summary'];

        $this->assertSame( 2, (int) $summary['total_games'], 'the fixture and the league match are the games' );
        $this->assertSame( 1, (int) $summary['rollups'] );
        $this->assertSame( 0, (int) $summary['none'], 'the day no longer inflates the not-recorded count' );
    }

    public function test_an_ordinary_match_is_unchanged(): void {
        $row = $this->rowFor( $this->match_activity );

        $this->assertFalse( (bool) $row['is_rollup'] );
        $this->assertTrue( (bool) $row['editable'] );
        $this->assertSame( 60, (int) $row['total_minutes'] );
        $this->assertSame( 0, (int) $row['tournament_id'] );
    }

    public function test_the_row_reports_the_tournament_type_rather_than_an_empty_subtype(): void {
        $this->assertSame( 'tournament', (string) $this->rowFor( $this->day_activity )['type_key'] );
    }

    /* ---- the editor ----------------------------------------------------- */

    public function test_the_editor_refuses_a_tournament_day_and_names_the_planner(): void {
        $response = $this->editor( $this->day_activity );

        $this->assertSame( 409, $response->get_status(), 'the editor still opens on a tournament day' );

        $data  = (array) $response->get_data();
        $error = (array) ( ( (array) ( $data['errors'] ?? [] ) )[0] ?? [] );
        $this->assertSame( 'minutes_recorded_per_fixture', $error['code'] ?? '' );

        $details = (array) ( $error['details'] ?? [] );
        $this->assertSame( $this->tournament, (int) ( $details['tournament_id'] ?? 0 ), 'the refusal does not say where the minutes live' );
        $this->assertSame( 'tournament', (string) ( $details['type_key'] ?? '' ), 'the refusal reports an empty type' );
    }

    public function test_the_editor_still_opens_on_an_ordinary_match(): void {
        $this->assertSame( 200, $this->editor( $this->match_activity )->get_status() );
    }

    public function test_the_editor_query_returns_null_for_a_tournament_day(): void {
        $query = new MinutesAuditQuery();

        $this->assertNull( $query->editorRows( $this->day_activity ), 'the editor read model still answers for a day' );
        $this->assertNotNull( $query->editorRows( $this->match_activity ) );
        $this->assertNull( $query->tournamentDay( $this->match_activity ), 'a match is not a tournament day' );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return array<string,mixed> */
    private function matrix(): array {
        return ( new MinutesAuditQuery() )->matrix( $this->team, self::FROM, self::TO );
    }

    /** @return array<string,mixed> */
    private function rowFor( int $activity_id ): array {
        foreach ( (array) $this->matrix()['games'] as $row ) {
            if ( (int) ( (array) $row )['activity_id'] === $activity_id ) return (array) $row;
        }
        $this->fail( "activity {$activity_id} has no row in the matrix" );
    }

    private function editor( int $activity_id ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', "/talenttrack/v1/reports/minutes-audit/{$activity_id}/editor" );
        return rest_do_request( $req );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => $first,
            'last_name'  => 'Speler',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertTournament( string $start ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_tournaments", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'name'       => 'Toernooi ' . $start,
            'start_date' => $start,
            'uuid'       => wp_generate_uuid4(),
            'created_by' => 0,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( string $type, string $date, string $title, ?int $tournament_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'tournament_id'       => $tournament_id,
            'title'               => $title,
            'session_date'        => $date,
            'activity_type_key'   => $type,
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A fixture of the tournament, already kicked off onto its own activity. */
    private function insertFixture( int $activity_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_tournament_matches", [
            'club_id'       => $this->club,
            'tournament_id' => $this->tournament,
            'sequence'      => 1,
            'label'         => 'Fixture 1',
            'duration_min'  => 25,
            // JSON NOT NULL on the column — an empty array is one period.
            'substitution_windows' => '[]',
            'activity_id'   => $activity_id,
        ] );
    }

    private function insertMinutes( int $activity_id, int $player_id, int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => $minutes,
        ] );
    }
}
