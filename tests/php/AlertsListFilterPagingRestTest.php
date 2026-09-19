<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * #3665 — narrowing `GET /alerts` to one definition, and reaching past the
 * first page of it.
 *
 * The paging case is the one worth proving row by row. A pager that repeats
 * or skips rows is worse than no pager: a coach who scrolls past page 1
 * believing they have seen everything will not go back, so an alert lost
 * between two pages is an alert nobody acts on. The assertions therefore
 * check that the three pages are disjoint AND that together they hold every
 * seeded row, not merely that each page has the right length.
 */
final class AlertsListFilterPagingRestTest extends WP_UnitTestCase {

    public const KEY   = 'test.paged_alert';
    public const OTHER = 'test.other_paged_alert';

    /** @var int */
    private $user;

    public function set_up(): void {
        parent::set_up();
        AlertOccurrencesRepository::flushTableCache();

        $this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_occurrences" );

        add_filter( 'tt_register_alerts', [ $this, 'registerStubs' ] );
        AlertRegistry::flush();

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        remove_filter( 'tt_register_alerts', [ $this, 'registerStubs' ] );
        AlertRegistry::flush();
        parent::tear_down();
    }

    /**
     * @param list<mixed> $alerts
     * @return list<mixed>
     */
    public function registerStubs( array $alerts ): array {
        $alerts[] = self::stub( self::KEY, 'paging' );
        $alerts[] = self::stub( self::OTHER, 'elsewhere' );
        return $alerts;
    }

    // ── the key filter ─────────────────────────────────────────────────

    public function test_alert_key_returns_only_that_definition(): void {
        $this->seed( self::KEY, 3 );
        $this->seed( self::OTHER, 2 );

        $response = $this->raw( [ 'alert_key' => self::KEY ] );
        $rows     = $this->rows( $response );

        $this->assertCount( 3, $rows );
        $this->assertSame( [ self::KEY ], array_values( array_unique( array_column( $rows, 'alert_key' ) ) ) );
        $this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
    }

