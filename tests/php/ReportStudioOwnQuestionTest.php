<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;

/**
 * #4572 — "your own question" against the #4584 fix: the attendance fact the
 * block asks counts the rows the attendance reports count (completed, live,
 * past, and the player on the team's current roster), so its attendance
 * percentage per player equals `AttendanceRankingQuery::rows()` beside it;
 * and the evaluations-per-session fact, which used to read a column that
 * does not exist, answers.
 */
final class ReportStudioOwnQuestionTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $club = 1;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $hod = 0;
    private int $pa1 = 0;
    private int $pa2 = 0;
    private int $pb1 = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->flush();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $this->team_a = $this->insertTeam( 'Question U12' );
        $this->team_b = $this->insertTeam( 'Question U15' );
        $this->hod    = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team_a );

        $this->pa1 = $this->insertPlayer( $this->team_a, 'Anna' );
        $this->pa2 = $this->insertPlayer( $this->team_a, 'Bram' );
        $this->pb1 = $this->insertPlayer( $this->team_b, 'Cor' );

        // The four kinds of row #4584 is about, on team A's activities.
        $done1     = $this->insertActivity( $this->team_a, '2020-03-02', 'completed' );
        $done2     = $this->insertActivity( $this->team_a, '2020-03-09', 'completed' );
        $scheduled = $this->insertActivity( $this->team_a, '2020-03-16', 'planned' );
        $trashed   = $this->insertActivity( $this->team_a, '2020-03-23', 'completed', '2020-03-24 10:00:00' );

        $this->insertAttendance( $done1, $this->pa1, 'Present' );
        $this->insertAttendance( $done2, $this->pa1, 'Absent' );
        $this->insertAttendance( $scheduled, $this->pa1, 'Present' );  // not completed: not counted
        $this->insertAttendance( $trashed, $this->pa1, 'Present' );    // trashed: not counted
        $this->insertAttendance( $done1, $this->pa2, 'Present' );
        $this->insertAttendance( $done2, $this->pa2, 'Late' );         // late counts as attended
        $this->insertAttendance( $done1, $this->pb1, 'Absent' );       // not on team A's roster: not counted

        // Evaluations of Anna at two of the activities.
        $this->evaluate( $this->pa1, $done1, '2020-03-02' );
        $this->evaluate( $this->pa1, $done2, '2020-03-09' );
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_attendance_per_player_equals_the_attendance_reports(): void {
        $out = $this->preview( [ 'scope' => 'team', 'team_id' => $this->team_a, 'from' => self::FROM, 'to' => self::TO ],
            [ 'fact' => 'attendance', 'measure' => 'attendance_pct', 'breakdown' => 'player_id' ] );

        $block = [];
        foreach ( $out['data']['rows'] as $row ) $block[ (int) $row['key'] ] = $row['value'];
        $report = [];
        foreach ( ( new AttendanceRankingQuery() )->rows( self::FROM, self::TO, $this->team_a ) as $r ) {
            $report[ (int) $r['player_id'] ] = $r['present_pct'] !== null ? round( (float) $r['present_pct'], 1 ) : null;
        }
        ksort( $block );
        ksort( $report );

        // Every figure the block shows is the attendance reports' figure.
        $figures = array_filter( $block, static fn( $v ): bool => $v !== null );
        $this->assertSame( $report, $figures, 'the same percentage as the attendance reports, player for player' );
        // The hand count: Anna 1 of 2 (the scheduled and the trashed rows are
        // out), Bram 2 of 2 (late is attended).
        $this->assertSame( [ $this->pa1 => 50.0, $this->pa2 => 100.0 ], $figures );

        // Known gap, reported with #4572: the fact's rows stay unscoped while
        // its measures follow the attendance rules (#3451, #4584), so a player
        // with only uncounted rows — Cor, not on this team's roster — is still
        // a row of the breakdown, with no figure. When the breakdown drops
        // such rows this assertion changes.
        $this->assertArrayHasKey( $this->pb1, $block );
        $this->assertNull( $block[ $this->pb1 ] );
    }

    public function test_one_players_attendance_equals_the_attendance_reports(): void {
        $out = $this->preview( [ 'scope' => 'player', 'player_id' => $this->pa1, 'from' => self::FROM, 'to' => self::TO ],
            [ 'fact' => 'attendance', 'measure' => 'attendance_pct' ] );

        $rows = array_column( ( new AttendanceRankingQuery() )->rows( self::FROM, self::TO, $this->team_a ), null, 'player_id' );
        $this->assertSame( round( (float) $rows[ $this->pa1 ]['present_pct'], 1 ), $out['data']['value'] );
        $this->assertSame( 50.0, $out['data']['value'] );
    }

    public function test_the_evaluation_coverage_fact_answers(): void {
        // The fact names no player or team dimension, so the block offers it
        // for the whole academy only; a club-wide reader asks it there.
        $admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->flush();
        $out = BlockEngine::preview( 'own_question', [ 'scope' => 'academy', 'from' => self::FROM, 'to' => self::TO ],
            [ 'fact' => 'evaluations_per_session', 'measure' => 'count_evaluations', 'breakdown' => 'month' ], $admin );
        $this->assertIsArray( $out, $out instanceof StudioError ? $out->message : '' );

        $march = array_values( array_filter( $out['data']['rows'], static fn( array $r ): bool => $r['key'] === '2020-03' ) );
        $this->assertCount( 1, $march, 'the month bucket reads the activity date, the column that exists' );
        $this->assertSame( 2.0, $march[0]['value'], 'two evaluations at March activities' );
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function preview( array $context, array $options ): array {
        $out = BlockEngine::preview( 'own_question', $context, $options, $this->hod );
        $this->assertIsArray( $out, $out instanceof StudioError ? $out->message : '' );
        return $out;
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

    private function insertPlayer( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Question', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id, string $date, string $status, ?string $trashed_at = null ): int {
        global $wpdb;
        $row = [
            'club_id' => $this->club, 'team_id' => $team_id, 'title' => 'Training ' . $date, 'session_date' => $date,
            'activity_type_key' => 'training', 'activity_status_key' => $status, 'plan_state' => $status,
        ];
        if ( $trashed_at !== null ) $row['trashed_at'] = $trashed_at;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", $row );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => $this->club, 'activity_id' => $activity_id, 'player_id' => $player_id,
            'status' => $status, 'is_guest' => 0, 'record_type' => 'actual',
        ] );
    }

    private function evaluate( int $player_id, int $activity_id, string $date ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id' => $this->club, 'player_id' => $player_id, 'coach_id' => 1, 'eval_type_id' => 0,
            'activity_id' => $activity_id, 'eval_date' => $date, 'notes' => '',
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
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Question',
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
