<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Cron\AlertSweepCron;
use TT\Modules\Alerts\Diagnostics\AlertDiagnostics;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;

/**
 * #2634 — the diagnostic that answers "is the engine running" and "is this
 * definition doing more harm than good".
 *
 * The staleness cases matter most. A sweep that has stopped produces exactly
 * the same screens as an academy with nothing wrong — empty ones — so the
 * only thing distinguishing "healthy and quiet" from "broken and silent" is
 * this check. Getting it backwards would be worse than not having it.
 */
final class AlertDiagnosticsTest extends WP_UnitTestCase {

    public const KEY = 'test.diagnostic_alert';

    /** @var int */
    private $user;

    public function set_up(): void {
        parent::set_up();
        $this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_occurrences" );

        $config = new ConfigService();
        $config->set( AlertSweepCron::LAST_RUN_CONFIG_KEY, '' );
        $config->set( AlertSweepCron::LAST_STATS_CONFIG_KEY, '' );

        add_filter( 'tt_register_alerts', [ $this, 'registerStub' ] );
        AlertRegistry::flush();
    }

    public function tear_down(): void {
        remove_filter( 'tt_register_alerts', [ $this, 'registerStub' ] );
        AlertRegistry::flush();
        parent::tear_down();
    }

    /**
     * @param list<mixed> $alerts
     * @return list<mixed>
     */
    public function registerStub( array $alerts ): array {
        $alerts[] = self::stub();
        return $alerts;
    }

    // ── staleness ──────────────────────────────────────────────────────

    public function test_an_engine_that_has_never_run_reads_as_stale(): void {
        $diag = new AlertDiagnostics();

        $this->assertNull( $diag->lastSweepAt() );
        $this->assertTrue(
            $diag->sweepLooksStale(),
            'never having run must not read as healthy — that is indistinguishable from an empty academy'
        );
    }

    public function test_a_recent_sweep_reads_as_healthy(): void {
        ( new ConfigService() )->set( AlertSweepCron::LAST_RUN_CONFIG_KEY, (string) ( time() - 600 ) );

        $this->assertFalse( ( new AlertDiagnostics() )->sweepLooksStale() );
    }

    public function test_a_sweep_older_than_the_window_reads_as_stale(): void {
        ( new ConfigService() )->set( AlertSweepCron::LAST_RUN_CONFIG_KEY, (string) ( time() - 5 * HOUR_IN_SECONDS ) );

        $this->assertTrue( ( new AlertDiagnostics() )->sweepLooksStale() );
    }

    /**
     * The sweep self-throttles to hourly, so a gap of just over an hour is
     * normal operation and must not raise a warning. An admin trained to
     * ignore this panel is an admin who will ignore it when it is right.
     */
    public function test_a_sweep_just_over_an_hour_ago_is_not_stale(): void {
        ( new ConfigService() )->set( AlertSweepCron::LAST_RUN_CONFIG_KEY, (string) ( time() - 4000 ) );

        $this->assertFalse( ( new AlertDiagnostics() )->sweepLooksStale() );
    }

    // ── per-definition counts ──────────────────────────────────────────

    public function test_every_registered_definition_appears_even_with_no_occurrences(): void {
        $rows = ( new AlertDiagnostics() )->perDefinition();

        $this->assertArrayHasKey( self::KEY, $rows );
        $this->assertSame( 0, $rows[ self::KEY ]['open'] );
        $this->assertNull( $rows[ self::KEY ]['dismiss_rate'], 'no data is not a zero rate' );
    }

    public function test_counts_split_open_resolved_and_dismissed(): void {
        $this->seed( [ 1, 2, 3 ] );

        global $wpdb;
        $t = $wpdb->prefix . 'tt_alert_occurrences';
        $wpdb->query( "UPDATE {$t} SET resolved_at = NOW() WHERE subject_id = 1" );
        $wpdb->query( "UPDATE {$t} SET dismissed_at = NOW() WHERE subject_id = 2" );

        $row = ( new AlertDiagnostics() )->perDefinition()[ self::KEY ];

        $this->assertSame( 1, $row['open'] );
        $this->assertSame( 1, $row['resolved'] );
        $this->assertSame( 1, $row['dismissed'] );
    }

