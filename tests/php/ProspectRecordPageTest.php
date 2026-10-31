<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Prospects\Alerts\ProspectConsentAwaitingAlert;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Domain\ConsentRequestLog;
use TT\Modules\Prospects\Domain\ProspectRecord;
use TT\Modules\Prospects\Frontend\FrontendProspectDetailView;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Modules\Prospects\Repositories\ProspectVisitObservationsRepository;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Modules\Wizards\Prospect\LogConsentRequestWizard;

/**
 * #4257 — a prospect's record page at `?tt_view=prospect&id=N`: the consent
 * trail, the scouting visits and the next action, for whoever may see the
 * prospect, and "not found" for anyone else. Plus the consent-request
 * wizard behind its "Log consent request" action.
 */
final class ProspectRecordPageTest extends WP_UnitTestCase {

    private int $scout   = 0;
    private int $coach   = 0;
    private int $outside = 0;
    private int $own     = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->scout = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Record U14', 'age_group' => 'U14' ] );
        $this->coach = $this->makeHeadCoach( (int) $wpdb->insert_id );

        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id' => 1, 'first_name' => 'Far', 'last_name' => 'Awayx',
            'current_club' => 'VV Voorbeeld', 'discovered_at' => '2026-09-01', 'discovered_by_user_id' => $this->scout,
        ] );
        $this->outside = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id' => 1, 'first_name' => 'Own', 'last_name' => 'Findx', 'discovered_by_user_id' => $this->coach,
        ] );
        $this->own = (int) $wpdb->insert_id;
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        unset( $_GET['id'] );
        parent::tear_down();
    }

    // ── the page ───────────────────────────────────────────────────────

    public function test_the_consent_alert_opens_the_record_page_and_it_shows_the_trail(): void {
        ( new ProspectConsentRequestsRepository() )->create( $this->outside, '2026-09-10', 'Youth coordinator VV Voorbeeld', ConsentOutcome::AWAITING );

        $alert = new \ReflectionMethod( ProspectConsentAwaitingAlert::class, 'urlFor' );
        $alert->setAccessible( true );
        $url = (string) $alert->invoke( new ProspectConsentAwaitingAlert(), (object) [ 'subject_id' => $this->outside ] );
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
        $this->assertSame( 'prospect', $query['tt_view'] ?? null );
        $this->assertSame( (string) $this->outside, (string) ( $query['id'] ?? '' ) );

        $html = $this->renderFor( $this->scout, $this->outside );
        $this->assertStringContainsString( 'Youth coordinator VV Voorbeeld', $html );
        $this->assertStringContainsString( ConsentOutcome::label( ConsentOutcome::AWAITING ), $html );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'CLAUDE.md §5 — the page renders the chain' );
    }

    public function test_a_reader_without_the_edit_right_opens_it_and_gets_no_write_actions(): void {
        $this->assertFalse( AuthorizationService::userCanOrMatrix( $this->coach, 'tt_edit_prospects' ), 'precondition: the head coach only reads prospects' );
        $html = $this->renderFor( $this->coach, $this->own );

        $this->assertStringNotContainsString( 'Not authorized', $html );
        $this->assertStringNotContainsString( 'Prospect not found', $html );
        $this->assertStringContainsString( 'Own Findx', $html );
        $this->assertStringNotContainsString( 'Log consent request', $html );
        $this->assertStringNotContainsString( 'Edit contact', $html );
    }

    public function test_a_prospect_outside_the_scope_reads_like_one_that_does_not_exist(): void {
        $refused = $this->renderFor( $this->coach, $this->outside );
        $missing = $this->renderFor( $this->coach, $this->outside + 100000 );

        $this->assertStringContainsString( 'Prospect not found', $refused );
        $this->assertStringNotContainsString( 'Far Awayx', $refused );
        $this->assertSame( $missing, $refused );
    }

    public function test_an_editor_gets_both_actions(): void {
        $html = $this->renderFor( $this->scout, $this->outside );
        $this->assertStringContainsString( 'Log consent request', $html );
        $this->assertStringContainsString( 'tt_wizard=' . LogConsentRequestWizard::SLUG, $html );
        $this->assertStringContainsString( 'Edit contact', $html );
    }

    public function test_the_page_lists_the_visits_the_viewer_may_open(): void {
        $visit = ( new ScoutingVisitsRepository() )->create( [
            'scout_user_id' => $this->scout,
            'visit_date'    => '2026-09-01',
            'location'      => 'Sportpark Nieuwland',
            'status'        => ScoutingVisitsRepository::STATUS_COMPLETED,
        ] );
        ( new ProspectVisitObservationsRepository() )->link( $this->outside, $visit );
        ( new ProspectVisitObservationsRepository() )->link( $this->own, $visit );

        $this->assertStringContainsString( 'Sportpark Nieuwland', $this->renderFor( $this->scout, $this->outside ), 'the scout who made the visit' );
        $this->assertSame( [], ProspectRecord::visits( $this->coach, $this->own ), 'a head coach does not read the scout\'s visit planner' );
    }

    // ── the consent request ────────────────────────────────────────────

    public function test_logging_a_request_writes_an_awaiting_entry(): void {
        wp_set_current_user( $this->scout );
        $id = ConsentRequestLog::record( $this->scout, $this->outside, current_time( 'Y-m-d' ), 'Youth coordinator', 'Rang on Monday' );

        $this->assertIsInt( $id );
        $row = ( new ProspectConsentRequestsRepository() )->findById( $id );
        $this->assertSame( ConsentOutcome::AWAITING, $row['outcome'] ?? null );
        $this->assertSame( 'Youth coordinator', $row['asked_of'] ?? null );
    }

    public function test_logging_refuses_a_future_date_and_a_missing_club(): void {
        wp_set_current_user( $this->scout );
        $future = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +2 days' ) );

        $this->assertWPError( ConsentRequestLog::record( $this->scout, $this->outside, $future, 'Youth coordinator' ) );
        $this->assertWPError( ConsentRequestLog::record( $this->scout, $this->outside, current_time( 'Y-m-d' ), '  ' ) );
        $this->assertSame( [], ( new ProspectConsentRequestsRepository() )->forProspect( $this->outside ), 'nothing stored' );
    }

    public function test_logging_refuses_a_prospect_the_user_may_not_open(): void {
        add_filter( 'user_has_cap', [ $this, 'grantEdit' ], 999, 4 );
        wp_set_current_user( $this->coach );
        $this->assertTrue( AuthorizationService::userCanOrMatrix( $this->coach, 'tt_edit_prospects' ), 'precondition: the edit right is held' );

        $result = ConsentRequestLog::record( $this->coach, $this->outside, current_time( 'Y-m-d' ), 'Youth coordinator' );
        $this->assertWPError( $result );
        $this->assertSame( 'prospect_not_found', $result->get_error_code() );
        $this->assertSame( [], ( new LogConsentRequestWizard() )->initialState( [ 'prospect_id' => (string) $this->outside ] ), 'the wizard does not take it from the URL either' );
        $this->assertSame( [ 'prospect_id' => $this->own ], ( new LogConsentRequestWizard() )->initialState( [ 'prospect_id' => (string) $this->own ] ) );

        remove_filter( 'user_has_cap', [ $this, 'grantEdit' ], 999 );
    }

    // ── REST ───────────────────────────────────────────────────────────

    public function test_the_visits_and_stage_routes_answer_inside_the_scope_only(): void {
        wp_set_current_user( $this->coach );
        $this->assertSame( 404, $this->get( '/prospects/' . $this->outside . '/visits' )->get_status() );
        $this->assertSame( 404, $this->get( '/prospects/' . $this->outside . '/stage' )->get_status() );

        $visits = $this->get( '/prospects/' . $this->own . '/visits' );
        $this->assertSame( 200, $visits->get_status() );
        $stage = $this->get( '/prospects/' . $this->own . '/stage' );
        $this->assertSame( 200, $stage->get_status() );
        $data = (array) $stage->get_data();
        $data = (array) ( $data['data'] ?? $data );
        $this->assertSame( 'prospects', ( (array) $data['stage'] )['key'] ?? null, 'a fresh prospect sits in the first column' );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /**
     * @param array<string,bool> $allcaps
     * @return array<string,bool>
     */
    public function grantEdit( array $allcaps, array $caps, array $args, \WP_User $user ): array {
        if ( (int) $user->ID === $this->coach ) $allcaps['tt_edit_prospects'] = true;
        return $allcaps;
    }

    private function get( string $route ): \WP_REST_Response {
        return rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1' . $route ) );
    }

    private function renderFor( int $user_id, int $prospect_id ): string {
        wp_set_current_user( $user_id );
        $_GET['id'] = (string) $prospect_id;
        ob_start();
        FrontendProspectDetailView::render( $user_id );
        return (string) ob_get_clean();
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Hoofd', 'last_name' => 'Trainer',
            'role_type' => 'head_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'team_id' => $team_id, 'person_id' => $person_id, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }
}