    /**
     * An unknown key is a stale bookmark, not a server fault. It answers
     * "nothing", the same way an unknown module already did — and, crucially,
     * not "everything", which is what an unfiltered fall-through would give.
     */
    public function test_an_unknown_alert_key_returns_nothing_rather_than_everything(): void {
        $this->seed( self::KEY, 3 );

        $response = $this->raw( [ 'alert_key' => 'nonsense.not_a_definition' ] );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [], $this->rows( $response ) );
        $this->assertSame( '0', $response->get_headers()['X-WP-Total'] );
    }

    public function test_a_key_outside_the_requested_module_returns_nothing(): void {
        $this->seed( self::KEY, 3 );

        $response = $this->raw( [ 'alert_key' => self::KEY, 'module' => 'elsewhere' ] );

        $this->assertSame( [], $this->rows( $response ) );
        $this->assertSame( '0', $response->get_headers()['X-WP-Total'] );
    }

    public function test_a_key_inside_the_requested_module_still_filters(): void {
        $this->seed( self::KEY, 3 );
        $this->seed( self::OTHER, 2 );

        $rows = $this->rows( $this->raw( [ 'alert_key' => self::KEY, 'module' => 'paging' ] ) );

        $this->assertCount( 3, $rows );
    }

    // ── paging ─────────────────────────────────────────────────────────

    public function test_pages_are_disjoint_and_together_hold_every_alert(): void {
        $this->seed( self::KEY, 45 );

        $seen = [];
        foreach ( [ 1 => 20, 2 => 20, 3 => 5 ] as $page => $expected ) {
            $response = $this->raw( [ 'per_page' => 20, 'page' => $page ] );
            $rows     = $this->rows( $response );
            $headers  = $response->get_headers();

            $this->assertCount( $expected, $rows, "page {$page} length" );
            $this->assertSame( '45', $headers['X-WP-Total'], "page {$page} total" );
            $this->assertSame( '3', $headers['X-WP-TotalPages'], "page {$page} page count" );

            $uuids = array_column( $rows, 'uuid' );
            $this->assertSame(
                [],
                array_intersect( $seen, $uuids ),
                "page {$page} repeats a row from an earlier page"
            );
            $seen = array_merge( $seen, $uuids );
        }

        $this->assertCount( 45, array_unique( $seen ), 'every seeded alert is reachable across the pages' );
    }

    /**
     * The body stays a plain array. The total travels in headers precisely
     * so v1 consumers that iterate the response keep working.
     */
    public function test_the_body_stays_a_plain_list(): void {
        $this->seed( self::KEY, 3 );

        $data = $this->raw( [] )->get_data();
        $rows = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;

        $this->assertIsArray( $rows );
        $this->assertSame( range( 0, 2 ), array_keys( $rows ) );
    }

    public function test_a_page_past_the_end_is_empty_but_still_reports_the_total(): void {
        $this->seed( self::KEY, 3 );

        $response = $this->raw( [ 'per_page' => 20, 'page' => 9 ] );

        $this->assertSame( [], $this->rows( $response ) );
        $this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
    }

    /**
     * The window must never become a way to read someone else's list, and
     * their rows must not inflate the total either — a page count that
     * promises rows the list will not serve is its own bug.
     */
    public function test_another_users_alerts_neither_appear_nor_count(): void {
        $this->seed( self::KEY, 3 );
        $this->seed( self::KEY, 4, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $response = $this->raw( [ 'per_page' => 50 ] );

        $this->assertCount( 3, $this->rows( $response ) );
        $this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Rows are written straight to the table rather than through the
     * evaluator: 45 occurrences of one definition is a volume the stubs
     * exist to stand in for, not a sweep worth simulating.
     */
    private function seed( string $key, int $count, ?int $recipient = null ): void {
        global $wpdb;
        $now  = current_time( 'mysql' );
        $user = $recipient ?? $this->user;

        for ( $i = 0; $i < $count; $i++ ) {
            $wpdb->insert( $wpdb->prefix . 'tt_alert_occurrences', [
                'uuid'              => wp_generate_uuid4(),
                'club_id'           => 1,
                'alert_key'         => $key,
                'recipient_user_id' => $user,
                'subject_type'      => 'activity',
                'subject_id'        => $i + 1,
                'dedupe_key'        => $key . '|' . $user . '|' . ( $i + 1 ),
                'severity'          => Severity::ATTENTION,
                'payload_json'      => wp_json_encode( [ 'title' => 'Seeded alert ' . ( $i + 1 ) ] ),
                'first_seen_at'     => $now,
                'last_seen_at'      => $now,
            ] );
        }
    }

    /** @param array<string,mixed> $params */
    private function raw( array $params ): \WP_REST_Response {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/alerts' );
        foreach ( $params as $k => $v ) {
            $request->set_param( $k, $v );
        }
        return rest_get_server()->dispatch( $request );
    }

    /** @return list<array<string,mixed>> */
    private function rows( \WP_REST_Response $response ): array {
        $data = $response->get_data();
        $rows = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
        return array_values( is_array( $rows ) ? $rows : [] );
    }

    private static function stub( string $key, string $module ): AlertInterface {
        return new class( $key, $module ) implements AlertInterface {
            /** @var string */ private $key;
            /** @var string */ private $module;
            public function __construct( string $key, string $module ) {
                $this->key    = $key;
                $this->module = $module;
            }

            public function key(): string { return $this->key; }
            public function module(): string { return $this->module; }
            public function subjectType(): string { return 'activity'; }
            public function label(): string { return 'Paging stub'; }
            public function description(): string { return 'Stub for the alerts list paging tests.'; }
            public function defaultSeverity(): string { return Severity::ATTENTION; }
            public function capRequired(): string { return ''; }
            public function defaultSurfaces(): array { return [ Surface::BADGE ]; }
            public function isOperational(): bool { return false; }

            /** @return list<AlertOccurrence> */
            public function evaluate( AlertContext $context ): array { return []; }
        };
    }
}
