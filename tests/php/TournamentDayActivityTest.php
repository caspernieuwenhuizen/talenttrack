<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Tournaments\Services\TournamentDayActivity;

/**
 * #4031 — a tournament day is on the team's calendar while it is still being
 * planned, not from the moment somebody kicks a fixture off.
 *
 * Before this, nothing existed in `tt_activities` until kick-off, so a
 * tournament planned three weeks ahead was invisible to everybody working from
 * the activity list and there was no activity to register availability against.
 *
 * The **fixtures** keep an activity each, created on kick-off. That is #3857's
 * model — the day is a read-only roll-up of its fixtures' minutes — and it is
 * the only place a single fixture's score (#4021) or its confirmed per-player
 * minutes (#4032) can live. `test_kick_off_does_not_create_a_second_day` is the
 * regression that matters: one day, however many fixtures.
 */
final class TournamentDayActivityTest extends WP_UnitTestCase {

    private const TEAM_ID = 4031;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

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

    /** @return array<string,mixed> */
    private function createTournament( string $start = '2026-10-17' ): array {
        $res = $this->dispatch( 'POST', '/talenttrack/v1/tournaments', [
            'name'       => 'Autumn cup',
            'team_id'    => self::TEAM_ID,
            'start_date' => $start,
            'matches'    => [
                [ 'opponent_name' => 'De Treffers', 'duration_min' => 20, 'scheduled_at' => $start . ' 09:30:00' ],
                [ 'opponent_name' => 'Juliana',     'duration_min' => 20, 'scheduled_at' => $start . ' 10:15:00' ],
            ],
        ] );
        $this->assertSame( 200, $res->get_status() );
        $data = $res->get_data();
        return (array) ( $data['data'] ?? $data );
    }

    /** @return list<object> */
    private function dayActivities( int $tournament_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_activities
              WHERE tournament_id = %d AND activity_type_key = %s",
            $tournament_id, 'tournament'
        ) );
    }

    private function countTeamActivities(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities WHERE team_id = %d",
            self::TEAM_ID
        ) );
    }

    public function test_a_planned_tournament_is_on_the_team_calendar_before_kick_off(): void {
        $tournament = $this->createTournament();
        $days       = $this->dayActivities( (int) $tournament['id'] );

        $this->assertCount( 1, $days, 'the day, once' );
        $this->assertSame( self::TEAM_ID, (int) $days[0]->team_id );
        $this->assertSame( '2026-10-17', substr( (string) $days[0]->session_date, 0, 10 ) );
        $this->assertSame( 'Autumn cup', (string) $days[0]->title );
        $this->assertSame( 'planned', (string) $days[0]->activity_status_key );
        $this->assertSame( 'tournament', (string) $days[0]->activity_source_key );

        foreach ( $tournament['matches'] as $match ) {
            $this->assertNull( $match['activity_id'], 'a scheduled fixture has no activity of its own yet' );
        }
    }

    public function test_kick_off_does_not_create_a_second_day(): void {
        $tournament = $this->createTournament();
        $id         = (int) $tournament['id'];
        $match_id   = (int) $tournament['matches'][0]['id'];

        $before = $this->countTeamActivities();

        $res = $this->dispatch(
            'POST',
            '/talenttrack/v1/tournaments/' . $id . '/matches/' . $match_id . '/kickoff'
        );
        $this->assertSame( 200, $res->get_status() );

        $this->assertCount( 1, $this->dayActivities( $id ), 'still exactly one day' );

        // The fixture gets one of its own — #3857's model, and where its score
        // and its confirmed minutes live. So the team's activity count grows by
        // exactly one: the fixture, never a second day.
        $this->assertSame(
            $before + 1,
            $this->countTeamActivities(),
            'kick-off adds the fixture and nothing else'
        );

        $data     = $res->get_data();
        $body     = (array) ( $data['data'] ?? $data );
        $this->assertSame(
            (int) $this->dayActivities( $id )[0]->id,
            (int) $body['day_activity_id'],
            'the response names the day it reused'
        );
        $this->assertNotSame(
            (int) $body['day_activity_id'],
            (int) $body['activity_id'],
            'the fixture activity is not the day'
        );
    }

    public function test_kicking_off_twice_is_idempotent(): void {
        $tournament = $this->createTournament();
        $id         = (int) $tournament['id'];
        $match_id   = (int) $tournament['matches'][0]['id'];

        $this->dispatch( 'POST', '/talenttrack/v1/tournaments/' . $id . '/matches/' . $match_id . '/kickoff' );
        $after_first = $this->countTeamActivities();

        $res = $this->dispatch( 'POST', '/talenttrack/v1/tournaments/' . $id . '/matches/' . $match_id . '/kickoff' );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( $after_first, $this->countTeamActivities() );
    }

    public function test_the_day_moves_when_the_tournament_moves(): void {
        $tournament = $this->createTournament();
        $id         = (int) $tournament['id'];

        $res = $this->dispatch( 'PUT', '/talenttrack/v1/tournaments/' . $id, [
            'name'       => 'Autumn cup — rescheduled',
            'team_id'    => self::TEAM_ID,
            'start_date' => '2026-10-24',
        ] );
        $this->assertSame( 200, $res->get_status() );

        $days = $this->dayActivities( $id );
        $this->assertCount( 1, $days );
        $this->assertSame( '2026-10-24', substr( (string) $days[0]->session_date, 0, 10 ) );
        $this->assertSame( 'Autumn cup — rescheduled', (string) $days[0]->title );
    }

    /**
     * A tournament that predates this behaviour gets its day the next time
     * anybody touches it — no migration, and nothing to re-run by hand.
     */
    public function test_a_tournament_created_before_this_gets_its_day_when_a_fixture_is_added(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_tournaments', [
            'club_id' => 1, 'team_id' => self::TEAM_ID, 'name' => 'Legacy cup', 'start_date' => '2026-11-07',
        ] );
        $id = (int) $wpdb->insert_id;

        $this->assertSame( 0, TournamentDayActivity::idFor( $id ), 'nothing yet' );

        $res = $this->dispatch( 'POST', '/talenttrack/v1/tournaments/' . $id . '/matches', [
            'opponent_name' => 'Achilles',
            'duration_min'  => 20,
        ] );
        $this->assertSame( 200, $res->get_status() );

        $this->assertCount( 1, $this->dayActivities( $id ) );
    }

    public function test_a_tournament_needs_a_start_date(): void {
        $res = $this->dispatch( 'POST', '/talenttrack/v1/tournaments', [
            'name'    => 'Dateless',
            'team_id' => self::TEAM_ID,
        ] );

        $this->assertSame( 422, $res->get_status() );
        $data = $res->get_data();
        $this->assertSame( 'start_date_required', (string) ( $data['errors'][0]['code'] ?? '' ) );
    }
}
