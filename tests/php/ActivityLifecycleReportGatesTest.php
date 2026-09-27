<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\AttendanceRegisterCsvExporter;

/**
 * `tt_activities` carries two lifecycle columns that disagree in practice:
 * `activity_status_key`, which the coach sets, and `plan_state`, which only
 * the planner sets and which defaults to `completed` on every other create
 * path. The reports decide through `ActivityLifecycle`, and these tests pin
 * the rows that tell the two readings apart.
 *
 * "Completed" follows the status: a match completed by status with a stale
 * `plan_state = 'scheduled'` happened, and a planned one carrying the default
 * `plan_state = 'completed'` did not.
 *
 * "Cancelled" is honoured on either column: a match cancelled by status only,
 * or by `plan_state` only, never took place.
 */
final class ActivityLifecycleReportGatesTest extends WP_UnitTestCase {

    private const FROM = '2026-01-01';
    private const TO   = '2026-06-30';

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    /* ---- completed: the register export and eval coverage ------------- */

    public function test_the_register_export_follows_the_status_not_plan_state(): void {
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        $admin = self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        wp_set_current_user( $admin );

        $team_id   = $this->insertTeam( 'U15 register' );
        $player_id = $this->insertPlayer( $team_id, 'Reg', 'Ister' );

        $done    = $this->insertActivity( $team_id, '2026-03-01', 'training', 'completed', 'scheduled', 'Done by status' );
        $planned = $this->insertActivity( $team_id, '2026-03-08', 'training', 'planned', 'completed', 'Planned with default' );
        $this->insertAttendance( $done, $player_id, null );
        $this->insertAttendance( $planned, $player_id, null );

        $payload = ( new AttendanceRegisterCsvExporter() )->collect( new ExportRequest(
            'attendance_register', 'csv', $this->club, $admin, null,
            [ 'team_id' => $team_id, 'date_from' => self::FROM, 'date_to' => self::TO ]
        ) );

        $titles = array_map( static fn( array $row ): string => (string) $row[1], $payload['rows'] );
        $this->assertSame( [ 'Done by status' ], $titles, 'only the activity completed by status is in the register' );
    }

    public function test_eval_coverage_counts_activities_completed_by_status(): void {
        $team_id = $this->insertTeam( 'U15 coverage' );
        $player  = $this->insertPlayer( $team_id, 'Cov', 'Erage' );

        $done    = $this->insertActivity( $team_id, '2026-03-01', 'training', 'completed', 'scheduled', 'Done by status' );
        $this->insertActivity( $team_id, '2026-03-08', 'training', 'planned', 'completed', 'Planned with default' );
        $this->insertAttendance( $done, $player, null );

        $rows = ( new EvalCoverageService() )->attendanceCompliance( [ 'start' => self::FROM, 'end' => self::TO ] );
        $row  = null;
        foreach ( $rows as $r ) {
            if ( $r['team_id'] === $team_id ) $row = $r;
        }

        $this->assertNotNull( $row );
        $this->assertSame( 1, $row['completed'], 'the planned activity is not completed, the status-completed one is' );
        $this->assertSame( 1, $row['with_attendance'] );
    }

    /* ---- cancelled: the minutes queries -------------------------------- */

    public function test_the_minutes_audit_drops_a_match_cancelled_on_either_column(): void {
        [ $team_id, $live ] = $this->seedCancelledPair( 'U15 audit' );

        $games = ( new MinutesAuditQuery() )->matrix( $team_id, self::FROM, self::TO )['games'];
        $ids   = array_map( static fn( array $g ): int => (int) $g['activity_id'], $games );

        $this->assertSame( [ $live ], $ids );
    }

    public function test_the_minutes_share_drops_a_match_cancelled_on_either_column(): void {
        [ $team_id ] = $this->seedCancelledPair( 'U15 share' );

        $q = new MinutesShareQuery();
        $this->assertSame( 1, $q->availableForTeam( $team_id, self::FROM, self::TO )['matches'] );

        $data = $q->forTeam( $team_id, self::FROM, self::TO );
        $this->assertSame( 1, $data['matches'] );
        $this->assertSame( 60, $data['players'][0]['minutes'], 'the cancelled matches add no minutes' );
    }

    /**
     * One live match and two cancelled ones, each cancelled on one column
     * only. Every match holds 60 recorded minutes for the same player.
     *
     * @return array{0:int, 1:int} team id, live match id
     */
    private function seedCancelledPair( string $team_name ): array {
        $team_id = $this->insertTeam( $team_name );
        $player  = $this->insertPlayer( $team_id, 'Can', 'Celled' );

        $live       = $this->insertActivity( $team_id, '2026-03-01', 'match', 'completed', 'completed', 'Live' );
        $by_status  = $this->insertActivity( $team_id, '2026-03-08', 'match', 'cancelled', 'completed', 'Cancelled by status' );
        $by_planner = $this->insertActivity( $team_id, '2026-03-15', 'match', 'planned', 'cancelled', 'Cancelled by planner' );

        foreach ( [ $live, $by_status, $by_planner ] as $match ) {
            $this->insertAttendance( $match, $player, 60 );
        }
        return [ $team_id, $live ];
    }

    /* ---- seed helpers --------------------------------------------------- */

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

    private function insertActivity( int $team_id, string $date, string $type, string $status, string $plan_state, string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => $title,
            'session_date'        => $date,
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
            'plan_state'          => $plan_state,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, ?int $minutes ): void {
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
