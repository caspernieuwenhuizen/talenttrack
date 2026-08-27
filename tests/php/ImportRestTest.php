<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\REST\ImportRestController;
use TT\Modules\Import\ImportBatchRegistry;

/**
 * #2956 — REST surface for spreadsheet import.
 *
 * Authorization gets the most attention. This endpoint creates player,
 * team and staff records wholesale from an uploaded file, so "who may call
 * it" is the question worth proving; a subscriber reaching it would be able
 * to write a club's roster.
 */
final class ImportRestTest extends WP_UnitTestCase {

    /** @var int */
    private $admin;

    public function set_up(): void {
        parent::set_up();

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

        // Register through the action rather than calling register()
        // directly — WordPress emits a doing_it_wrong notice for routes
        // registered outside `rest_api_init`, and the test suite treats
        // those notices as failures.
        ImportRestController::init();
        do_action( 'rest_api_init' );
    }

    public function test_routes_are_registered(): void {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey( '/talenttrack/v1/imports', $routes );
    }

    public function test_listing_requires_the_capability(): void {
        wp_set_current_user( 0 );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/imports' )
        );

        $this->assertSame( 401, $response->get_status() );
    }

    public function test_a_subscriber_cannot_import(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'POST', '/talenttrack/v1/imports' )
        );

        $this->assertSame( 403, $response->get_status() );
    }

    public function test_an_administrator_can_list_batches(): void {
        wp_set_current_user( $this->admin );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/imports' )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertArrayHasKey( 'batches', $response->get_data() );
    }

    public function test_listing_reports_a_recorded_batch(): void {
        wp_set_current_user( $this->admin );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Ajax U17' ] );
        $registry = new ImportBatchRegistry( 'rest-batch', 'squad.xlsx' );
        $registry->tag( 'team', (int) $wpdb->insert_id );
        $registry->recordCounts( [ 'teams' => 1 ] );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/imports' )
        );

        $batches = $response->get_data()['batches'];
        $this->assertCount( 1, $batches );
        $this->assertSame( 'squad.xlsx', $batches[0]['source_filename'] );
        $this->assertSame( [ 'teams' => 1 ], $batches[0]['counts'] );
    }

    public function test_posting_without_a_file_is_a_clean_400(): void {
        wp_set_current_user( $this->admin );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'POST', '/talenttrack/v1/imports' )
        );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'tt_import_no_file', $response->as_error()->get_error_code() );
    }
}
