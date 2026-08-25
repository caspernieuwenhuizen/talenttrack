<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #2849 — the per-player squad queries behind two standard reports must
 * actually run.
 *
 * Both selected `p.name` from `tt_players`, which has no such column — it
 * carries `first_name` and `last_name`. MySQL rejected the statement,
 * `get_results()` returned null, and every caller coerced that to an empty
 * array. So the reports showed an empty squad on every install, and the
 * pilot reported it as "1 wedstrijd vastgelegd, 0 spelers in selectie".
 *
 * It survived #2339, #2433 and #2833 because each of those tested the KPI
 * *counts*, which are computed by different queries that never touch
 * `tt_players`. Nothing asserted that the rows beneath them existed. These
 * tests do, by running the statements and failing on a database error.
 */
final class ReportSquadQueriesTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->hide_errors();
    }

    /**
     * Team · Minutes distribution: a team with one played match and one
     * player's recorded minutes has a squad of one, with a readable name.
     */
    public function test_minutes_distribution_squad_query_returns_the_player(): void {
        global $wpdb;

        $team_id   = $this->insertTeam( 'U14 squad' );
        $player_id = $this->insertPlayer( $team_id, 'Sven', 'Bakker' );
        $match_id  = $this->insertMatch( $team_id, '2026-03-14' );
        $this->insertAttendance( $match_id, $player_id, 70 );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id AS player_id,
                    CONCAT( p.first_name, ' ', p.last_name ) AS name,
                    p.jersey_number,
                    COALESCE( SUM( m.match_minutes ), 0 ) AS total_minutes
               FROM (
                    SELECT att.player_id,
                           att.activity_id,
                           SUM( COALESCE( att.minutes_override, att.minutes_played, 0 ) ) AS match_minutes
                      FROM {$this->p}tt_attendance att
                      JOIN {$this->p}tt_activities a ON a.id = att.activity_id
                           AND a.team_id = %d
                           AND a.club_id = %d
                     WHERE att.record_type = 'actual'
                       AND att.is_guest = 0
                     GROUP BY att.player_id, att.activity_id
                  ) m
               JOIN {$this->p}tt_players p ON p.id = m.player_id AND p.archived_at IS NULL
              GROUP BY p.id, p.first_name, p.last_name, p.jersey_number",
            $team_id, $this->club
        ) );

        $this->assertSame( '', (string) $wpdb->last_error, 'the squad query must execute without a database error' );
        $this->assertIsArray( $rows );
        $this->assertCount( 1, $rows, 'a player with recorded minutes is in the squad' );
        $this->assertSame( 'Sven Bakker', (string) $rows[0]->name );
        $this->assertSame( 70, (int) $rows[0]->total_minutes );
    }

    /**
     * The column itself: an explicit assertion, so the next person to reach
     * for `p.name` gets told why it is not there rather than an empty report.
     */
    public function test_players_table_has_no_name_column(): void {
        global $wpdb;

        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$this->p}tt_players" );

        $this->assertNotContains( 'name', $columns, 'tt_players has never had a `name` column' );
        $this->assertContains( 'first_name', $columns );
        $this->assertContains( 'last_name', $columns );
    }

    // ── seed helpers ────────────────────────────────────────────────

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first,
            'last_name'  => $last,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMatch( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => $this->club,
            'team_id'           => $team_id,
            'title'             => 'Match ' . $date,
            'session_date'      => $date,
            'activity_type_key' => 'match',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, int $minutes ): void {
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
