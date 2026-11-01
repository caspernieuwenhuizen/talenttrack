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
use TT\Modules\Export\ExportService;
use TT\Modules\MatchPrep\Export\MatchPrepPdfExporter;

/**
 * The match-prep PDF is the team's plan: line-up, focus per player, the
 * coach's notes. The export capability is club-wide on every coach, so the
 * exporter asks whose match it is, as the activity brief does: another
 * team's coach is refused, the team's own coach is served. It asks the
 * capability the match prep view asks, so a reader who sees every team
 * without coaching one is refused too.
 */
final class MatchPrepPdfExportScopeTest extends WP_UnitTestCase {

    private int $club     = 0;
    private int $team     = 0;
    private int $activity = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Prepdf U13', 'age_group' => 'U13' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_activities", [
            'club_id' => $this->club, 'team_id' => $this->team, 'title' => 'Prepdf match',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '+2 days' ) ),
            'activity_type_key' => 'match', 'activity_status_key' => 'planned', 'plan_state' => 'scheduled',
        ] );
        $this->activity = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Prepdfplayer',
            'last_name' => 'Kidx', 'status' => 'active',
            'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $player = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $player, 'fixture: the player row was written' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $put = new WP_REST_Request( 'PUT', '/talenttrack/v1/match-prep/' . $this->activity );
        $put->set_header( 'content-type', 'application/json' );
        $put->set_body( (string) wp_json_encode( [ 'availability' => [ $player => [ 'status' => 'Present', 'reason' => '' ] ] ] ) );
        $this->assertSame( 200, rest_do_request( $put )->get_status(), 'fixture: the prep was saved' );
        wp_set_current_user( 0 );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_another_teams_coach_is_refused_the_prep_pdf(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Prepdf Other U13', 'age_group' => 'U13' ] );
        $coach = $this->coachOf( (int) $wpdb->insert_id );

        try {
            $out = $this->export( $coach );
            $this->assertStringNotContainsString( 'Prepdfplayer', (string) ( $out['html'] ?? '' ), 'another team\'s prep was rendered' );
            $this->fail( 'the export was not refused' );
        } catch ( ExportException $e ) {
            $this->assertSame( 'forbidden', $e->errorKey );
        }
    }

    public function test_the_teams_own_coach_gets_the_prep_pdf(): void {
        $out = $this->export( $this->coachOf( $this->team ) );
        $this->assertStringContainsString( 'Prepdfplayer', (string) ( $out['html'] ?? '' ) );
    }

    /**
     * The read-only observer reads every team's activities and is refused
     * the match prep view, its REST route and the print page. The PDF asks
     * the same, on both ways in: the exporter itself and `ExportService`.
     */
    public function test_the_read_only_observer_is_refused_the_prep_pdf(): void {
        $observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->assertTrue( \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsActivities( $observer ), 'precondition: the seat reads every team' );

        wp_set_current_user( $observer );
        $get = new WP_REST_Request( 'GET', '/talenttrack/v1/match-prep/' . $this->activity );
        $this->assertSame( 403, rest_do_request( $get )->get_status(), 'precondition: the REST route refuses the observer' );

        try {
            $out = $this->export( $observer );
            $this->assertStringNotContainsString( 'Prepdfplayer', (string) ( $out['html'] ?? '' ), 'the observer\'s prep was rendered' );
            $this->fail( 'the export was not refused' );
        } catch ( ExportException $e ) {
            $this->assertSame( 'forbidden', $e->errorKey );
        }

        try {
            ( new ExportService() )->run( $this->request( $observer ) );
            $this->fail( 'the export service did not refuse' );
        } catch ( ExportException $e ) {
            $this->assertSame( 'forbidden', $e->errorKey );
        }
    }

    public function test_the_head_of_development_gets_the_prep_pdf(): void {
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $out = $this->export( $hod );
        $this->assertStringContainsString( 'Prepdfplayer', (string) ( $out['html'] ?? '' ) );
    }

    /** @return array<string, mixed> */
    private function export( int $user_id ): array {
        return ( new MatchPrepPdfExporter() )->collect( $this->request( $user_id ) );
    }

    private function request( int $user_id ): ExportRequest {
        wp_set_current_user( $user_id );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        return new ExportRequest( 'match_prep_pdf', 'pdf', $this->club, $user_id, null, [ 'activity_id' => $this->activity ] );
    }

    private function coachOf( int $team_id ): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Prepdf', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $team_id, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        return $coach;
    }
}
