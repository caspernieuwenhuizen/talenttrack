<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Channel\ChannelAdapterInterface;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Modules\Comms\Queue\DeferredSendQueue;
use TT\Modules\Comms\Queue\DeferredSendSweep;
use TT\Modules\Comms\QuietHours\QuietHoursPolicy;
use TT\Modules\Comms\Template\TemplateInterface;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;

/**
 * #3646 — a message quiet hours hold is sent once they end.
 *
 * It used to be logged as `quiet_hours` ("Held until morning") and then
 * nothing ever sent it. These tests run the whole round trip on a fixed
 * clock in Europe/Amsterdam with the default 21:00-07:00 window: the hold,
 * the sweep that leaves it alone at 06:59 and sends it at 07:00, the same
 * log row moving to its final status with `attempt` 2, and the cases where
 * the night changed the answer (an opt-out, a template switched off, a
 * heartbeat that stopped for a day).
 *
 * Runs against the real schema, so the assertions are on actual
 * `tt_comms_log` and `tt_comms_deferred` rows.
 */
final class CommsQuietHoursDeferralTest extends WP_UnitTestCase {

    private const TYPE = 'test_deferral';

    private string $log;
    private string $queueTable;
    private int $now = 0;
    private string $savedTimezone = '';
    private QuietHoursPolicy $quiet;
    private DeferredSendQueue $queue;
    private DeferralSpyAdapter $adapter;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->log        = $wpdb->prefix . 'tt_comms_log';
        $this->queueTable = $wpdb->prefix . 'tt_comms_deferred';
        $wpdb->query( "DELETE FROM {$this->log}" );
        $wpdb->query( "DELETE FROM {$this->queueTable}" );

        $this->savedTimezone = (string) get_option( 'timezone_string' );
        update_option( 'timezone_string', 'Europe/Amsterdam' );

        // The default window, set explicitly: other suites move it and the
        // config service caches per request.
        QueryHelpers::set_config( 'comms_quiet_hours_start', '21:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '07:00' );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new DeferralSpyTemplate() );
        $this->adapter = new DeferralSpyAdapter();
        ChannelAdapterRegistry::register( $this->adapter );

        $clock       = function (): int { return $this->now; };
        $this->quiet = new QuietHoursPolicy( $clock );
        $this->queue = new DeferredSendQueue( $clock );
    }

