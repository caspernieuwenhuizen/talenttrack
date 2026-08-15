<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Services\ActivityCompletionResolver;
use TT\Modules\Activities\Services\ActivityGridLink;

/**
 * #2401 / #2407 — the wizard-off completion path.
 *
 * Switching `tt_wizards_enabled` off used to strand an activity: the
 * completion resolver produced an empty URL (so every render surface
 * correctly hid "Complete activity", leaving no affordance at all), and
 * nothing on the remaining grid path ever wrote `completed`.
 *
 * These tests pin both halves of the fix and, just as importantly, that
 * the wizard-ON behaviour is unchanged.
 */
final class WizardOffCompletionRoutingTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 9401;
    private const TEAM_ID     = 941;
    private const THE_DATE    = '2026-03-14';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->seedActivity();

        // Default posture for these tests: grids on. Individual tests
        // flip the wizard config.
        FeatureRegistry::setEnabled( 'attendance_grid', true );
    }

    public function tear_down(): void {
        QueryHelpers::set_config( 'tt_wizards_enabled', 'all' );
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private function seedActivity(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'             => 1,
            'id'                  => self::ACTIVITY_ID,
            'team_id'             => self::TEAM_ID,
            'title'               => 'Wizard-off training',
            'session_date'        => self::THE_DATE,
            'activity_type_key'   => 'training',
            'activity_status_key' => ActivityStatusKey::PLANNED,
            'plan_state'          => 'scheduled',
        ] );
    }

    private function currentStatus(): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT activity_status_key FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            self::ACTIVITY_ID
        ) );
    }

    private function postStatus( string $status ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/activities/' . self::ACTIVITY_ID . '/status' );
        $req->set_body_params( [ 'status' => $status ] );
        return rest_get_server()->dispatch( $req );
    }

    /**
     * The reported symptom: with the wizard off the completion affordance
     * disappeared entirely because `canComplete()` gated on the wizard
     * alone. It must now hold on the grid instead.
     */
    public function test_can_complete_holds_when_wizard_off_but_grid_on(): void {
        QueryHelpers::set_config( 'tt_wizards_enabled', 'off' );

        $this->assertTrue(
            ActivityCompletionResolver::canComplete( self::ACTIVITY_ID, 'training', get_current_user_id() ),
            'Wizard off + attendance grid on must still offer a completion affordance'
        );
    }

    /** Neither path reachable → the affordance stays hidden, not dead-clicking. */
    public function test_can_complete_false_when_wizard_and_grid_both_off(): void {
        QueryHelpers::set_config( 'tt_wizards_enabled', 'off' );
        FeatureRegistry::setEnabled( 'attendance_grid', false );

        $this->assertFalse(
            ActivityCompletionResolver::canComplete( self::ACTIVITY_ID, 'training', get_current_user_id() ),
            'With no wizard and no grid there is nothing to route to'
        );
    }

    /** The wizard-off destination is the grid, narrowed to this activity. */
    public function test_completion_url_points_at_the_grid_column_when_wizard_off(): void {
        QueryHelpers::set_config( 'tt_wizards_enabled', 'off' );

        $url = ActivityCompletionResolver::completionUrl( self::ACTIVITY_ID, 'training' );

        $this->assertStringContainsString( 'tt_view=attendance-grid', $url );
        $this->assertStringContainsString( 'team_id=' . self::TEAM_ID, $url );
        $this->assertStringContainsString( 'from=' . self::THE_DATE, $url );
        $this->assertStringContainsString( 'to=' . self::THE_DATE, $url );
    }

    /**
     * The button names its real destination — "Complete activity" would
     * over-promise, since the grid records attendance without completing.
     */
    public function test_completion_label_names_the_grid_when_wizard_off(): void {
        QueryHelpers::set_config( 'tt_wizards_enabled', 'all' );
        $this->assertSame(
            'Complete activity',
            ActivityCompletionResolver::completionLabel( self::ACTIVITY_ID, 'training', get_current_user_id() )
        );

        QueryHelpers::set_config( 'tt_wizards_enabled', 'off' );
        $this->assertSame(
            'Mark attendance',
            ActivityCompletionResolver::completionLabel( self::ACTIVITY_ID, 'training', get_current_user_id() )
        );
    }

    /**
     * A club-wide activity has no roster to grid, so no affordance —
     * §7 says hide it, never render a link that resolves to nothing.
     * Uses its own id so the primed anchor can't leak into the other
     * tests (the memo is per-request, and a test run is one request).
     */
    public function test_grid_link_declines_a_team_less_activity(): void {
        QueryHelpers::set_config( 'tt_wizards_enabled', 'off' );
        $club_wide = self::ACTIVITY_ID + 1;
        ActivityGridLink::primeAnchor( $club_wide, 0, self::THE_DATE );

        $this->assertFalse( ActivityGridLink::canUseAttendance( $club_wide, get_current_user_id() ) );
        $this->assertSame( '', ActivityGridLink::attendanceUrl( $club_wide ) );
        $this->assertFalse(
            ActivityCompletionResolver::canComplete( $club_wide, 'training', get_current_user_id() ),
            'No wizard and no griddable team means no completion affordance'
        );
    }

    /**
     * #2407 — with the wizard off, the status endpoint is the only way an
     * activity can reach `completed`, so it must accept it.
     */
    public function test_status_endpoint_accepts_completed_when_wizard_off(): void {
        QueryHelpers::set_config( 'tt_wizards_enabled', 'off' );

        $res = $this->postStatus( ActivityStatusKey::COMPLETED );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( ActivityStatusKey::COMPLETED, $this->currentStatus() );
    }

    /**
     * ...and with the wizard on it stays rejected, so completion keeps
     * running through the flow that actually records attendance.
     */
    public function test_status_endpoint_still_rejects_completed_when_wizard_on(): void {
        QueryHelpers::set_config( 'tt_wizards_enabled', 'all' );

        $res = $this->postStatus( ActivityStatusKey::COMPLETED );

        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( ActivityStatusKey::PLANNED, $this->currentStatus() );
    }

    /** Cancel / Reopen are unaffected by the toggle. */
    public function test_cancel_and_reopen_work_regardless_of_wizard_state(): void {
        QueryHelpers::set_config( 'tt_wizards_enabled', 'all' );

        $this->assertSame( 200, $this->postStatus( ActivityStatusKey::CANCELLED )->get_status() );
        $this->assertSame( ActivityStatusKey::CANCELLED, $this->currentStatus() );

        $this->assertSame( 200, $this->postStatus( ActivityStatusKey::PLANNED )->get_status() );
        $this->assertSame( ActivityStatusKey::PLANNED, $this->currentStatus() );
    }
}
