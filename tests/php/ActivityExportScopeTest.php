<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\Exporters\TeamActivitiesCsvExporter;
use TT\Modules\Export\Exporters\TeamPlannerXlsxExporter;

/**
 * The team activity exports, and the per-activity principle and result
 * reads, are bounded by the teams the caller covers.
 *
 * `TeamActivitiesCsvExporter` and `TeamPlannerXlsxExporter` asked only
 * `tt_view_activities`, which every coach holds, and took the team from the
 * request. They now follow `ExportScope`, the rule the other squad exports
 * use. `GET activities/{id}/principles` and `GET activities/{id}/result`
 * now ask `ActivityAccess::canRead()` about the activity in the URL.
 */
final class ActivityExportScopeTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $teamA = 0;
    private int $teamB = 0;
    private int $activityA = 0;
    private int $activityB = 0;
    private int $coachA = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Export JO13-1' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Export JO17-1' ] );
        $this->teamB = (int) $wpdb->insert_id;
        $this->activityA = $this->activity( $this->teamA, 'Own team match' );
        $this->activityB = $this->activity( $this->teamB, 'Other team match' );

        $this->coachA = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Export', 'last_name' => 'Coach',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coachA, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'team_id' => $this->teamA, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->teamA,
        ] );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── team activities CSV ────────────────────────────────────────────

    public function test_a_coach_exports_their_own_teams_activities_only(): void {
        $exporter = new TeamActivitiesCsvExporter();
        $this->assertTrue( $exporter->isAvailableFor( $this->coachA ) );

        $titles = array_column( $this->activityRows( $this->coachA, 0 ), 2 );
        $this->assertContains( 'Own team match', $titles );
        $this->assertNotContains( 'Other team match', $titles, 'an unfiltered export narrows to the coach\'s teams' );

        $this->expectException( ExportException::class );
        $this->activityRows( $this->coachA, $this->teamB );
    }

    public function test_a_parent_cannot_run_the_team_activities_export(): void {
        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->assertFalse( ( new TeamActivitiesCsvExporter() )->isAvailableFor( $parent ) );
        $this->assertFalse( ( new TeamPlannerXlsxExporter() )->isAvailableFor( $parent ) );
    }

    public function test_an_administrator_exports_every_team(): void {
        $admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $titles = array_column( $this->activityRows( $admin, 0 ), 2 );
        $this->assertContains( 'Own team match', $titles );
        $this->assertContains( 'Other team match', $titles );
    }

    // ── team planner ───────────────────────────────────────────────────

    public function test_a_coach_cannot_export_another_teams_planner(): void {
        $this->expectException( ExportException::class );
        ( new TeamPlannerXlsxExporter() )->collect( $this->request( 'team_planner', $this->coachA, $this->teamB ) );
    }

    public function test_a_coach_exports_their_own_teams_planner(): void {
        $payload = ( new TeamPlannerXlsxExporter() )->collect( $this->request( 'team_planner', $this->coachA, $this->teamA ) );
        $this->assertNotSame( [], $payload );
    }

    // ── per-activity reads ─────────────────────────────────────────────

    public function test_principles_and_result_refuse_another_teams_activity(): void {
        wp_set_current_user( $this->coachA );

        $this->assertSame( 403, $this->status( '/activities/' . $this->activityB . '/principles' ) );
        $this->assertSame( 403, $this->status( '/activities/' . $this->activityB . '/result' ) );
        $this->assertSame( 200, $this->status( '/activities/' . $this->activityA . '/principles' ) );
        $this->assertNotSame( 403, $this->status( '/activities/' . $this->activityA . '/result' ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function status( string $route ): int {
        return rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1' . $route ) )->get_status();
    }

    private function request( string $key, int $user_id, int $team_id ): ExportRequest {
        $exporter = $key === 'team_planner' ? new TeamPlannerXlsxExporter() : new TeamActivitiesCsvExporter();
        $filters  = (array) $exporter->validateFilters( [ 'team_id' => $team_id, 'date_from' => '2026-01-01', 'date_to' => '2026-12-31' ] );
        return new ExportRequest( $key, 'xlsx', $this->club, $user_id, null, $filters );
    }

    /** @return list<list<mixed>> */
    private function activityRows( int $user_id, int $team_id ): array {
        $payload = ( new TeamActivitiesCsvExporter() )->collect( $this->request( 'team_activities', $user_id, $team_id ) );
        return array_map( static fn( $r ): array => array_values( (array) $r ), (array) ( $payload['rows'] ?? [] ) );
    }

    private function activity( int $team_id, string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => $title,
            'session_date'        => '2026-06-15',
            'activity_type_key'   => 'game',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        return (int) $wpdb->insert_id;
    }
}
