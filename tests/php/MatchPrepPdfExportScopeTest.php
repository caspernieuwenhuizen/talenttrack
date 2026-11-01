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
use TT\Modules\MatchPrep\Export\MatchPrepPdfExporter;
use TT\Modules\MatchPrep\Frontend\FrontendMatchPrepView;
use TT\Modules\MatchPrep\Print\MatchPrepPrintRouter;
use TT\Modules\MatchPrep\Rest\MatchPrepRestController;

/**
 * The match prep is the team's plan: line-up, focus per player, the
 * coach's notes. One rule covers its four surfaces. Reading it, on the
 * screen, through `GET match-prep/{id}`, on the print page and as the PDF,
 * asks the activity read rule; changing it asks the write rule. So a
 * club-wide reader such as the read-only observer reads every team's prep
 * as a document, without the coach's free-text reasons, and changes none;
 * the team's own coach edits it; a coach of another team without
 * club-wide read gets none of it.
 */
final class MatchPrepPdfExportScopeTest extends WP_UnitTestCase {

    private const REASON = 'Prepdfkneeinjury';

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
        $present = $this->player( 'Prepdfplayer' );
        $absent  = $this->player( 'Prepdfabsent' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $put = new WP_REST_Request( 'PUT', '/talenttrack/v1/match-prep/' . $this->activity );
        $put->set_header( 'content-type', 'application/json' );
        $put->set_body( (string) wp_json_encode( [ 'availability' => [
            $present => [ 'status' => 'Present', 'reason' => '' ],
            $absent  => [ 'status' => 'Absent', 'reason' => self::REASON ],
        ] ] ) );
        $this->assertSame( 200, rest_do_request( $put )->get_status(), 'fixture: the prep was saved' );
        wp_set_current_user( 0 );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_GET = [];
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_another_teams_coach_is_refused_every_surface(): void {
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

        $view = $this->view( $coach );
        $this->assertStringContainsString( 'do not coach', $view );
        $this->assertStringNotContainsString( 'Prepdfplayer', $view );
        $this->assertStringNotContainsString( 'Prepdfplayer', MatchPrepPrintRouter::renderHtml( $this->activity ) );
        $this->assertFalse( MatchPrepRestController::can_edit( $this->request( 'GET' ) ) );
    }

    public function test_the_teams_own_coach_edits_and_reads_the_reason(): void {
        $coach = $this->coachOf( $this->team );

        $view = $this->view( $coach );
        $this->assertStringContainsString( 'tt-match-prep-bootstrap', $view, 'the editor opens' );
        $this->assertStringNotContainsString( 'tt-mp-readonly-note', $view );
        $this->assertTrue( MatchPrepRestController::can_edit( $this->request( 'PUT' ) ) );

        $out = $this->export( $coach );
        $this->assertStringContainsString( 'Prepdfplayer', (string) ( $out['html'] ?? '' ) );
        $this->assertStringContainsString( self::REASON, (string) ( $out['html'] ?? '' ), 'who plans the team reads the reason' );
    }

    public function test_the_read_only_observer_reads_the_prep_without_the_reason(): void {
        $observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->assertTrue( \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsActivities( $observer ), 'precondition: the seat reads every team' );

        $view = $this->view( $observer );
        $this->assertStringContainsString( 'tt-mp-readonly-note', $view, 'the screen opens read-only' );
        $this->assertStringContainsString( 'Prepdfplayer', $view );
        $this->assertStringContainsString( 'tt-breadcrumbs', $view, 'CLAUDE.md §5: the read-only screen renders the chain' );
        $this->assertStringNotContainsString( 'tt-match-prep-bootstrap', $view, 'no editor' );
        $this->assertStringNotContainsString( 'data-tt-mp-goal', $view, 'no inputs' );
        $this->assertStringNotContainsString( self::REASON, $view );

        $out = $this->export( $observer );
        $this->assertStringContainsString( 'Prepdfplayer', (string) ( $out['html'] ?? '' ), 'the observer gets the PDF' );
        $this->assertStringNotContainsString( self::REASON, (string) ( $out['html'] ?? '' ) );

        $print = MatchPrepPrintRouter::renderHtml( $this->activity );
        $this->assertStringContainsString( 'Prepdfplayer', $print );
        $this->assertStringNotContainsString( self::REASON, $print );

        $this->assertTrue( MatchPrepRestController::can_edit( $this->request( 'GET' ) ), 'REST reads the prep' );
        $this->assertFalse( MatchPrepRestController::can_edit( $this->request( 'PUT' ) ), 'REST does not change it' );
        $get = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/match-prep/' . $this->activity ) );
        $this->assertSame( 200, $get->get_status() );
        $this->assertStringNotContainsString( self::REASON, (string) wp_json_encode( $get->get_data() ) );
    }

    public function test_the_head_of_development_gets_the_prep_pdf(): void {
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $out = $this->export( $hod );
        $this->assertStringContainsString( 'Prepdfplayer', (string) ( $out['html'] ?? '' ) );
    }

    /** @return array<string, mixed> */
    private function export( int $user_id ): array {
        $this->as( $user_id );
        return ( new MatchPrepPdfExporter() )->collect(
            new ExportRequest( 'match_prep_pdf', 'pdf', $this->club, $user_id, null, [ 'activity_id' => $this->activity ] )
        );
    }

    private function view( int $user_id ): string {
        $this->as( $user_id );
        $_GET = [ 'tt_view' => 'match-prep', 'activity_id' => (string) $this->activity ];
        ob_start();
        FrontendMatchPrepView::render( $user_id, false );
        return (string) ob_get_clean();
    }

    private function request( string $method ): WP_REST_Request {
        $r = new WP_REST_Request( $method, '/talenttrack/v1/match-prep/' . $this->activity );
        $r->set_param( 'activity_id', $this->activity );
        return $r;
    }

    private function as( int $user_id ): void {
        wp_set_current_user( $user_id );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }

    private function player( string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => $first,
            'last_name' => 'Kidx', 'status' => 'active',
            'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the player row was written' );
        return $id;
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
