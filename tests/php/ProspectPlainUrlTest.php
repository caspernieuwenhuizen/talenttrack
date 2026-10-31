<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Prospects\Domain\ProspectRecord;
use TT\Modules\Prospects\Domain\ProspectStages;
use TT\Modules\Prospects\Frontend\FrontendProspectDetailView;

/**
 * #4477 — the prospect's next action is a plain URL in the domain layer.
 *
 * `BackLink::appendTo()` reads the page being served, so a domain method
 * that called it answered differently depending on which page asked. The
 * domain now returns the plain target; the record page adds `tt_back` once,
 * when it renders the link.
 */
final class ProspectPlainUrlTest extends WP_UnitTestCase {

    private int $admin    = 0;
    private int $prospect = 0;
    private int $task     = 0;

    /** @var array<string,mixed> */
    private array $server_was = [];

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new RolesService() )->installRoles();
        $this->server_was = $_SERVER;

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );

        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'       => 1,
            'first_name'    => 'Plain',
            'last_name'     => 'Urlx',
            'current_club'  => 'VV Voorbeeld',
            'discovered_at' => current_time( 'Y-m-d' ),
        ] );
        $this->prospect = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_workflow_tasks", [
            'club_id'          => 1,
            'template_key'     => 'log_prospect',
            'assignee_user_id' => $this->admin,
            'status'           => 'open',
            'due_at'           => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
            'prospect_id'      => $this->prospect,
        ] );
        $this->task = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_SERVER = $this->server_was;
        unset( $_GET['id'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_next_url_is_the_same_from_any_page_and_carries_no_back_chain(): void {
        $this->servedFrom( '/?tt_view=onboarding-pipeline', home_url( '/?tt_view=players' ) );
        $first = ProspectRecord::stage( $this->admin, $this->prospect );

        $this->servedFrom( '/?tt_view=prospect&id=' . $this->prospect, home_url( '/?tt_view=my-tasks' ) );
        $second = ProspectRecord::stage( $this->admin, $this->prospect );

        $this->assertNotNull( $first );
        $this->assertNotNull( $second );
        $this->assertStringContainsString( 'task_id=' . $this->task, $first['next_url'] );
        $this->assertSame( $first['next_url'], $second['next_url'] );
        $this->assertStringNotContainsString( 'tt_back', $first['next_url'] );
    }

    public function test_board_cards_carry_plain_urls(): void {
        $this->servedFrom( '/?tt_view=onboarding-pipeline', '' );
        foreach ( ProspectStages::computeStages( $this->admin, $this->prospect ) as $column ) {
            foreach ( $column['cards'] as $card ) {
                $this->assertStringNotContainsString( 'tt_back', (string) $card['url'] );
            }
        }
        $this->assertStringNotContainsString( 'tt_back', ProspectStages::prospectFocusUrl( $this->prospect ) );
    }

    public function test_the_stage_route_is_unchanged(): void {
        $this->servedFrom( '/wp-json/talenttrack/v1/prospects/' . $this->prospect . '/stage', home_url( '/?tt_view=players' ) );
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/prospects/' . $this->prospect . '/stage' ) );

        $this->assertSame( 200, $response->get_status() );
        $data  = (array) $response->get_data();
        $stage = (array) ( ( (array) ( $data['data'] ?? [] ) )['stage'] ?? [] );
        $this->assertSame( [ 'key', 'label', 'summary', 'next_task_id' ], array_keys( $stage ) );
        $this->assertSame( $this->task, $stage['next_task_id'] );
    }

    public function test_the_record_pages_next_action_carries_exactly_one_back_hint(): void {
        $this->servedFrom( '/?tt_view=prospect&id=' . $this->prospect, '' );
        $_GET['id'] = (string) $this->prospect;

        ob_start();
        FrontendProspectDetailView::render( $this->admin );
        $html = (string) ob_get_clean();

        $this->assertSame( 1, preg_match( '#<p class="tt-prospect-record-next"><a [^>]*href="([^"]+)"#', $html, $m ), 'the next action renders' );
        $href = html_entity_decode( $m[1] );
        $this->assertStringContainsString( 'task_id=' . $this->task, $href );
        $this->assertSame( 1, substr_count( $href, 'tt_back=' ), 'one back hint, added at render' );
    }

    private function servedFrom( string $request_uri, string $referer ): void {
        $_SERVER['REQUEST_URI'] = $request_uri;
        if ( $referer === '' ) {
            unset( $_SERVER['HTTP_REFERER'] );
        } else {
            $_SERVER['HTTP_REFERER'] = $referer;
        }
    }
}
