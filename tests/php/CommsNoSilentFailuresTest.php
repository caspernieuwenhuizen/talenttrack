<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Comms\Channel\ChannelAdapterInterface;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Domain\CommsOutcomeSummary;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Template\TemplateInterface;
use TT\Modules\Comms\Template\TemplateRegistry;

/**
 * #2602 (epic #2600) — Comms must never fail silently.
 *
 * The module's audit trail is the evidence an academy relies on to answer
 * "was this player's family told?". A send that leaves no row is worse than
 * a send that failed loudly: it is indistinguishable from success.
 *
 * These tests pin the guard clauses that previously returned early with no
 * trace — a recipient list that resolved to nobody, and a template key that
 * isn't registered — plus the preflight contract that lets a surface warn
 * before the user commits (it must evaluate policy WITHOUT dispatching or
 * writing rows).
 *
 * Runs against the real schema (wp-env tests-cli), so the assertions are on
 * actual `tt_comms_log` rows rather than a spy.
 */
final class CommsNoSilentFailuresTest extends WP_UnitTestCase {

    private string $table;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_comms_log';

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();

        $wpdb->query( "DELETE FROM {$this->table}" );
    }

    public function tear_down(): void {
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    /** @return object[] */
    private function rows(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->table}" );
    }

    /**
     * `urgent: true` pins the quiet-hours outcome so these assertions do
     * not depend on the wall clock. Without it the suite passes by day and
     * fails after 21:00 — quiet hours default to 21:00-07:00, and CI runs
     * at whatever hour it happens to run. `quietHoursRequest()` covers the
     * deferred path deliberately instead.
     */
    private function request( array $recipients, string $templateKey = 'test_template' ): CommsRequest {
        return new CommsRequest( $templateKey, 'test_template', 1, 0, $recipients, [], null, true );
    }

    /** Same request, subject to quiet hours. */
    private function quietHoursRequest( array $recipients ): CommsRequest {
        return new CommsRequest( 'test_template', 'test_template', 1, 0, $recipients, [], null, false );
    }

    /** Force the quiet-hours window open or shut regardless of the hour. */
    private function setQuietHours( string $start, string $end ): void {
        \TT\Infrastructure\Query\QueryHelpers::set_config( 'comms_quiet_hours_start', $start );
        \TT\Infrastructure\Query\QueryHelpers::set_config( 'comms_quiet_hours_end', $end );
    }

    private function recipient( string $email = 'parent@example.test' ): Recipient {
        return Recipient::parent( 0, 42, $email );
    }

    // -- the two holes -----------------------------------------------------

    public function test_send_with_no_recipients_writes_an_audit_row(): void {
        TemplateRegistry::register( new SpyTemplate() );
        ChannelAdapterRegistry::register( new SpyAdapter() );

        $results = ( new CommsService() )->send( $this->request( [] ) );

        $this->assertCount( 1, $results );
        $this->assertSame( CommsResult::STATUS_NO_RECIPIENTS, $results[0]->status );

        $rows = $this->rows();
        $this->assertCount( 1, $rows, 'A send that resolved to nobody must still leave evidence.' );
        $this->assertSame( CommsResult::STATUS_NO_RECIPIENTS, $rows[0]->status );
    }

    public function test_unregistered_template_writes_one_audit_row_per_recipient(): void {
        // Nothing registered — the template key cannot resolve.
        $results = ( new CommsService() )->send(
            $this->request( [ $this->recipient( 'a@example.test' ), $this->recipient( 'b@example.test' ) ] )
        );

        $this->assertCount( 2, $results );
        foreach ( $results as $result ) {
            $this->assertSame( CommsResult::STATUS_FAILED, $result->status );
            $this->assertSame( 'unknown_template', $result->errorCode );
        }

        $rows = $this->rows();
        $this->assertCount( 2, $rows, 'A typo\'d template key must not be the one send that leaves no trace.' );
        $this->assertSame( 'unknown_template', $rows[0]->error_code );
    }

    // -- preflight ---------------------------------------------------------

    public function test_preflight_dispatches_nothing_and_writes_nothing(): void {
        TemplateRegistry::register( new SpyTemplate() );
        $adapter = new SpyAdapter();
        ChannelAdapterRegistry::register( $adapter );

        $results = ( new CommsService() )->preflight( $this->request( [ $this->recipient() ] ) );

        $this->assertCount( 1, $results );
        $this->assertSame( CommsResult::STATUS_QUEUED, $results[0]->status );
        $this->assertSame( 'spy', $results[0]->channelUsed );

        $this->assertSame( 0, $adapter->sendCalls, 'Preflight must not dispatch.' );
        $this->assertCount( 0, $this->rows(), 'Preflight must not write audit rows.' );
    }

    public function test_preflight_reports_an_unreachable_recipient(): void {
        TemplateRegistry::register( new SpyTemplate() );
        ChannelAdapterRegistry::register( new SpyAdapter() );

        // No email address — the spy adapter cannot reach them.
        $results = ( new CommsService() )->preflight( $this->request( [ $this->recipient( '' ) ] ) );

        $this->assertSame( CommsResult::STATUS_FAILED, $results[0]->status );
        $this->assertSame( 'no_channel_available', $results[0]->errorCode );
    }

    /**
     * Ordering guard. Delivery checks quiet hours before resolving a
     * channel; preflight must not, or a warning shown at 22:00 would say
     * "held until quiet hours end" and never mention the recipients who
     * have no contact details at all — which is the actionable half.
     */
    public function test_preflight_reports_unreachable_over_quiet_hours(): void {
        TemplateRegistry::register( new SpyTemplate() );
        ChannelAdapterRegistry::register( new SpyAdapter() );
        $this->setQuietHours( '00:00', '23:59' );  // always inside the window

        $results = ( new CommsService() )->preflight(
            $this->quietHoursRequest( [ $this->recipient( '' ) ] )
        );

        $this->assertSame(
            CommsResult::STATUS_FAILED,
            $results[0]->status,
            'An unreachable recipient must be reported even during quiet hours.'
        );
        $this->assertSame( 'no_channel_available', $results[0]->errorCode );
    }

    public function test_preflight_reports_quiet_hours_for_a_reachable_recipient(): void {
        TemplateRegistry::register( new SpyTemplate() );
        ChannelAdapterRegistry::register( new SpyAdapter() );
        $this->setQuietHours( '00:00', '23:59' );

        $results = ( new CommsService() )->preflight(
            $this->quietHoursRequest( [ $this->recipient() ] )
        );

        $this->assertSame( CommsResult::STATUS_QUIET_HOURS, $results[0]->status );
        $this->assertCount( 0, $this->rows(), 'Preflight must not write audit rows.' );
    }

    public function test_preflight_on_empty_recipients_reports_no_recipients(): void {
        TemplateRegistry::register( new SpyTemplate() );
        ChannelAdapterRegistry::register( new SpyAdapter() );

        $results = ( new CommsService() )->preflight( $this->request( [] ) );

        $this->assertCount( 1, $results );
        $this->assertSame( CommsResult::STATUS_NO_RECIPIENTS, $results[0]->status );
        $this->assertCount( 0, $this->rows() );
    }

    // -- the send path still works ----------------------------------------

    public function test_successful_send_still_dispatches_and_logs(): void {
        TemplateRegistry::register( new SpyTemplate() );
        $adapter = new SpyAdapter();
        ChannelAdapterRegistry::register( $adapter );

        $results = ( new CommsService() )->send( $this->request( [ $this->recipient() ] ) );

        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
        $this->assertSame( 1, $adapter->sendCalls );
        $this->assertCount( 1, $this->rows() );
    }

    // -- outcome summary ---------------------------------------------------

    public function test_summary_surfaces_skipped_and_failed_recipients(): void {
        $r = $this->recipient();
        $results = [
            new CommsResult( 'u1', CommsResult::STATUS_SENT, 'spy', $r ),
            new CommsResult( 'u2', CommsResult::STATUS_SENT, 'spy', $r ),
            new CommsResult( 'u3', CommsResult::STATUS_OPTED_OUT, '', $r ),
            new CommsResult( 'u4', CommsResult::STATUS_FAILED, '', $r, 'no_channel_available' ),
        ];

        $this->assertSame( 2, CommsOutcomeSummary::sentCount( $results ) );
        $this->assertTrue( CommsOutcomeSummary::hasProblems( $results ) );

        $lines = CommsOutcomeSummary::lines( $results );
        $this->assertCount( 3, $lines, 'Sent, opted-out and unreachable are three distinct things to say.' );

        $joined = implode( ' ', $lines );
        $this->assertStringContainsString( '2', $joined );
        $this->assertStringContainsString( 'opted out', $joined );
        $this->assertStringContainsString( 'contact details', $joined );
    }

    public function test_summary_reports_a_clean_send_without_problems(): void {
        $r = $this->recipient();
        $results = [ new CommsResult( 'u1', CommsResult::STATUS_SENT, 'spy', $r ) ];

        $this->assertFalse( CommsOutcomeSummary::hasProblems( $results ) );
        $this->assertCount( 1, CommsOutcomeSummary::lines( $results ) );
    }

    public function test_warnings_are_empty_when_every_recipient_would_be_sent_to(): void {
        $r = $this->recipient();
        $preflight = [ new CommsResult( '', CommsResult::STATUS_QUEUED, 'spy', $r ) ];

        $this->assertSame( [], CommsOutcomeSummary::warnings( $preflight ) );
    }

    public function test_warnings_flag_quiet_hours_before_the_user_commits(): void {
        $r = $this->recipient();
        $preflight = [
            new CommsResult( '', CommsResult::STATUS_QUEUED, 'spy', $r ),
            new CommsResult( '', CommsResult::STATUS_QUIET_HOURS, '', $r ),
        ];

        $warnings = CommsOutcomeSummary::warnings( $preflight );
        $this->assertCount( 1, $warnings, 'Only the problem recipients are worth interrupting for.' );
        $this->assertStringContainsString( 'quiet hours', $warnings[0] );
    }

    // -- result buckets ----------------------------------------------------

    public function test_skipped_is_distinct_from_failed(): void {
        $r = $this->recipient();

        $optedOut = new CommsResult( 'u1', CommsResult::STATUS_OPTED_OUT, '', $r );
        $this->assertTrue( $optedOut->isSkipped() );
        $this->assertFalse( $optedOut->isFailure() );

        $failed = new CommsResult( 'u2', CommsResult::STATUS_FAILED, '', $r, 'no_channel_available' );
        $this->assertTrue( $failed->isFailure() );
        $this->assertFalse( $failed->isSkipped() );

        $sent = new CommsResult( 'u3', CommsResult::STATUS_SENT, 'spy', $r );
        $this->assertTrue( $sent->isSuccess() );
        $this->assertFalse( $sent->isFailure() );
        $this->assertFalse( $sent->isSkipped() );
    }
}

/** Minimal template so the service has something to resolve. */
final class SpyTemplate implements TemplateInterface {
    public function key(): string { return 'test_template'; }
    public function label(): string { return 'Test template'; }
    public function supportedChannels(): array { return [ 'spy' ]; }
    public function isEditable(): bool { return false; }
    public function render( string $channelKey, CommsRequest $request, Recipient $recipient, string $locale ): array {
        return [ 'Subject', 'Body' ];
    }
}

/** Counts dispatches so preflight can be proven not to make any. */
final class SpyAdapter implements ChannelAdapterInterface {
    public int $sendCalls = 0;

    public function key(): string { return 'spy'; }
    public function canReach( Recipient $recipient ): bool { return $recipient->emailAddress !== ''; }
    public function send( CommsRequest $request, Recipient $recipient, string $uuid, string $renderedSubject, string $renderedBody ): CommsResult {
        $this->sendCalls++;
        return new CommsResult( $uuid, CommsResult::STATUS_SENT, 'spy', $recipient );
    }
}
