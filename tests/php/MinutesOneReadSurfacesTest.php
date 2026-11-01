<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Reports\MinutesGridQuery;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\TeamRosterStatsCsvExporter;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\Tournaments\Services\TournamentMinutesResolver;

/**
 * #4556 — one read behind every minutes figure.
 *
 * One fixture set — a duplicate row (70, then 60), a coach's override (50
 * corrected to 40), a confirmed 0 — read from every surface that reports a
 * player's minutes for a match: the grid, the audit, the minutes reports
 * (`forTeam()`, the per-match breakdown, `squadForActivity()`), the match
 * execution view's read and the roster stats CSV. Before, the CSV summed
 * raw rows and ignored the override, and the execution read took whichever
 * duplicate came back last.
 */
final class MinutesOneReadSurfacesTest extends WP_UnitTestCase {

    private const FROM = '2026-09-01';
    private const TO   = '2026-09-30';

    private string $p = '';
    private int $club = 0;
    private int $team = 0;
    private int $dup = 0;
    private int $override = 0;
    private int $zero = 0;
    private int $match = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb;
        $wpdb->hide_errors();
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'One read JO15' ] );
        $this->team     = (int) $wpdb->insert_id;
        $this->dup      = $this->insertPlayer( 'Duplicate' );
        $this->override = $this->insertPlayer( 'Override' );
        $this->zero     = $this->insertPlayer( 'Zero' );

        $this->match = $this->insertMatch( '2026-09-12' );
        $this->actual( $this->match, $this->dup, 70 );
        $this->actual( $this->match, $this->dup, 60 );
        $this->actual( $this->match, $this->override, 50, 40 );
        $this->actual( $this->match, $this->zero, 0 );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_every_surface_reads_the_same_minutes(): void {
        $expected = [ $this->dup => 60, $this->override => 40, $this->zero => 0 ];

        $grid  = ( new MinutesGridQuery() )->matrix( $this->team, self::FROM, self::TO );
        $audit = $this->auditRow();
        $squad = MinutesQuery::squadForActivity( $this->match );
        $exec  = ( new MatchExecutionRepository() )->loggedMinutesByActivity( $this->match );
        $csv   = $this->csvMinutes();
        $team  = $this->forTeamTotals();

        foreach ( $expected as $pid => $minutes ) {
            $this->assertSame( $minutes, (int) $grid['cells'][ $pid ][ $this->match ]['minutes'], "grid, player {$pid}" );
            $this->assertSame( $minutes, (int) ( $audit['minutes'][ $pid ] ?? 0 ), "audit, player {$pid}" );
            $this->assertSame( $minutes, (int) ( $squad[ $pid ] ?? 0 ), "squadForActivity, player {$pid}" );
            $this->assertSame( $minutes, (int) ( $exec[ $pid ] ?? 0 ), "match execution, player {$pid}" );
            $this->assertSame( $minutes, (int) ( $csv[ $pid ] ?? 0 ), "roster stats CSV, player {$pid}" );
            $this->assertSame( $minutes, (int) ( $team[ $pid ] ?? 0 ), "forTeam, player {$pid}" );

            $rows = ( new MinutesQuery() )->matchBreakdownForPlayer( $this->team, $pid, self::FROM, self::TO );
            $this->assertSame( $minutes, (int) array_sum( array_column( $rows, 'minutes' ) ), "breakdown, player {$pid}" );
        }
    }

    /**
     * Hidde's cases on #4592: the CSV and the team report count the same
     * matches. An archived match counts in neither; minutes typed into the
     * grid on a match nobody marked completed (#2407) count in both.
     */
    public function test_the_csv_and_the_team_report_count_the_same_matches(): void {
        global $wpdb;
        $archived = $this->insertMatch( '2026-09-19' );
        $this->actual( $archived, $this->dup, 30 );
        $wpdb->update( "{$this->p}tt_activities", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $archived ] );

        $open = $this->insertMatch( '2026-09-20', 'scheduled' );
        $this->actual( $open, $this->zero, 25 );

        $csv  = $this->csvMinutes();
        $team = $this->forTeamTotals();

        $this->assertSame( 60, $team[ $this->dup ] ?? 0, 'the archived match is left out of the report' );
        $this->assertSame( 60, $csv[ $this->dup ] ?? 0, 'and out of the CSV' );
        $this->assertSame( 25, $team[ $this->zero ] ?? 0, 'grid minutes on an uncompleted match count in the report' );
        $this->assertSame( 25, $csv[ $this->zero ] ?? 0, 'and in the CSV' );
    }

    /**
     * The "played" predicate reads the chosen row: a match still to come
     * whose duplicate was corrected to 0 has no minutes, so it is not
     * played yet; one with minutes on its chosen row is.
     */
    public function test_played_reads_the_chosen_row(): void {
        $window_from = '2099-05-01';
        $window_to   = '2099-05-31';

        $corrected = $this->insertMatch( '2099-05-10', 'scheduled' );
        $this->actual( $corrected, $this->dup, 70 );
        $this->actual( $corrected, $this->dup, 0 );
        $this->assertSame( 0, ( new MinutesQuery() )->matchCountsForTeam( $this->team, $window_from, $window_to )['played'], 'a duplicate corrected to 0 does not make it played' );

        $this->actual( $this->insertMatch( '2099-05-17', 'scheduled' ), $this->dup, 45 );
        $this->assertSame( 1, ( new MinutesQuery() )->matchCountsForTeam( $this->team, $window_from, $window_to )['played'], 'minutes on the chosen row do' );
    }

    public function test_the_shared_read_carries_the_source(): void {
        $entries = MinutesQuery::playerMatchMinutes( [ $this->match ] );

        $this->assertSame( [ 'minutes' => 60, 'source' => TournamentMinutesResolver::SOURCE_REGISTER ], $entries[ $this->match ][ $this->dup ] );
        $this->assertSame( [ 'minutes' => 0, 'source' => TournamentMinutesResolver::SOURCE_REGISTER ], $entries[ $this->match ][ $this->zero ], 'a confirmed 0 is a recorded figure' );
    }

    public function test_the_breakdown_reads_minutes_in_a_fixed_number_of_queries(): void {
        global $wpdb;
        $count = function (): int {
            global $wpdb;
            $before = $wpdb->num_queries;
            ( new MinutesQuery() )->matchBreakdownForPlayer( $this->team, $this->dup, self::FROM, self::TO );
            return $wpdb->num_queries - $before;
        };

        $one = $count();
        foreach ( [ '2026-09-19', '2026-09-26', '2026-09-27' ] as $date ) {
            $this->actual( $this->insertMatch( $date ), $this->dup, 45 );
        }
        $four = $count();

        $this->assertSame( $one, $four, 'one minutes read for all matches, not one per match' );
        $this->assertGreaterThan( 0, $wpdb->num_queries );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function auditRow(): array {
        foreach ( ( new MinutesAuditQuery() )->matrix( $this->team, self::FROM, self::TO )['games'] as $g ) {
            if ( (int) $g['activity_id'] === $this->match ) return $g;
        }
        $this->fail( 'the audit lists the match' );
    }

    /** @return array<int,int> player id => minutes */
    private function forTeamTotals(): array {
        $out = [];
        foreach ( ( new MinutesQuery() )->forTeam( $this->team, self::FROM, self::TO ) as $row ) {
            $out[ (int) $row['player_id'] ] = (int) $row['total_minutes'];
        }
        return $out;
    }

    /** @return array<int,int> player id => minutes column of the roster stats CSV */
    private function csvMinutes(): array {
        $payload = ( new TeamRosterStatsCsvExporter() )->collect( new ExportRequest(
            'team_roster_stats', 'csv', $this->club, get_current_user_id(), null,
            [ 'team_id' => $this->team, 'date_from' => self::FROM, 'date_to' => self::TO ]
        ) );
        $index = array_search( __( 'Minutes played', 'talenttrack' ), $payload['headers'], true );
        $out   = [];
        foreach ( $payload['rows'] as $row ) {
            $out[ (int) $row[0] ] = (int) $row[ (int) $index ];
        }
        return $out;
    }

    private function insertPlayer( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'One',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMatch( string $date, string $status = 'completed' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'              => $this->club,
            'team_id'              => $this->team,
            'title'                => 'One read match',
            'session_date'         => $date,
            'activity_type_key'    => 'match',
            'game_subtype_key'     => 'League',
            'activity_status_key'  => $status,
            'plan_state'           => $status === 'completed' ? 'completed' : 'planned',
            'match_length_minutes' => 70,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function actual( int $activity_id, int $player_id, int $minutes, ?int $override = null ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'          => $this->club,
            'activity_id'      => $activity_id,
            'player_id'        => $player_id,
            'status'           => 'present',
            'is_guest'         => 0,
            'record_type'      => 'actual',
            'minutes_played'   => $minutes,
            'minutes_override' => $override,
        ] );
    }
}
