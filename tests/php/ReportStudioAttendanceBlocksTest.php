<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\Analytics\Domain\DimensionValueResolver;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;

/**
 * #4572 — the attendance blocks: attendance per player equals the team
 * monthly report's attendance section, players flagged on attendance equals
 * the at-risk list on the configured thresholds (and not the report's 70 %
 * line), both are staff only, a coach is refused another team, and each block
 * runs a bounded number of statements.
 */
final class ReportStudioAttendanceBlocksTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $club = 1;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $manager = 0;
    private int $hod = 0;
    private int $absent = 0;
    private int $late = 0;
    private int $steady = 0;
    private int $low = 0;
    private int $pb1 = 0;

    /** @var list<int> */
    private array $sessions = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        QueryHelpers::set_config( AttendanceFlagService::CONFIG_KEY, '3' );
        QueryHelpers::set_config( AttendanceFlagService::LATE_CONFIG_KEY, '' );
        $this->flush();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $this->team_a  = $this->insertTeam( 'Attendance U12' );
        $this->team_b  = $this->insertTeam( 'Attendance U15' );
        $this->manager = $this->makePersona( 'tt_team_manager', 'team_manager', $this->team_a );
        $this->hod     = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team_a );

        // Shirt numbers out of name order, so shirt order is visible.
        $this->absent = $this->insertPlayer( $this->team_a, 'Anna', 9 );
        $this->late   = $this->insertPlayer( $this->team_a, 'Bram', 4 );
        $this->steady = $this->insertPlayer( $this->team_a, 'Cor', 1 );
        // Two misses out of five: under the monthly report's 70 % line, under
        // the flag threshold of 3 — the case the two meanings disagree on.
        $this->low    = $this->insertPlayer( $this->team_a, 'Dirk', 7 );
        $this->pb1    = $this->insertPlayer( $this->team_b, 'Eva', 2 );

        foreach ( [ '2020-03-02', '2020-03-05', '2020-03-09', '2020-03-12', '2020-03-16' ] as $i => $date ) {
            $a = $this->insertActivity( $this->team_a, $date );
            $this->sessions[] = $a;
            $this->insertAttendance( $a, $this->absent, $i < 3 ? 'Absent' : 'Present' );
            $this->insertAttendance( $a, $this->late, $i < 3 ? 'Late' : 'Present' );
            $this->insertAttendance( $a, $this->steady, 'Present' );
            $this->insertAttendance( $a, $this->low, $i < 2 ? 'Absent' : 'Present' );
        }
        $b = $this->insertActivity( $this->team_b, '2020-03-03' );
        foreach ( [ 1, 2, 3 ] as $i ) {
            $this->insertAttendance( $this->insertActivity( $this->team_b, '2020-03-1' . $i ), $this->pb1, 'Absent' );
        }
        $this->insertAttendance( $b, $this->pb1, 'Absent' );
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_both_attendance_blocks_are_registered_and_staff_only(): void {
        foreach ( [ 'attendance_per_player', 'attendance_flags' ] as $key ) {
            $block = BlockRegistry::find( $key );
            $this->assertNotNull( $block, $key );
            $this->assertSame( [ PlayerReportAudience::INTERNAL ], $block->audiences(), $key );
            $this->assertSame( [], $block->externalKeys(), $key );
        }
        $listed = array_map( static fn( $b ): string => $b->key(), BlockRegistry::availableFor( ReportContext::forListing( 'player', PlayerReportAudience::FAMILY, $this->hod ) ) );
        $this->assertNotContains( 'attendance_per_player', $listed );
        $this->assertNotContains( 'attendance_flags', $listed );

        foreach ( [ 'attendance_per_player', 'attendance_flags' ] as $key ) {
            $family = BlockEngine::preview( $key, [ 'scope' => 'player', 'player_id' => $this->absent, 'audience' => PlayerReportAudience::FAMILY ], [], $this->hod );
            $this->assertInstanceOf( StudioError::class, $family, $key );
        }
    }

    public function test_attendance_per_player_equals_the_monthly_reports_section(): void {
        $block  = $this->preview( 'attendance_per_player', $this->team( $this->team_a ) );
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'attendance' ] );
        $theirs = $report['data']['attendance']['rows'];

        $this->assertSame( array_column( $theirs, 'player_id' ), array_column( $block['data']['rows'], 'player_id' ), 'the report\'s shirt order' );
        $this->assertSame( [ $this->steady, $this->late, $this->low, $this->absent ], array_column( $block['data']['rows'], 'player_id' ) );
        foreach ( $block['data']['rows'] as $i => $row ) {
            foreach ( [ 'activities', 'present', 'late', 'absent', 'excused', 'injured', 'suspended' ] as $field ) {
                $this->assertSame( (int) $theirs[ $i ][ $field ], $row[ $field ], "{$field} for player {$row['player_id']}" );
            }
            $this->assertSame( $theirs[ $i ]['present_pct'], $row['attendance_pct'] );
        }
        $this->assertSame( $report['data']['attendance']['team_avg_pct'], $block['data']['team_avg_pct'] );
        $this->assertSame( $report['data']['attendance']['absences'], $block['data']['absences'] );

        // The hand count: late counts as attended.
        $by_id = array_column( $block['data']['rows'], null, 'player_id' );
        $this->assertEquals( 100.0, $by_id[ $this->late ]['attendance_pct'] );
        $this->assertEquals( 40.0, $by_id[ $this->absent ]['attendance_pct'] );
        // Neither the 70 % line nor the flags travel with this block.
        $this->assertArrayNotHasKey( 'below', $block['data'] );
        $this->assertArrayNotHasKey( 'flagged', $block['data']['rows'][0] );
    }

    public function test_the_flags_are_the_at_risk_list_on_the_configured_thresholds(): void {
        $block  = $this->preview( 'attendance_flags', $this->team( $this->team_a ) );
        $theirs = ( new AttendanceRankingQuery() )->atRisk( self::FROM, self::TO, $this->team_a );

        $this->assertSame( array_column( $theirs, 'player_id' ), array_column( $block['data']['rows'], 'player_id' ), 'the at-risk order' );
        $this->assertSame( [ $this->absent, $this->late ], array_column( $block['data']['rows'], 'player_id' ) );
        foreach ( $block['data']['rows'] as $i => $row ) {
            $this->assertSame( AttendanceFlagService::reasonText( $theirs[ $i ] ), $row['reason'] );
            $this->assertSame( $theirs[ $i ]['present_pct'], $row['attendance_pct'] );
            $this->assertArrayNotHasKey( 'team_name', $row, 'a team list does not repeat the team' );
        }
        // One meaning: the player under the 70 % line but under the threshold is not flagged.
        $this->assertNotContains( $this->low, array_column( $block['data']['rows'], 'player_id' ) );
        $this->assertSame( 3, $block['data']['threshold'] );
        $this->assertSame( 3, $block['data']['late_threshold'] );

        // The reason says lateness, not "0 missed", for the late player.
        $by_id = array_column( $block['data']['rows'], null, 'player_id' );
        $this->assertSame( sprintf( '%d late', 3 ), $by_id[ $this->late ]['reason'] );
        $this->assertSame( sprintf( '%d missed', 3 ), $by_id[ $this->absent ]['reason'] );
    }

    public function test_the_academys_flags_are_every_teams_at_risk_list(): void {
        $admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->flush();
        $out = BlockEngine::preview( 'attendance_flags', [ 'scope' => 'academy', 'from' => self::FROM, 'to' => self::TO ], [], $admin );
        $this->assertIsArray( $out, $out instanceof StudioError ? $out->message : '' );

        $theirs = ( new AttendanceRankingQuery() )->atRisk( self::FROM, self::TO );
        $this->assertSame( array_column( $theirs, 'player_id' ), array_column( $out['data']['rows'], 'player_id' ) );
        $this->assertContains( $this->pb1, array_column( $out['data']['rows'], 'player_id' ) );
        $this->assertSame( 'Attendance U15', array_column( $out['data']['rows'], 'team_name', 'player_id' )[ $this->pb1 ] );
    }

    public function test_a_coach_out_of_scope_is_refused_each_blocks_subject(): void {
        foreach ( [ 'attendance_per_player', 'attendance_flags' ] as $key ) {
            $out = BlockEngine::preview( $key, $this->team( $this->team_b ), [], $this->manager );
            $this->assertInstanceOf( StudioError::class, $out, $key );
            $this->assertSame( 403, $out->status, $key );
        }
        $academy = BlockEngine::preview( 'attendance_flags', [ 'scope' => 'academy', 'from' => self::FROM, 'to' => self::TO ], [], $this->manager );
        $this->assertInstanceOf( StudioError::class, $academy, 'a team manager reads no academy-wide list' );
        $this->assertSame( 403, $academy->status );
    }

    public function test_the_blocks_answer_to_the_attendance_read_not_the_reports_read(): void {
        // The read-only observer reads reports club-wide and holds no
        // attendance read (seed matrix): the subject passes, the figures do not.
        $observer = $this->makePersona( 'tt_readonly_observer', 'readonly_observer', $this->team_a );
        $this->assertTrue( user_can( $observer, 'tt_view_reports' ), 'Precondition: the observer holds the blocks\' capability.' );
        $this->assertSame( [], AuthorizationService::attendanceReadableTeamIds( $observer ), 'Precondition: no attendance read.' );
        $subject = \TT\Modules\ReportStudio\Domain\ReportSubjectAccess::check( ReportContext::fromInput( $this->team( $this->team_a ), $observer ) );
        $this->assertInstanceOf( ReportContext::class, $subject, 'Precondition: the reports read lets the observer open the team.' );

        foreach ( [ 'attendance_per_player', 'attendance_flags' ] as $key ) {
            $out = BlockEngine::preview( $key, $this->team( $this->team_a ), [], $observer );
            $this->assertInstanceOf( StudioError::class, $out, "{$key} for a reader without the attendance read" );
            $this->assertSame( 'not_found', $out->code, $key );
        }
        $listed = array_map( static fn( $b ): string => $b->key(), BlockRegistry::availableFor( ReportContext::forListing( 'team', PlayerReportAudience::INTERNAL, $observer ) ) );
        $this->assertNotContains( 'attendance_per_player', $listed );
        $this->assertNotContains( 'attendance_flags', $listed );

        // The edge: the attendance read on the team is what opens them.
        $this->assertNotNull( AuthorizationService::attendanceReadableTeamIds( $this->manager ) );
        $this->assertTrue( AuthorizationService::canReadTeamAttendance( $this->manager, $this->team_a ) );
        $this->assertIsArray( BlockEngine::preview( 'attendance_per_player', $this->team( $this->team_a ), [], $this->manager ) );
    }

    public function test_each_block_adds_no_statement_per_player_to_the_shared_read(): void {
        $context = $this->team( $this->team_a );
        $before  = [];
        foreach ( [ 'attendance_per_player', 'attendance_flags' ] as $key ) $before[ $key ] = $this->statementsFor( $key, $context );
        $read_before = $this->readStatements();

        // Six more squad players who attend: more rows.
        for ( $i = 0; $i < 6; $i++ ) {
            $pid = $this->insertPlayer( $this->team_a, 'Extra' . $i, 20 + $i );
            foreach ( $this->sessions as $a ) $this->insertAttendance( $a, $pid, 'Present' );
        }

        // Both blocks read `AttendanceRankingQuery::rows()`, whose flag
        // thresholds are read from the configuration once per player today
        // (a `ConfigService` per call, #4948). The blocks add nothing per
        // player on top of that read: their growth is exactly the read's.
        $read_growth = $this->readStatements() - $read_before;
        foreach ( [ 'attendance_per_player', 'attendance_flags' ] as $key ) {
            $this->assertSame( $read_growth, $this->statementsFor( $key, $context ) - $before[ $key ], "{$key} costs more per player than the attendance read" );
        }
    }

    private function readStatements(): int {
        global $wpdb;
        $this->flush();
        $start = (int) $wpdb->num_queries;
        ( new AttendanceRankingQuery() )->rows( self::FROM, self::TO, $this->team_a );
        return (int) $wpdb->num_queries - $start;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function preview( string $key, array $context ): array {
        $out = BlockEngine::preview( $key, $context, [], $this->hod );
        $this->assertIsArray( $out, $key . ( $out instanceof StudioError ? ': ' . $out->message : '' ) );
        return $out;
    }

    /** @return array<string,mixed> */
    private function team( int $team_id ): array {
        return [ 'scope' => 'team', 'team_id' => $team_id, 'from' => self::FROM, 'to' => self::TO ];
    }

    /** @param array<string,mixed> $context */
    private function statementsFor( string $key, array $context ): int {
        global $wpdb;
        BlockEngine::preview( $key, $context, [], $this->hod );
        SharedReads::flush();
        DimensionValueResolver::flush();
        wp_cache_flush();
        $start = (int) $wpdb->num_queries;
        $out   = BlockEngine::preview( $key, $context, [], $this->hod );
        $this->assertIsArray( $out, $key );
        return (int) $wpdb->num_queries - $start;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, int $jersey ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Attendance',
            'status' => 'active', 'jersey_number' => $jersey,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => $this->club, 'team_id' => $team_id, 'title' => 'Training ' . $date, 'session_date' => $date,
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => $this->club, 'activity_id' => $activity_id, 'player_id' => $player_id,
            'status' => $status, 'is_guest' => 0, 'record_type' => 'actual',
        ] );
    }

    /** A persona from its WordPress role, with a team-scope grant on one team. */
    private function makePersona( string $role, string $role_type, int $team_id ): int {
        global $wpdb;
        if ( get_role( $role ) === null ) {
            add_role( $role, $role_type, [ 'read' => true ] );
        }
        $user_id = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Attendance',
            'role_type' => $role_type, 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => (int) $wpdb->insert_id, 'role_id' => 1,
            'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        $this->flush();
        return $user_id;
    }
}