    /**
     * #3665 — the counts are the academy's, not the reader's.
     *
     * An admin read `open: 1` for a certificate alert, went looking for it
     * in their own list, and found nothing: the row was addressed to the
     * certificate's holder. The recipient count is what makes that legible
     * without naming anyone — five open rows spread over two people is a
     * different situation from one person drowning in five.
     */
    public function test_open_recipients_counts_people_not_rows(): void {
        $other = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->insertOpen( $this->user, 3 );
        $this->insertOpen( $other, 2 );

        $row = ( new AlertDiagnostics() )->perDefinition()[ self::KEY ];

        $this->assertSame( 5, $row['open'] );
        $this->assertSame( 2, $row['open_recipients'] );
    }

    /**
     * A resolved or dismissed row is nobody's open alert, so its recipient
     * must not be counted — otherwise the number would only ever grow and
     * would stop meaning "people who still have something to do".
     */
    public function test_open_recipients_ignores_cleared_rows(): void {
        $other = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->insertOpen( $this->user, 2 );
        $this->insertOpen( $other, 1 );

        global $wpdb;
        $t = $wpdb->prefix . 'tt_alert_occurrences';
        $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET resolved_at = NOW() WHERE recipient_user_id = %d", $other ) );

        $row = ( new AlertDiagnostics() )->perDefinition()[ self::KEY ];

        $this->assertSame( 2, $row['open'] );
        $this->assertSame( 1, $row['open_recipients'] );
    }

    /**
     * Counts, never names. Some of these alerts are personal — a staff
     * member's own certificate — and the settings screen is not the place
     * a colleague learns whose it is.
     */
    public function test_the_rest_route_exposes_the_count_without_naming_anyone(): void {
        $other = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->insertOpen( $this->user, 1 );
        $this->insertOpen( $other, 1 );

        do_action( 'rest_api_init' );
        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/alerts/diagnostics' )
        );
        $data = $response->get_data();
        $body = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;

        $keys = array_column( $body['definitions'], 'alert_key' );
        $row  = $body['definitions'][ (int) array_search( self::KEY, $keys, true ) ];

