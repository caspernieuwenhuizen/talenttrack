<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;

/**
 * #4957 — every surface that says "played" counts the same matches.
 *
 * One team, one window: a won fixture with minutes, a played fixture with no
 * score and no minutes, a tournament day with minutes, a completed fixture
 * dated after today carrying minutes, and a fixture today not yet kicked off.
 * The record counts two played fixtures; every minutes figure counts those
 * plus the tournament day.
 */
final class PlayedMatchSurfacesTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $player = 0;
    private string $today = '';
    private string $from  = '';
    private string $to    = '';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->hide_errors();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Surfaces U15', 'age_group' => 'U15' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Surface', 'last_name' => 'Player', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->today = (string) $wpdb->get_var( 'SELECT CURDATE()' );
        $this->from  = $this->day( -30 );
        $this->to    = $this->day( 30 );

        $this->match( -10, 'game', [ 'activity_status_key' => 'completed', 'home_score' => 3, 'away_score' => 1 ], 70 );
        $this->match( -5, 'game', [], 0 );
        $this->match( -3, 'tournament', [ 'activity_status_key' => 'completed' ], 40 );
        $this->match( 7, 'game', [ 'activity_status_key' => 'completed' ], 60 );
        $this->match( 0, 'game', [], 0 );
    }

    public function test_every_played_figure_agrees(): void {
        $tab     = ( new TeamMatchStatsQuery() )->forTeam( $this->team, [ 'from' => $this->from, 'to' => $this->to ] )['record'];
        $report  = ( new TeamMonthlyReport() )->forTeam( $this->team, $this->from, $this->to, [ 'matches', 'minutes', 'quality' ] )['data'];
        $monthly = $report['matches']['record'];
        $minutes = $report['minutes'];
        $counts  = ( new MinutesQuery() )->matchCountsForTeam( $this->team, $this->from, $this->to );
        $share   = ( new MinutesShareQuery() )->forTeam( $this->team, $this->from, $this->to );

        // The record: the won fixture and the one without a score.
        $this->assertSame( 2, $tab['played'] );
        $this->assertSame( $tab['played'], $monthly['played'] );
        $this->assertSame( 1, $tab['without_a_score'] );
        $this->assertSame( $tab['without_a_score'], $monthly['without_score'] );
        $this->assertSame( 1, $tab['tournaments_excluded'] );
        $this->assertSame( [ 1, 0, 0 ], [ $tab['won'], $tab['drawn'], $tab['lost'] ] );

        // Every minutes figure: the record's played plus the tournament day.
        $played = $tab['played'] + $tab['tournaments_excluded'];
        $this->assertSame( 3, $played );
        $this->assertSame( $played, $counts['played'], 'matchCountsForTeam, which the Minutes distribution reads' );
        $this->assertSame( $played, $share['played_matches'], 'the minutes share' );
        $this->assertSame( $played, $minutes['matches_played'], 'the monthly minutes section' );
        $this->assertSame( 1, $minutes['tournaments_played'] );

        // Recorded: the won fixture and the tournament day. The minutes on the
        // fixture dated next week are not a match played.
        $this->assertSame( 2, $counts['recorded'] );
        $this->assertSame( $counts['recorded'], $minutes['matches_recorded'] );
        $this->assertSame( $counts['played'] - $counts['recorded'], $report['quality']['matches_without_minutes'] );
        $this->assertSame( 1, $report['quality']['matches_without_minutes'] );

        // The section lists the played fixtures only.
        $this->assertCount( 2, $report['matches']['fixtures'] );
    }

    public function test_the_minutes_line_names_the_tournament_days(): void {
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team, $this->from, $this->to, [ 'minutes' ] );
        $line   = TeamMonthlyReport::sectionMeta( TeamMonthlyReportBlock::MINUTES, $report['data']['minutes'] );
        $this->assertStringContainsString( 'Minutes recorded for 2 of 3 matches played.', $line );
        $this->assertStringContainsString( 'Including 1 tournament day.', $line );
    }

    private function day( int $offset ): string {
        return gmdate( 'Y-m-d', (int) strtotime( $this->today . ' ' . ( $offset >= 0 ? '+' : '' ) . $offset . ' days' ) );
    }

    /** @param array<string,mixed> $columns */
    private function match( int $offset, string $type, array $columns, int $minutes ): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $date = $this->day( $offset );

        $wpdb->insert( "{$p}tt_activities", $columns + [
            'club_id' => $club, 'team_id' => $this->team, 'title' => 'Surface ' . $date,
            'session_date' => $date, 'activity_type_key' => $type, 'game_subtype_key' => 'League',
            'activity_status_key' => 'planned', 'plan_state' => 'scheduled', 'opponent' => 'VVV', 'home_away' => 'home',
        ] );
        $id = (int) $wpdb->insert_id;
        if ( $minutes > 0 ) {
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id' => $club, 'activity_id' => $id, 'player_id' => $this->player, 'is_guest' => 0,
                'record_type' => 'actual', 'status' => 'present', 'minutes_played' => $minutes,
            ] );
        }
    }
}
