<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
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
        // The thresholds are read through QueryHelpers' one ConfigService
        // (#4948), whose cache outlives the per-test rollback: a fresh one
        // keeps a threshold set here from leaking into the next case.
        QueryHelpers::setConfigService( new ConfigService() );
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
        QueryHelpers::setConfigService( new ConfigService() );
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
        // #4949 — the section is for a reader with the attendance right.
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'attendance' ], $this->hod );
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

        // Both blocks read `AttendanceRankingQuery::rows()`. Since #4948 its
        // flag thresholds come from the request's one config service, read
        // once rather than per player. The blocks add nothing per player on
        // top of that read: their growth is exactly the read's.
        $read_growth = $this->readStatements() - $read_before;
        foreach ( [ 'attendance_per_player', 'attendance_flags' ] as $key ) {
            $this->assertSame( $read_growth, $this->statementsFor( $key, $context ) - $before[ $key ], "{$key} costs more per player than the attendance read" );
        }
    }

    /**
     * #4948 — the thresholds cost no statement per player. Each
     * `flagReasons()` call used to build its own `ConfigService`, so six
     * more players cost 18 more `tt_config` reads.
     */
    public function test_the_ranking_reads_the_thresholds_once_not_per_player(): void {
        $before = $this->configStatementsForRows();

        for ( $i = 0; $i < 6; $i++ ) {
            $pid = $this->insertPlayer( $this->team_a, 'More' . $i, 30 + $i );
            foreach ( $this->sessions as $a ) $this->insertAttendance( $a, $pid, 'Present' );
        }

        $this->assertSame( 0, $this->configStatementsForRows() - $before, 'six more players, no more config reads' );
        $this->assertSame( 0, $before, 'set_up stored both keys through the same service, so they are already read' );
    }

    /** A threshold saved mid-request is the one the next ranking flags on. */
    public function test_the_ranking_follows_a_threshold_change_in_the_same_process(): void {
        $flagged = static fn( array $rows ): array => array_values( array_map(
            static fn( array $r ): int => (int) $r['player_id'],
            array_filter( $rows, static fn( array $r ): bool => (bool) $r['flagged'] )
        ) );
        $rows = ( new AttendanceRankingQuery() )->rows( self::FROM, self::TO, $this->team_a );
        $this->assertNotContains( $this->low, $flagged( $rows ), 'two misses are under a threshold of 3' );

        QueryHelpers::set_config( AttendanceFlagService::CONFIG_KEY, '2' );
        $this->flush();

        $rows = ( new AttendanceRankingQuery() )->rows( self::FROM, self::TO, $this->team_a );
        $this->assertContains( $this->low, $flagged( $rows ), 'and reach a threshold of 2' );
        $this->assertSame( 2, AttendanceFlagService::threshold() );
        $this->assertSame( 2, AttendanceFlagService::lateThreshold(), 'lateness still follows the absence threshold' );
    }

    private function configStatementsForRows(): int {
        $this->flush();
        $count   = 0;
        $counter = static function ( string $sql ) use ( &$count ): string {
            if ( strpos( $sql, 'tt_config' ) !== false ) $count++;
            return $sql;
        };
        add_filter( 'query', $counter );
        try {
            ( new AttendanceRankingQuery() )->rows( self::FROM, self::TO, $this->team_a );
        } finally {
            remove_filter( 'query', $counter );
        }
        return $count;
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

    /**
     * #4949 — the monthly report's attendance section and its attendance
     * tile follow the attendance read right. A persona who reads the team's
     * reports but not its attendance gets neither, in the composed report,
     * on screen and in the PDF; the head coach and the head of development
     * get both, and every other section is the same for all of them.
     */
    public function test_the_monthly_reports_attendance_follows_the_attendance_right(): void {
        $readers = [
            'head of development' => $this->hod,
            'head coach'          => $this->makePersona( 'tt_coach', 'head_coach', $this->team_a ),
            'observer'            => $this->makePersona( 'tt_readonly_observer', 'readonly_observer', $this->team_a ),
            'scout'               => $this->makePersona( 'tt_scout', 'scout', $this->team_a ),
        ];

        $without  = [];
        $sections = [];
        foreach ( $readers as $who => $uid ) {
            $this->flush();
            $reads = AuthorizationService::canReadTeamAttendance( $uid, $this->team_a );
            if ( in_array( $who, [ 'head of development', 'head coach' ], true ) ) {
                $this->assertTrue( $reads, "Precondition: the {$who} reads the team's attendance." );
            }
            if ( $who === 'observer' ) {
                $this->assertTrue( \TT\Modules\Analytics\Reports\TeamReportAccess::canRead( $uid, $this->team_a ), 'Precondition: the observer reads the team\'s reports.' );
                $this->assertFalse( $reads, 'Precondition: the observer does not read attendance.' );
            }

            $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [], $uid );
            $pdf    = \TT\Modules\Export\Exporters\TeamMonthlyReportPdfExporter::payload( $report, \TT\Modules\Analytics\Reports\TeamMonthlyReportLayout::DEFAULT, 'Attendance U12' )['html'];
            $prep   = \TT\Modules\Analytics\Reports\TeamMonthlyReportDocument::prepare( $report, \TT\Modules\Analytics\Reports\TeamMonthlyReportLayout::DEFAULT );
            $screen = \TT\Modules\Analytics\Reports\TeamMonthlyReportDocument::screen( $prep['report'], \TT\Modules\Analytics\Reports\TeamMonthlyReportLayout::DEFAULT, 'Attendance U12', $prep['fit'] );
            // The tile's label cell: the PDF's class, and the screen's, which
            // prefixes every document class with `tt-d-`.
            $label      = esc_html__( 'Attendance', 'talenttrack' );
            $pdf_tile   = '<div class="kl">' . $label . '</div>';
            $screen_tile = '<div class="tt-d-kl">' . $label . '</div>';

            if ( $reads ) {
                $this->assertContains( 'attendance', $report['blocks'], $who );
                $this->assertArrayHasKey( 'attendance_pct', $report['data']['kpi'], $who );
                $this->assertStringContainsString( $pdf_tile, $pdf, "{$who}: the PDF carries the tile" );
                $this->assertStringContainsString( $screen_tile, $screen, "{$who}: the screen carries the tile" );
            } else {
                $this->assertNotContains( 'attendance', $report['blocks'], $who );
                $this->assertArrayNotHasKey( 'attendance', $report['data'], $who );
                $this->assertArrayNotHasKey( 'attendance_pct', $report['data']['kpi'], $who );
                $this->assertStringNotContainsString( $pdf_tile, $pdf, "{$who}: no attendance tile in the PDF" );
                $this->assertStringNotContainsString( $screen_tile, $screen, "{$who}: no attendance tile on screen" );
                // Nor per player: the roster's and the attention list's figures.
                $this->assertSame( [], self::attendanceKeys( $report['data'] ), "{$who}: no attendance figure anywhere in the report" );
                $this->assertStringNotContainsString( '>' . esc_html_x( 'Att.', 'team monthly report column: attendance, short', 'talenttrack' ) . '<', $pdf, "{$who}: no attendance column in the roster" );
                $without[ $who ] = $report;
            }
            foreach ( [ 'kpi', 'attendance' ] as $gated ) unset( $report['data'][ $gated ] );
            $sections[ $who ] = array_keys( $report['data'] );
        }
        $this->assertArrayHasKey( 'observer', $without, 'the observer is a reader without the attendance right' );
        // Every other section is there for every reader.
        $this->assertCount( 1, array_unique( array_map( 'wp_json_encode', $sections ) ), 'the other sections do not depend on the attendance right' );
    }

    /**
     * #4949 — a snapshot is frozen under its taker's rights and shown under
     * the reader's. One taken by the head of development, who reads
     * attendance, is shown and printed without it to the observer, who does
     * not; the taker still sees it whole.
     */
    public function test_a_snapshot_is_shown_without_attendance_to_a_reader_without_the_right(): void {
        $observer = $this->makePersona( 'tt_readonly_observer', 'readonly_observer', $this->team_a );
        $this->assertFalse( AuthorizationService::canReadTeamAttendance( $observer, $this->team_a ), 'Precondition: the observer does not read attendance.' );

        $composition = \TT\Modules\Analytics\Reports\TeamMonthlyReportComposition::normalise( [
            'team_id' => $this->team_a, 'from' => self::FROM, 'to' => self::TO, 'layout' => \TT\Modules\Analytics\Reports\TeamMonthlyReportLayout::DEFAULT,
        ] );
        $frozen = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [], $this->hod );
        $this->assertContains( 'attendance', $frozen['blocks'], 'Precondition: the taker\'s snapshot carries attendance.' );
        $uuid = ( new \TT\Modules\Analytics\Reports\TeamReportSnapshotRepository() )->create( $this->team_a, $composition, $frozen, 'Snapshot', $this->hod );
        $this->assertNotSame( '', $uuid );

        $tile = esc_html__( 'Attendance', 'talenttrack' ) . '</div>';
        foreach ( [ $observer => false, $this->hod => true ] as $uid => $sees ) {
            $this->flush();
            wp_set_current_user( $uid );
            ob_start();
            $this->assertTrue( \TT\Modules\Analytics\Frontend\TeamMonthlyReportSnapshotPage::render( $uuid ) );
            $page = (string) ob_get_clean();

            $pdf = ( new \TT\Modules\Export\Exporters\TeamMonthlyReportPdfExporter() )->collect(
                new \TT\Modules\Export\Domain\ExportRequest( 'team_monthly_report_pdf', 'pdf', (int) CurrentClub::id(), $uid, null, [ 'snapshot' => $uuid ] )
            );
            $html = (string) ( $pdf['html'] ?? '' );
            $this->assertNotSame( '', $html, 'the snapshot PDF renders' );

            if ( $sees ) {
                $this->assertStringContainsString( 'class="tt-d-kl">' . $tile, $page, 'the taker sees the tile on screen' );
                $this->assertStringContainsString( 'class="kl">' . $tile, $html, 'and in the PDF' );
            } else {
                $this->assertStringNotContainsString( 'class="tt-d-kl">' . $tile, $page, 'no attendance tile on screen' );
                $this->assertStringNotContainsString( 'class="kl">' . $tile, $html, 'no attendance tile in the PDF' );
                $this->assertSame( [], self::attendanceKeys( TeamMonthlyReport::forReader( $frozen, $this->team_a, $uid )['data'] ) );
            }
        }
        wp_set_current_user( 0 );
    }

    /**
     * Every path in the report data that ends in an attendance figure.
     *
     * @param array<mixed> $data
     * @return list<string>
     */
    private static function attendanceKeys( array $data, string $path = '' ): array {
        $found = [];
        foreach ( $data as $key => $value ) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;
            if ( in_array( (string) $key, [ 'attendance_pct', 'missed', 'suspended' ], true ) ) $found[] = $here;
            if ( is_array( $value ) ) $found = array_merge( $found, self::attendanceKeys( $value, $here ) );
        }
        return $found;
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
