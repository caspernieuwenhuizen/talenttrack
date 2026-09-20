<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Prospects\Domain\ProposeTestTrainingService;
use TT\Modules\Prospects\Frontend\FrontendOnboardingPipelineView;
use TT\Modules\Prospects\Repositories\ProspectsRepository;

/**
 * #3710 — a prospect with no open pipeline task could not be invited to a
 * test training. `invite_to_test_training` is the only link between a
 * prospect and one, and it was only ever spawned by the chain, so every
 * prospect whose chain was cancelled, never ran, or was seeded by the demo
 * generator sat in the first column with nothing to click.
 *
 * The scout proposes, the head of development decides: the proposal spawns
 * the HoD's invite task, and it is idempotent — two scouts on the same
 * prospect produce one task, not two.
 */
final class ProposeTestTrainingTest extends WP_UnitTestCase {

    private int $scout = 0;
    private int $hod   = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        FeatureRegistry::setEnabled( 'onboarding_pipeline_workflow', true );
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->hod   = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->scout = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        wp_set_current_user( $this->scout );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_stuck_prospect_can_be_put_forward(): void {
        $id = $this->prospect();
        $this->assertTrue( ProposeTestTrainingService::canPropose( $this->scout, $id ) );

        $task_id = ProposeTestTrainingService::propose( $this->scout, $id );

        $this->assertIsInt( $task_id );
        $this->assertGreaterThan( 0, $task_id );
        $this->assertSame( $task_id, ProposeTestTrainingService::openInviteTaskId( $id ) );
        $this->assertSame( 'invite_to_test_training', $this->taskColumn( $task_id, 'template_key' ) );
        $this->assertSame( $id, (int) $this->taskColumn( $task_id, 'prospect_id' ) );
    }

    public function test_proposing_twice_produces_one_open_task(): void {
        $id = $this->prospect();

        $first = ProposeTestTrainingService::propose( $this->scout, $id );

        $second_scout = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        wp_set_current_user( $second_scout );
        $second = ProposeTestTrainingService::propose( $second_scout, $id );

        $this->assertSame( $first, $second, 'the second proposal returns the first task' );
        $this->assertSame( 1, $this->openInviteCount( $id ) );
    }

    public function test_a_prospect_already_moving_is_not_proposable(): void {
        $id = $this->prospect();
        ProposeTestTrainingService::propose( $this->scout, $id );

        $this->assertFalse(
            ProposeTestTrainingService::canPropose( $this->scout, $id ),
            'a prospect with the invite already open is past proposing'
        );
    }

    public function test_the_feature_being_off_takes_the_action_away(): void {
        $id = $this->prospect();
        FeatureRegistry::setEnabled( 'onboarding_pipeline_workflow', false );

        $this->assertFalse( ProposeTestTrainingService::canPropose( $this->scout, $id ) );
        $this->assertInstanceOf( \WP_Error::class, ProposeTestTrainingService::propose( $this->scout, $id ) );
        $this->assertSame( 0, $this->openInviteCount( $id ) );

        FeatureRegistry::setEnabled( 'onboarding_pipeline_workflow', true );
    }

    public function test_a_viewer_without_the_prospect_capability_cannot_propose(): void {
        $id      = $this->prospect();
        $outside = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertFalse( ProposeTestTrainingService::canPropose( $outside, $id ) );
        $this->assertInstanceOf( \WP_Error::class, ProposeTestTrainingService::propose( $outside, $id ) );
        $this->assertSame( 0, $this->openInviteCount( $id ) );
    }

    public function test_the_rest_route_proposes_once_and_reports_the_repeat(): void {
        $id = $this->prospect();

        [ $data, $status ] = $this->send( 'POST', 'prospects/' . $id . '/test-training-proposal' );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['data']['created'] );
        $task_id = (int) $data['data']['task_id'];
        $this->assertGreaterThan( 0, $task_id );

        [ $again, $status ] = $this->send( 'POST', 'prospects/' . $id . '/test-training-proposal' );
        $this->assertSame( 200, $status );
        $this->assertFalse( $again['data']['created'] );
        $this->assertSame( $task_id, (int) $again['data']['task_id'] );
        $this->assertSame( 1, $this->openInviteCount( $id ) );
    }

    public function test_the_rest_route_answers_404_for_an_unknown_prospect(): void {
        [ , $status ] = $this->send( 'POST', 'prospects/999999/test-training-proposal' );
        $this->assertSame( 404, $status );
    }

    public function test_the_focus_panel_offers_the_way_forward_and_stops_after(): void {
        $id = $this->prospect();

        $before = $this->renderPanel( $id );
        $this->assertStringContainsString( 'Propose test training', $before );

        ProposeTestTrainingService::propose( $this->scout, $id );

        $after = $this->renderPanel( $id );
        $this->assertStringNotContainsString( 'Propose test training', $after );
        $this->assertStringContainsString( 'Open next action', $after );
    }

    public function test_a_reader_who_cannot_edit_sees_no_new_button(): void {
        $id     = $this->prospect();
        $reader = self::factory()->user->create( [ 'role' => 'tt_head_coach' ] );
        wp_set_current_user( $reader );

        $this->assertFalse( ProposeTestTrainingService::canPropose( $reader, $id ) );
        $this->assertStringNotContainsString( 'Propose test training', $this->renderPanel( $id, $reader ) );
    }

    private function renderPanel( int $prospect_id, ?int $user_id = null ): string {
        $user_id = $user_id ?? $this->scout;
        $_GET['prospect_id'] = $prospect_id;
        ob_start();
        FrontendOnboardingPipelineView::render( $user_id );
        $html = (string) ob_get_clean();
        unset( $_GET['prospect_id'] );
        return $html;
    }

    private function prospect(): int {
        return ( new ProspectsRepository() )->create( [
            'first_name'            => 'Vastgelopen',
            'last_name'             => 'Talent',
            'discovered_by_user_id' => $this->scout,
        ] );
    }

    private function openInviteCount( int $prospect_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_workflow_tasks
              WHERE prospect_id = %d AND template_key = 'invite_to_test_training'
                AND status IN ('open','in_progress','overdue')",
            $prospect_id
        ) );
    }

    private function taskColumn( int $task_id, string $column ): string {
        global $wpdb;
        $sql = "SELECT {$column} FROM {$wpdb->prefix}tt_workflow_tasks WHERE id = %d";
        return (string) $wpdb->get_var( $wpdb->prepare( $sql, $task_id ) );
    }

    /**
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route ): array {
        $request  = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
