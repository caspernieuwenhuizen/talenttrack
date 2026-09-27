<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;

/**
 * #4058 — the minutes audit only calls a game complete when the minutes add
 * up to a whole match.
 *
 * It used to count players with minutes and nothing else, so eleven players
 * with 30 minutes each of a 70-minute match wore the green Complete chip
 * while 440 player-minutes were never recorded. The audit is the screen a
 * head coach uses to decide there is nothing left to chase; a false green
 * there is worse than no check.
 */
final class MinutesAuditFullMatchTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── match length: one test per branch ──────────────────────────────

    public function test_the_activitys_own_match_length_wins(): void {
        $this->assertSame( 60, MinutesAuditQuery::resolveMatchLength( 60, 30, '10:00:00', '11:30:00' ) );
    }

    public function test_the_age_group_config_is_next_and_is_stored_per_half(): void {
        $this->assertSame( 50, MinutesAuditQuery::resolveMatchLength( 0, 25, '10:00:00', '11:30:00' ) );
    }

    public function test_the_scheduled_duration_is_next(): void {
        $this->assertSame( 90, MinutesAuditQuery::resolveMatchLength( 0, 0, '10:00:00', '11:30:00' ) );
    }

    public function test_with_nothing_set_the_global_default_still_gives_a_figure(): void {
        $this->assertSame( 70, MinutesAuditQuery::resolveMatchLength( 0, 0, '', '' ) );
        $this->assertSame( 70, MinutesAuditQuery::resolveMatchLength( 0, 0, '11:00:00', '10:00:00' ), 'an end before the start is no duration' );
    }

    public function test_available_minutes_is_players_a_side_times_length(): void {
        $this->assertSame( 770, MinutesAuditQuery::availableMinutes( 11, 70 ) );
        $this->assertSame( 400, MinutesAuditQuery::availableMinutes( 8, 50 ) );
    }

    // ── the verdict ────────────────────────────────────────────────────

    public function test_the_verdict_and_its_reason(): void {
        $this->assertSame( [ 'none', '' ], MinutesAuditQuery::completeness( 0, 11, 0, 770 ) );
        $this->assertSame( [ 'complete', '' ], MinutesAuditQuery::completeness( 11, 11, 770, 770 ) );
        $this->assertSame( [ 'partial', 'minutes_short' ], MinutesAuditQuery::completeness( 11, 11, 330, 770 ) );
        $this->assertSame( [ 'partial', 'minutes_over' ], MinutesAuditQuery::completeness( 12, 12, 780, 770 ) );
        $this->assertSame( [ 'partial', 'players_missing' ], MinutesAuditQuery::completeness( 10, 11, 770, 770 ) );
        $this->assertSame( [ 'complete', '' ], MinutesAuditQuery::completeness( 2, 2, 50, null ), 'a roll-up is judged on its players only' );
    }

    // ── the matrix ─────────────────────────────────────────────────────

    public function test_rows_carry_the_verdict_and_the_kpis_reconcile(): void {
        $team    = $this->insertTeam();
        $players = [];
        for ( $i = 1; $i <= 12; $i++ ) {
            $players[] = $this->insertPlayer( $team, $i );
        }
        $eleven = array_slice( $players, 0, 11 );

        $full  = $this->insertMatch( $team, '2026-03-01', 70 );
        $short = $this->insertMatch( $team, '2026-03-08', 70 );
        $over  = $this->insertMatch( $team, '2026-03-15', 70 );
        $none  = $this->insertMatch( $team, '2026-03-22', 70 );

        foreach ( $eleven as $pid ) {
            $this->insertMinutes( $full, $pid, 70 );
            $this->insertMinutes( $short, $pid, 30 );
            $this->insertMinutes( $over, $pid, 70 );
        }
        $this->insertMinutes( $over, $players[11], 10 );
        $this->insertSquadRow( $none, $players[0] );

        $matrix = ( new MinutesAuditQuery() )->matrix( $team, '2026-01-01', '2026-12-31' );
        $rows   = [];
        foreach ( $matrix['games'] as $g ) {
            $rows[ (int) $g['activity_id'] ] = $g;
        }

        $this->assertSame( 770, (int) $rows[ $full ]['available_minutes'], 'eleven a side over seventy minutes' );
        $this->assertSame( 'complete', $rows[ $full ]['status'] );

        $this->assertSame( 'partial', $rows[ $short ]['status'], 'every player recorded, but 330 of 770 is not a whole match' );
        $this->assertSame( 'minutes_short', $rows[ $short ]['status_reason'] );

        $this->assertSame( 'partial', $rows[ $over ]['status'], 'more than the match can hold' );
        $this->assertSame( 'minutes_over', $rows[ $over ]['status_reason'] );

        $this->assertSame( 'none', $rows[ $none ]['status'] );

        $summary = $matrix['summary'];
        $this->assertSame( 4, (int) $summary['total_games'] );
        $this->assertSame( 1, (int) $summary['complete'] );
        $this->assertSame( 2, (int) $summary['partial'] );
        $this->assertSame( 1, (int) $summary['none'] );
    }

    public function test_the_teams_football_form_sets_players_a_side(): void {
        global $wpdb;
        $team = $this->insertTeam();
        $wpdb->update( "{$this->p}tt_teams", [ 'football_form' => '8v8' ], [ 'id' => $team ] );
        $game = $this->insertMatch( $team, '2026-04-01', 50 );
        $this->insertMinutes( $game, $this->insertPlayer( $team, 1 ), 50 );

        $matrix = ( new MinutesAuditQuery() )->matrix( $team, '2026-01-01', '2026-12-31' );

        $this->assertSame( 400, (int) $matrix['games'][0]['available_minutes'] );
    }

    public function test_the_rest_route_carries_the_new_fields(): void {
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $team = $this->insertTeam();
        $game = $this->insertMatch( $team, '2026-05-01', 70 );
        $this->insertMinutes( $game, $this->insertPlayer( $team, 1 ), 70 );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/minutes-audit' );
        $req->set_param( 'team_id', $team );
        $req->set_param( 'from', '2026-01-01' );
        $req->set_param( 'to', '2026-12-31' );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status() );
        $data    = $res->get_data();
        $payload = is_array( $data ) && isset( $data['data'] ) ? $data['data'] : $data;
        $row     = $payload['games'][0];

        $this->assertSame( 770, (int) $row['available_minutes'] );
        $this->assertSame( 'minutes_short', (string) $row['status_reason'] );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Audit full match' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, int $n ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'first_name'    => 'Player',
            'last_name'     => 'No ' . $n,
            'jersey_number' => $n,
            'status'        => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMatch( int $team_id, string $date, int $length ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'              => $this->club,
            'team_id'              => $team_id,
            'title'                => 'Match ' . $date,
            'session_date'         => $date,
            'activity_type_key'    => 'match',
            'game_subtype_key'     => 'League',
            'activity_status_key'  => 'completed',
            'plan_state'           => 'completed',
            'match_length_minutes' => $length,
        ] );
        return (int) $wpdb->insert_id;
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

    private function insertSquadRow( int $activity_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => 'present',
            'is_guest'    => 0,
            'record_type' => 'expected',
        ] );
    }
}
