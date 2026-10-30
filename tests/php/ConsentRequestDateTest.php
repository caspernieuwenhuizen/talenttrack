<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Prospects\Domain\ConsentRequestDate;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Modules\Workflow\Forms\RequestConsentForm;

/**
 * #4267 — a consent request's date must be one the academy can have asked on.
 *
 * The checks were format-only, so a request dated in the future, or a
 * mistyped year, was stored as given, and the waiting counter and the
 * overdue alert read it as months of silence. Every write path now asks
 * `ConsentRequestDate::validate()`: a real date, not later than today.
 * A date before the prospect was entered is accepted, because a scout may
 * ask the child's club before logging them.
 */
final class ConsentRequestDateTest extends WP_UnitTestCase {

    private int $prospect_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'       => 1,
            'first_name'    => 'Dated',
            'last_name'     => 'Prospect',
            'current_club'  => 'VV Voorbeeld',
            'discovered_at' => $this->today(),
        ] );
        $this->prospect_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $this->prospect_id );

        do_action( 'rest_api_init' );
    }

    private function today(): string {
        return current_time( 'Y-m-d' );
    }

    private function shift( int $days ): string {
        return gmdate( 'Y-m-d', (int) strtotime( $this->today() . ' ' . sprintf( '%+d', $days ) . ' days' ) );
    }

    private function post( string $asked_at ): \WP_REST_Response {
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/prospects/' . $this->prospect_id . '/consent-requests' );
        $request->set_body_params( [ 'asked_at' => $asked_at, 'asked_of' => 'Youth coordinator', 'outcome' => 'awaiting' ] );
        return rest_do_request( $request );
    }

    private function stored(): int {
        return count( ( new ProspectConsentRequestsRepository() )->forProspect( $this->prospect_id ) );
    }

    // ── REST ───────────────────────────────────────────────────────────

    public function test_today_is_accepted(): void {
        $this->assertLessThan( 300, $this->post( $this->today() )->get_status() );
        $this->assertSame( 1, $this->stored() );
    }

    public function test_tomorrow_is_refused_and_nothing_is_stored(): void {
        $response = $this->post( $this->shift( 1 ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'invalid_asked_at', $response->get_data()['code'] ?? ( $response->get_data()['error']['code'] ?? null ) );
        $this->assertSame( 0, $this->stored() );
    }

    public function test_a_date_that_does_not_exist_is_refused(): void {
        $this->assertSame( 400, $this->post( '2026-02-30' )->get_status() );
        $this->assertSame( 0, $this->stored() );
    }

    public function test_a_date_before_the_prospect_was_entered_is_accepted(): void {
        $this->assertLessThan( 300, $this->post( $this->shift( -1 ) )->get_status(), 'the club may have been asked before the child was logged' );
        $this->assertSame( 1, $this->stored() );
    }

    // ── the other write paths ──────────────────────────────────────────

    public function test_the_workflow_form_puts_the_error_on_the_date_field(): void {
        $form = new RequestConsentForm();

        $errors = $form->validate( [ 'asked_at' => $this->shift( 1 ), 'asked_of' => 'Club', 'outcome' => 'awaiting' ], [] );
        $this->assertArrayHasKey( 'asked_at', $errors );

        $this->assertSame( [], $form->validate( [ 'asked_at' => $this->today(), 'asked_of' => 'Club', 'outcome' => 'awaiting' ], [] ) );
    }

    public function test_the_form_date_input_carries_todays_max(): void {
        $html = ( new RequestConsentForm() )->render( [ 'prospect_id' => $this->prospect_id ] );
        $this->assertStringContainsString( 'max="' . $this->today() . '"', $html );
    }

    public function test_the_repository_guard_refuses_a_future_date(): void {
        $id = ( new ProspectConsentRequestsRepository() )->create( $this->prospect_id, $this->shift( 1 ), 'Club', 'awaiting' );
        $this->assertSame( 0, $id );
    }

    public function test_the_validator_takes_today_as_a_parameter(): void {
        $this->assertTrue( ConsentRequestDate::isValid( '2026-10-30', '2026-10-30' ) );
        $this->assertFalse( ConsentRequestDate::isValid( '2026-10-31', '2026-10-30' ) );
        $this->assertFalse( ConsentRequestDate::isValid( '30-10-2026', '2026-10-30' ) );
    }
}