        $this->assertSame( 2, $row['open_recipients'] );
        $this->assertSame(
            [ 'alert_key', 'label', 'module', 'ms', 'open', 'open_recipients', 'resolved', 'dismissed', 'delivered', 'dismiss_rate', 'noisy' ],
            array_keys( $row ),
            'the route carries counts only — no recipient ids, no names'
        );
    }

    // ── the dismiss rate ───────────────────────────────────────────────

    /**
     * The rate is over what people actually acted on, not over every row
     * ever written. Counting untouched rows would drag a young definition's
     * rate toward zero and make a genuinely noisy one look fine for weeks.
     */
    public function test_the_rate_is_measured_over_acted_on_occurrences_only(): void {
        $this->seed( range( 1, 10 ) );

        global $wpdb;
        $t = $wpdb->prefix . 'tt_alert_occurrences';
        // Two acted on: one dismissed, one resolved. Eight untouched.
        $wpdb->query( "UPDATE {$t} SET dismissed_at = NOW() WHERE subject_id = 1" );
        $wpdb->query( "UPDATE {$t} SET resolved_at = NOW() WHERE subject_id = 2" );

        $row = ( new AlertDiagnostics() )->perDefinition()[ self::KEY ];

        $this->assertSame( 2, $row['delivered'] );
        $this->assertSame( 0.5, $row['dismiss_rate'] );
    }

    /**
     * Two dismissals out of two is 100% and means nothing. The floor is what
     * stops the flag firing on noise.
     */
    public function test_a_tiny_sample_is_never_flagged_as_noisy(): void {
        $this->seed( [ 1, 2 ] );

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences SET dismissed_at = NOW()" );

        $row = ( new AlertDiagnostics() )->perDefinition()[ self::KEY ];

        $this->assertSame( 1.0, $row['dismiss_rate'] );
        $this->assertFalse( $row['noisy'], 'two out of two is not evidence' );
    }

    public function test_a_mostly_dismissed_definition_is_flagged(): void {
        $this->seed( range( 1, 12 ) );

        global $wpdb;
        $t = $wpdb->prefix . 'tt_alert_occurrences';
        $wpdb->query( "UPDATE {$t} SET dismissed_at = NOW() WHERE subject_id <= 9" );
        $wpdb->query( "UPDATE {$t} SET resolved_at = NOW() WHERE subject_id > 9" );

        $row = ( new AlertDiagnostics() )->perDefinition()[ self::KEY ];

        $this->assertTrue( $row['noisy'] );
    }

    public function test_a_mostly_acted_on_definition_is_not_flagged(): void {
        $this->seed( range( 1, 12 ) );

        global $wpdb;
        $t = $wpdb->prefix . 'tt_alert_occurrences';
        $wpdb->query( "UPDATE {$t} SET resolved_at = NOW() WHERE subject_id <= 10" );
        $wpdb->query( "UPDATE {$t} SET dismissed_at = NOW() WHERE subject_id > 10" );

        $this->assertFalse( ( new AlertDiagnostics() )->perDefinition()[ self::KEY ]['noisy'] );
    }

    // ── REST ───────────────────────────────────────────────────────────

    public function test_the_rest_route_requires_the_settings_capability(): void {
        do_action( 'rest_api_init' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/alerts/diagnostics' )
        );

        $this->assertSame( 403, $response->get_status() );
    }

    public function test_the_rest_route_reports_staleness(): void {
        do_action( 'rest_api_init' );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/alerts/diagnostics' )
        );
        $data = $response->get_data();
        $body = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;

        $this->assertTrue( $body['stale'], 'a never-run engine must report stale over the API too' );
        $this->assertNull( $body['last_sweep_at'] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Open rows for one recipient, written straight to the table (#3665).
     *
     * The stub evaluator always addresses the current user, and the point
     * of these cases is two recipients, so the row is what gets seeded
     * rather than the sweep that would produce it.
     */
    private function insertOpen( int $recipient, int $count ): void {
        global $wpdb;
        $now = current_time( 'mysql' );

        for ( $i = 0; $i < $count; $i++ ) {
            $wpdb->insert( $wpdb->prefix . 'tt_alert_occurrences', [
                'uuid'              => wp_generate_uuid4(),
                'club_id'           => 1,
                'alert_key'         => self::KEY,
                'recipient_user_id' => $recipient,
                'subject_type'      => 'activity',
                'subject_id'        => $i + 1,
                'dedupe_key'        => self::KEY . '|' . $recipient . '|' . ( $i + 1 ),
                'severity'          => Severity::ATTENTION,
                'payload_json'      => wp_json_encode( [ 'title' => 'Diagnostic stub alert' ] ),
                'first_seen_at'     => $now,
                'last_seen_at'      => $now,
            ] );
        }
    }

    /** @param list<int> $subjectIds */
    private function seed( array $subjectIds ): void {
        ( new \TT\Modules\Alerts\AlertEvaluator() )->run( self::stub( $subjectIds ), new AlertContext( 1 ) );
    }

    /** @param list<int> $subjectIds */
    private static function stub( array $subjectIds = [ 1 ] ): AlertInterface {
        return new class( $subjectIds ) implements AlertInterface {
            /** @var list<int> */ private $ids;
            public function __construct( array $ids ) { $this->ids = $ids; }

            public function key(): string { return AlertDiagnosticsTest::KEY; }
            public function module(): string { return 'test'; }
            public function subjectType(): string { return 'activity'; }
            public function label(): string { return 'Diagnostic stub'; }
            public function description(): string { return 'Stub for diagnostics tests.'; }
            public function defaultSeverity(): string { return Severity::ATTENTION; }
            public function capRequired(): string { return ''; }
            public function defaultSurfaces(): array { return [ Surface::BADGE ]; }
            public function isOperational(): bool { return false; }

            public function evaluate( AlertContext $context ): array {
                $user = get_current_user_id() ?: 1;
                $out  = [];
                foreach ( $this->ids as $id ) {
                    $out[] = new AlertOccurrence(
                        AlertDiagnosticsTest::KEY,
                        $user,
                        'activity',
                        (int) $id,
                        Severity::ATTENTION,
                        [ 'title' => 'Diagnostic stub alert', 'url' => 'https://example.test/' ]
                    );
                }
                return $out;
            }
        };
    }
}