    public function tear_down(): void {
        update_option( 'timezone_string', $this->savedTimezone );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    // -- the window --------------------------------------------------------

    public function test_the_window_edges_in_local_time(): void {
        $request = $this->request( $this->recipient() );

        $this->at( '2026-09-21 06:59' );
        $this->assertTrue( $this->quiet->shouldDefer( $request ), '06:59 is inside the window' );

        $this->at( '2026-09-21 07:00' );
        $this->assertFalse( $this->quiet->shouldDefer( $request ), '07:00 is the first minute after it' );

        $this->at( '2026-09-21 20:59' );
        $this->assertFalse( $this->quiet->shouldDefer( $request ), '20:59 is the last minute before it' );

        $this->at( '2026-09-21 21:00' );
        $this->assertTrue( $this->quiet->shouldDefer( $request ), '21:00 opens it' );
    }

    public function test_a_daytime_send_is_not_held(): void {
        $this->at( '2026-09-21 08:10' );

        $results = $this->service()->send( $this->request( $this->recipient() ) );

        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
        $this->assertSame( 0, $this->queued() );
        $this->assertSame( 1, (int) $this->onlyRow()->attempt );
    }

    // -- the round trip ----------------------------------------------------

    public function test_a_send_at_22_00_is_held_with_one_log_row_and_one_queue_row(): void {
        $this->at( '2026-09-21 22:00' );

        $results = $this->service()->send( $this->request( $this->recipient() ) );

        $this->assertSame( CommsResult::STATUS_QUIET_HOURS, $results[0]->status );
        $this->assertSame( 0, $this->adapter->sendCalls );

        $row = $this->onlyRow();
        $this->assertSame( CommsResult::STATUS_QUIET_HOURS, $row->status );
        $this->assertSame( 1, (int) $row->attempt );
        $this->assertSame( 1, $this->queued() );
    }

    public function test_the_sweep_waits_for_07_00_then_sends_against_the_same_row(): void {
        $this->at( '2026-09-21 22:00' );
        $results = $this->service()->send( $this->request( $this->recipient() ) );
        $uuid    = $results[0]->uuid;

        $this->at( '2026-09-22 06:59' );
        $this->sweep()->runForCurrentClub();
        $this->assertSame( 1, $this->queued(), 'Still quiet hours: the message waits.' );
        $this->assertSame( 0, $this->adapter->sendCalls );

        $this->at( '2026-09-22 07:00' );
        $counts = $this->sweep()->runForCurrentClub();

        $this->assertSame( 1, $counts['sent'] );
        $this->assertSame( 1, $this->adapter->sendCalls );
        $this->assertSame( 0, $this->queued(), 'A sent message leaves the queue.' );

        $row = $this->onlyRow();
        $this->assertSame( $uuid, $row->uuid, 'One message, one log row.' );
        $this->assertSame( CommsResult::STATUS_SENT, $row->status );
        $this->assertSame( 2, (int) $row->attempt );
        $this->assertSame( 'deferral_spy', $row->channel );
        $this->assertSame( hash( 'sha256', 'Body' ), $row->payload_hash );
    }

    public function test_the_message_log_route_shows_the_final_status_and_second_attempt(): void {
        $this->at( '2026-09-21 22:00' );
        $this->service()->send( $this->request( $this->recipient() ) );
        $this->at( '2026-09-22 07:05' );
        $this->sweep()->runForCurrentClub();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        do_action( 'rest_api_init' );
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/players/42/messages' ) );

        $this->assertSame( 200, $response->get_status() );
        $data     = $response->get_data();
        $data     = is_array( $data ) && isset( $data['data'] ) ? $data['data'] : $data;
        $messages = $data['messages'];
        $this->assertCount( 1, $messages );
        $this->assertSame( 'sent', $messages[0]['status'] );
        $this->assertSame( 2, $messages[0]['attempt'] );
    }

    // -- what the night can change ----------------------------------------

    public function test_an_opt_out_overnight_is_honoured(): void {
        $user = self::factory()->user->create();
        $this->at( '2026-09-21 22:00' );
        $this->service()->send( $this->request( $this->recipient( $user ) ) );

        ( new OptOutPolicy() )->setOptedOut( $user, self::TYPE, true );

        $this->at( '2026-09-22 07:10' );
        $this->sweep()->runForCurrentClub();

        $this->assertSame( 0, $this->adapter->sendCalls );
        $this->assertSame( 0, $this->queued() );
        $this->assertSame( CommsResult::STATUS_OPTED_OUT, $this->onlyRow()->status );
    }

    public function test_a_template_switched_off_overnight_is_honoured(): void {
        $this->at( '2026-09-21 22:00' );
        $this->service()->send( $this->request( $this->recipient() ) );

        TemplateSwitch::setDisabled( [ DeferralSpyTemplate::KEY ] );

        $this->at( '2026-09-22 07:10' );
        $this->sweep()->runForCurrentClub();

        $this->assertSame( 0, $this->adapter->sendCalls );
        $this->assertSame( 0, $this->queued() );
        $row = $this->onlyRow();
        $this->assertSame( CommsResult::STATUS_TEMPLATE_DISABLED, $row->status );
        $this->assertSame( 'template_disabled', $row->error_code );
    }

    public function test_a_held_message_past_its_expiry_is_marked_failed_not_sent(): void {
        $this->at( '2026-09-21 22:00' );
        $this->service()->send( $this->request( $this->recipient() ) );

        // A day and a minute later: the heartbeat stopped. Still inside
        // the window, so without the expiry the row would simply wait.
        $this->at( '2026-09-22 22:01' );
        $counts = $this->sweep()->runForCurrentClub();

        $this->assertSame( 1, $counts['expired'] );
        $this->assertSame( 0, $this->adapter->sendCalls );
        $this->assertSame( 0, $this->queued() );

        $row = $this->onlyRow();
        $this->assertSame( CommsResult::STATUS_FAILED, $row->status );
        $this->assertSame( DeferredSendQueue::ERROR_EXPIRED, $row->error_code );
        $this->assertSame( 1, (int) $row->attempt, 'Expiring is not an attempt to send.' );
    }

    // -- files are never held ---------------------------------------------

    public function test_a_scheduled_report_at_22_00_is_sent_immediately(): void {
        $this->at( '2026-09-21 22:00' );

        $request = new CommsRequest(
            DeferralSpyTemplate::KEY, MessageType::SCHEDULED_REPORT, 1, 0, [ $this->recipient() ],
            [], null, false, null, null, [ '/tmp/tt-report-test/report.csv' ]
        );
        $results = $this->service()->send( $request );

        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
        $this->assertSame( 1, $this->adapter->sendCalls );
        $this->assertSame( 0, $this->queued() );
    }

    public function test_a_held_request_with_an_attachment_is_refused_and_logged_as_failed(): void {
        $this->at( '2026-09-21 22:00' );

        $request = new CommsRequest(
            DeferralSpyTemplate::KEY, self::TYPE, 1, 0, [ $this->recipient() ],
            [], null, false, null, null, [ '/tmp/tt-report-test/report.csv' ]
        );
        $results = $this->service()->send( $request );

        $this->assertSame( CommsResult::STATUS_FAILED, $results[0]->status );
        $this->assertSame( DeferredSendQueue::ERROR_WITH_ATTACHMENT, $results[0]->errorCode );
        $this->assertSame( 0, $this->queued(), 'The queue never holds a file.' );
        $this->assertSame( 0, $this->adapter->sendCalls );

        $row = $this->onlyRow();
        $this->assertSame( CommsResult::STATUS_FAILED, $row->status );
        $this->assertSame( DeferredSendQueue::ERROR_WITH_ATTACHMENT, $row->error_code );
    }

    // -- fixtures ----------------------------------------------------------

    private function at( string $local ): void {
        $this->now = ( new \DateTimeImmutable( $local, new \DateTimeZone( 'Europe/Amsterdam' ) ) )->getTimestamp();
    }

    private function service(): CommsService {
        return new CommsService( null, $this->quiet, null, null, $this->queue );
    }

    private function sweep(): DeferredSendSweep {
        return new DeferredSendSweep( $this->queue, $this->service(), $this->quiet );
    }

    private function recipient( int $userId = 0 ): Recipient {
        return Recipient::parent( $userId, 42, 'parent@example.test' );
    }

    private function request( Recipient $recipient ): CommsRequest {
        return new CommsRequest( DeferralSpyTemplate::KEY, self::TYPE, 1, 0, [ $recipient ] );
    }

    private function queued(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->queueTable}" );
    }

    private function onlyRow(): object {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$this->log}" );
        $this->assertCount( 1, $rows, 'Exactly one log row per message.' );
        return $rows[0];
    }
}

final class DeferralSpyTemplate implements TemplateInterface {
    public const KEY = 'deferral_spy_template';

    public function key(): string { return self::KEY; }
    public function label(): string { return 'Deferral spy'; }
    public function supportedChannels(): array { return [ 'deferral_spy' ]; }
    public function isEditable(): bool { return false; }
    public function render( string $channelKey, CommsRequest $request, Recipient $recipient, string $locale ): array {
        return [ 'Subject', 'Body' ];
    }
}

final class DeferralSpyAdapter implements ChannelAdapterInterface {
    public int $sendCalls = 0;

    public function key(): string { return 'deferral_spy'; }
    public function canReach( Recipient $recipient ): bool { return $recipient->emailAddress !== ''; }
    public function send( CommsRequest $request, Recipient $recipient, string $uuid, string $renderedSubject, string $renderedBody ): CommsResult {
        $this->sendCalls++;
        return new CommsResult( $uuid, CommsResult::STATUS_SENT, 'deferral_spy', $recipient );
    }
}
